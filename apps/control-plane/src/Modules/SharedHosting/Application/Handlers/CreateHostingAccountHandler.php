<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Handlers;

use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Application\Actions\ReserveHostingNodeCapacity;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingPlacementRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeUnlicensedException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingUsernameConflictException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NodeAtCapacityException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NoHostingCapacityException;
use Lynomia\Modules\SharedHosting\Domain\Services\HostingNodeScheduler;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * Opens a shared hosting account: place it, take the slot, create it, record it.
 *
 * The order is the design. Everything that can fail cheaply happens before
 * anything that cannot be undone:
 *
 *  1. **Place** — choosing a node is pure computation and costs nothing to redo.
 *  2. **Take the slot** — a row lock, an increment and a pending account row in
 *     one transaction. Cheap, and released by compensation.
 *  3. **Create the account** — the first irreversible step, and the only one.
 *  4. **Record the outcome** — bookkeeping that follows a resource that already
 *     exists.
 *
 * Doing step 3 before step 2 would mean an account on a node whose slot count
 * does not know about it: the scheduler keeps placing onto that node, the disk
 * fills faster than the platform believes, and the account itself is invisible
 * to billing and to the termination path.
 *
 * Every failure is classified, because the engine's retry and compensation
 * behaviour depends entirely on that classification — and getting it wrong is
 * how a customer ends up with two accounts, or with none and no refund.
 */
