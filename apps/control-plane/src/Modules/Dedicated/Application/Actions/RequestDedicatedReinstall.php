<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\ReinstallConfirmationMismatchException;
use Lynomia\Modules\Dedicated\Domain\Services\DedicatedOperationGuard;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Ask the platform to erase a physical machine and lay an operating system
 * down on it again.
 *
 * This is the most destructive thing on the customer surface, and the only one
 * whose effect cannot be undone by anybody at any price: the disks that held
 * the customer's data are the disks the installer partitions. Every gate is
 * therefore here rather than in a controller, so that a support tool or an
 * operator script reaching this action gets the same refusals a customer does.
 *
 * ---------------------------------------------------------------------------
 * Why this records work rather than doing it
 * ---------------------------------------------------------------------------
 *
 * Nothing here touches a controller, arms a boot override or changes the
 * machine's status, and that is deliberate. A reinstall is a sequence that
 * runs for tens of minutes — authorise a one-time PXE boot, reset the chassis,
 * watch the installer, commit the addresses, mark the machine active — and the
 * platform already owns that sequence in the provisioning engine, which is
 * what claims the work, counts the attempts, classifies the failures and, most
 * importantly, refuses to retry one that timed out. An HTTP request cannot
 * hold any of that: a customer closing a laptop mid-install would be a machine
 * half-erased with nobody watching.
 *
 * So this action does the part that must happen while the caller is still on
 * the line — prove they meant it, prove the machine is theirs and idle, and
 * write one row that says what was asked for — and hands the rest to the
 * engine.
 *
 * The work itself is {@see ReinstallDedicatedHandler}, which the engine reaches
 * through the registry. This action deliberately does not call
 * {@see AuthorisePxeBoot} itself: arming a boot override inside an HTTP
 * request would leave a machine primed to erase itself if the response were
 * lost, with no job row to say why. The path from a running customer server to
 * a rebuilt one now exists and is explicit — the machine moves to
 * `reinstalling`, which is the one state other than `provisioning` where a
 * network install is legal, and it is reached only by way of the typed
 * confirmation above.
 *
 * ---------------------------------------------------------------------------
 * The order of the four steps is the design
 * ---------------------------------------------------------------------------
 *
 *  1. **Prove the caller meant this machine.** Before anything is looked up
 *     or written.
 *  2. **Replay.** A repeated idempotency key returns the job that already
 *     exists, before any guard runs — otherwise a client retrying after a
 *     dropped response would be told its own in-flight job is a conflict,
 *     which is a 409 for doing exactly what a retry is supposed to do.
 *  3. **Then the guards**, so a machine in maintenance is refused rather than
 *     queued.
 *  4. **Then the insert**, which is where idempotency is actually decided — by
 *     a unique constraint, in one statement, not by the read in step 2 — and
 *     dispatch only what was inserted. Dispatching for a job that already
 *     existed is how a job that is mid-flight acquires a second worker.
 */
final readonly class RequestDedicatedReinstall
{
    /** The operation half of the engine's idempotency key. */
    private const string OPERATION = 'reinstall';

    public function __construct(
        private CreateProvisioningJob $createJob,
        private DedicatedOperationGuard $guard,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @param  string  $confirmation  must equal the machine's serial number
     *
     * @throws ReinstallConfirmationMismatchException
     * @throws DedicatedOperationRefusedException
     */
    public function execute(
        DedicatedServer $server,
        string $confirmation,
        string $idempotencyKey,
        ?OsInstallProfile $profile = null,
        ?string $requestedByUserId = null,
    ): ProvisioningJob {
        /*
         * Compared first, and compared exactly — no trimming of internal
         * whitespace, no case folding. The serial is not being looked up; it
         * is being used as proof that a person read the screen, and a
         * comparison that forgives near-misses forgives a script that guessed.
         *
         * hash_equals rather than ===, so the comparison does not return early
         * on the first differing byte. A serial is not a secret, but a
         * confirmation that leaks its own prefix by timing is a confirmation a
         * client can solve one character at a time.
         */
        if (! hash_equals($server->serial, $confirmation)) {
            throw ReinstallConfirmationMismatchException::make();
        }

        $key = DedicatedIdempotencyKey::for($server, self::OPERATION, $idempotencyKey);

        $replay = ProvisioningJob::query()->where('idempotency_key', $key)->first();

        if ($replay !== null) {
            return $replay;
        }

        $this->guard->assertInService($server);
        $this->guard->assertNothingInFlight($server);

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: ProvisioningJobKind::ReinstallDedicated,
            idempotencyKey: $key,
            /*
             * The protocol the machine's own best controller speaks, read from
             * its endpoint rows rather than from a fleet default. A fleet
             * migrating off IPMI one machine at a time has both, and the job
             * has to record which one this machine will actually be reached
             * over.
             */
            provider: $server->preferredBmcEndpoint()?->protocol->value ?? 'unknown',
            serviceId: $server->service_id,
            customerId: $server->customer_id,
            requestedByUserId: $requestedByUserId,
            payload: [
                'dedicated_server_id' => (string) $server->getKey(),
                // The stable name of the machine, so the job record still says
                // which box was erased after the row has been reassigned.
                'serial' => $server->serial,
                'os_install_profile_id' => $profile?->getKey(),
                'os_install_profile_slug' => $profile?->slug,
                /*
                 * A PXE authorisation is not written without a reason — the
                 * column is not nullable and neither is the decision. This is
                 * the reason, composed here where it is still true, rather
                 * than invented by a handler months later.
                 */
                'install_reason' => sprintf('Customer-requested reinstall of server %s.', $server->serial),
            ],
            /*
             * One attempt, deliberately, against the engine's default of
             * three. Every other kind of job is safe to retry because it
             * either built nothing or built the thing it was asked for; a
             * reinstall that failed halfway has already erased the disks, and
             * a second automatic pass erases whatever the first one managed to
             * lay down. A failed reinstall is a person's decision.
             */
            maxAttempts: 1,
        ));

        /*
         * The operation record is written before the job is dispatched. A
         * customer who has just typed a serial number to confirm the most
         * destructive act available to them must see something happening, and
         * a record created by the worker would appear only once a worker
         * picked the job up. It also means a job that never reaches a worker
         * still leaves evidence that a rebuild was asked for.
         */
        $operation = DedicatedReinstall::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'service_id' => $server->service_id,
            'customer_id' => $server->customer_id,
            'provisioning_job_id' => $job->getKey(),
            'state' => DedicatedReinstallState::Requested,
            'state_changed_at' => now(),
            'os_install_profile_id' => $profile?->getKey(),
            'os_install_profile_slug' => $profile?->slug,
            'bmc_endpoint_id' => $server->preferredBmcEndpoint()?->getKey(),
            'bmc_protocol' => $server->preferredBmcEndpoint()?->protocol->value,
        ]);

        // The same record the virtual path keeps, for the same reason: this is
        // the moment a person asked for a machine's disks to be erased.
        $this->audit->execute(
            action: AuditAction::DedicatedReinstallRequested,
            subject: $server,
            customerId: $server->customer_id,
            context: [
                'dedicated_server_id' => (string) $server->getKey(),
                'serial' => $server->serial,
                'provisioning_job_id' => (string) $job->getKey(),
                'dedicated_reinstall_id' => (string) $operation->getKey(),
                'os_install_profile_slug' => $profile?->slug,
            ],
        );

        if ($job->wasRecentlyCreated) {
            $operation->advanceTo(DedicatedReinstallState::Queued);

            RunProvisioningJob::dispatch((string) $job->getKey());
        }

        return $job;
    }
}