final readonly class CreateHostingAccountHandler implements ProvisioningHandler
{
    public function __construct(
        private HostingNodeScheduler $scheduler,
        private ReserveHostingNodeCapacity $reserveCapacity,
        /*
         * Resolved per node rather than injected as "the" provider. The panel
         * is a property of the node the account lands on, and a platform
         * running both cPanel and DirectAdmin — which every platform of any age
         * does — has no single correct default.
         */
        private HostingProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::CreateHostingAccount;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $packageId = (string) ($payload['hosting_package_id'] ?? '');
        $package = HostingPackage::query()->find($packageId);

        if ($package === null) {
            /*
             * Permanent, and the only permanent class in this handler. A job
             * naming a package that does not exist will name the same
             * non-existent package on every retry; retrying it wastes a worker
             * and delays the operator finding out.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'hosting.unknown_package',
                sprintf('No hosting package exists with the id "%s".', $packageId),
                metadata: ['hosting_package_id' => $packageId],
            );
        }

        try {
            $decision = $this->scheduler->place(new HostingPlacementRequest(
                packageId: (string) $package->getKey(),
                regionId: isset($payload['region_id']) ? (string) $payload['region_id'] : null,
                panel: isset($payload['panel']) ? HostingPanel::tryFrom((string) $payload['panel']) : null,
                customerId: $job->customer_id,
                excludedNodeIds: array_values(array_filter(
                    (array) ($payload['excluded_node_ids'] ?? []),
                    is_string(...),
                )),
            ));
        } catch (NoHostingCapacityException $e) {
            /*
             * Capacity, not permanent. A full fleet is a condition that
             * resolves — accounts are terminated, an operator adds a node, a
             * disk is grown — so the job waits and retries rather than
             * refunding a customer who would happily have waited an hour.
             */
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $node = $decision->node();

        $username = $this->username($payload, $job, $node->panel);
        $primaryDomain = (string) ($payload['primary_domain'] ?? $username.'.hosting.invalid');

        try {
            $reservation = $this->reserveCapacity->reserve(
                node: $node,
                username: $username,
                primaryDomain: $primaryDomain,
                customerId: (string) $job->customer_id,
                package: $package,
                serviceId: $job->service_id,
            );

            $account = $reservation->account;
        } catch (NodeAtCapacityException $e) {
            // The node moved between placement and reservation. Capacity: the
            // next attempt scores the fleet again and lands somewhere else.
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        } catch (HostingUsernameConflictException $e) {
            /*
             * Permanent, and deliberately not Capacity. The name is taken on
             * this node by another customer and every retry of this job asks
             * for the same name; retrying only delays an operator finding out.
             * Nothing was created and nothing was reserved — the refusal is
             * thrown before the slot is taken — so there is nothing to
             * compensate.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        } catch (HostingNodeUnlicensedException $e) {
            /*
             * A lapsed licence is PERMANENT for this node and retryable for the
             * job, and Capacity is the class that expresses exactly that pair.
             *
             * The reasoning is worth spelling out because the obvious choice is
             * wrong. Permanent would be true of the node — no retry against
             * this machine can succeed, since cPanel and DirectAdmin are
             * commercial products and an unlicensed panel does not serve — but
             * it is false of the JOB, and Permanent is a statement about the
             * job. It fails the order outright and refunds a customer whom
             * twenty other licensed nodes could have served in seconds.
             *
             * Transient would retry, but it says "the provider was momentarily
             * unavailable", which is a lie that hides a licensing problem
             * behind an outage and delays the one action that fixes it.
             *
             * Capacity says: nothing was built, this node cannot serve you, and
             * retrying needs time. The retry re-runs the scheduler, which
             * excludes unlicensed nodes, so the job lands on a node that can
             * serve it — while the rejection tally on the exhaustion path names
             * "unlicensed" so an operator is told to renew rather than to wait.
             */
            return ProvisioningResult::failed(
                FailureClass::Capacity,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        try {
            $result = $this->providers->for($node)->createAccount($node, new CreateAccountRequest(
                username: $account->username,
                primaryDomain: $account->primary_domain,
                password: (string) ($payload['password'] ?? ''),
                packageName: $package->panel_package_name,
                contactEmail: (string) ($payload['contact_email'] ?? ''),
                dedicatedIp: (bool) ($payload['dedicated_ip'] ?? false),
                locale: isset($payload['locale']) ? (string) $payload['locale'] : null,
            ));
        } catch (HostingProviderException $e) {
            /*
             * The adapter's own verdict on whether the request may still be in
             * flight decides the class, and it is the only thing that can know:
             * it saw the transport. A refusal the panel spoke out loud —
             * metadata.result=0, error=1 — is transient, because nothing was
             * created and the next attempt may find the node less busy. A
             * request the platform stopped waiting for is a TIMEOUT, which the
             * engine neither retries nor compensates: WHM builds a home
             * directory, a mail store, a database user and a DNS zone before it
             * answers, so the account may exist, and retrying would try to
             * create it a second time.
             */
            $indeterminate = $e->isIndeterminate();

            if (! $indeterminate && $reservation->slotTakenNow) {
                /*
                 * The panel answered, and this attempt is the one that took
                 * the slot — so nothing has ever been created under this name
                 * and the node is genuinely one account emptier than its count
                 * says. The row is marked failed and the slot handed back in
                 * one transaction.
                 *
                 * The release cannot be left to the engine's compensation. The
                 * only ResourceReservationReleaser the application binds is
                 * IPAM's, which knows about addresses and nothing about
                 * hosting_nodes.account_count; a slot not released here is
                 * never released at all, and a node quietly stops accepting
                 * accounts long before its disk is full. Nodes lose capacity
                 * fastest exactly when creates are failing, which is when the
                 * fleet can least afford it.
                 *
                 * The row itself is kept rather than deleted, so an operator
                 * can still see what was attempted and where, and so a retry
                 * finds one identity for the account rather than making a
                 * second.
                 */
                $this->reserveCapacity->releaseFor($account, HostingAccountStatus::Failed);
            }

            /*
             * Two cases deliberately leave both the row and the slot alone:
             *
             *  - an INDETERMINATE answer. The platform stopped waiting and the
             *    account may exist; releasing its slot would put a second
             *    customer's files onto space the first one is already using;
             *
             *  - a refusal against a reservation THIS attempt did not take.
             *    The row was left by an earlier attempt that already reached
             *    the panel — a create that timed out, or one that succeeded —
             *    so "an account with this name already exists" is not evidence
             *    that the node is holding nothing. Marking such a row failed
             *    would also throw away the platform's record of a live
             *    account: it would stop occupying capacity, stop being billed
             *    and never be terminated, while it carried on serving.
             */

            return ProvisioningResult::failed(
                $indeterminate ? FailureClass::Timeout : FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                // The account name IS the provider reference for shared
                // hosting: there is no numeric id. Carried even on failure,
                // because for a timeout it is the single most valuable fact in
                // the module — the name under which the account may exist.
                providerReference: $account->username,
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $account->forceFill([
            'status' => HostingAccountStatus::Active,
            // The panel's own account name wins over the requested one: a panel
            // that shortened it has just made every later call address an
            // account that does not exist.
            'username' => $result->username,
        ])->save();

        return ProvisioningResult::succeeded(
            // Shared hosting has no asynchronous task handle: the panel does
            // the work before it answers. The account name is the only handle
            // that exists, so it stands as both.
            remoteJobId: $result->username,
            providerReference: $result->username,
            metadata: [
                'node' => $node->hostname,
                'panel' => $node->panel->value,
                'placement_score' => $decision->score(),
                'hosting_account_id' => $account->getKey(),
                'primary_domain' => $result->primaryDomain,
                'ip_address' => $result->ipAddress,
            ],
        );
    }

    /**
     * The panel account name for this job.
     *
     * Truncated to the panel's own limit here rather than left to the adapter,
     * because cPanel silently shortens a name it considers too long — and two
     * customers whose names shorten to the same string would be one account.
     *
     * @param  array<string, mixed>  $payload
     */
    private function username(array $payload, ProvisioningJob $job, HostingPanel $panel): string
    {
        $requested = trim((string) ($payload['username'] ?? ''));

        if ($requested === '') {
            // Derived from the job rather than random, so that a retry of the
            // same job produces the same name and collides with its own
            // earlier attempt instead of creating a second account.
            $requested = 'lyn'.strtolower(substr((string) $job->getKey(), -8));
        }

        return substr($requested, 0, $panel->maxUsernameLength());
    }
}
