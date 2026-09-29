<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Application\Services\PowerClaimLease;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedControlUnavailableException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PowerOperationIndeterminateException;
use Lynomia\Modules\Dedicated\Domain\Services\DedicatedOperationGuard;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;

/**
 * Change what one physical machine is doing, through its out-of-band
 * controller.
 *
 * An action rather than controller code, so a support tool, a console command
 * and a queue worker reach the same refusals a customer does — and so that the
 * two rules below cannot be skipped by whichever caller comes next.
 *
 * ---------------------------------------------------------------------------
 * Why this is synchronous, when a VPS power change is a queued job
 * ---------------------------------------------------------------------------
 *
 * A hypervisor power change is one call in a fleet-wide API that the engine
 * already claims, retries and classifies. A BMC is a separate small computer
 * bolted to one chassis, reachable only from the management network, and the
 * request either lands on it or does not. There is nothing for a worker to
 * add, and a queued power-on is a customer waiting on a scheduler for an
 * operation that takes a second.
 *
 * What a worker WOULD add is retries, and this is the one operation that must
 * not have them — see below.
 *
 * ---------------------------------------------------------------------------
 * The two rules
 * ---------------------------------------------------------------------------
 *
 * **Never build a request out of the caller's words.** The action arrives as
 * an enum and is dispatched by `match` to a named method on the provider
 * interface. No verb, path or argument is ever assembled from a request value:
 * the IPMI adapter runs a process, and a string reaching that far is a
 * customer choosing what the platform executes on its management network.
 *
 * **A timeout is never retried.** A controller that refuses out loud has done
 * nothing, and the exception says so. A controller that stops answering may
 * have accepted the reset — the chassis may be going down as the response is
 * written — and the platform's only honest answer is "we do not know". It is
 * translated into {@see PowerOperationIndeterminateException} and nothing is
 * retried, nothing is rolled back, and no state is written from a guess.
 *
 * **One intent reaches the chassis once.** A caller's idempotency key becomes
 * a row in `dedicated_power_operations`, claimed before the controller is
 * called and settled after it answers. The unique index on that key is what
 * makes the claim atomic: two concurrent requests carrying one key race for
 * one insert, the loser blocks on the index until the winner's insert commits
 * and then reads the winner's row, and the controller is called once. The
 * claim is committed before the provider is called — not held open across it —
 * so that window is as short as a single insert rather than as long as a BMC
 * round trip. A replay after the first request settled is answered from the row —
 * including when the answer is a refusal or a timeout, because "we do not
 * know" repeated is still the truth and a second reset is not a way to find
 * out.
 *
 * A claim whose process died before the controller answered is the same
 * answer arrived at another way. It is bounded by {@see PowerClaimLease}:
 * inside the lease a repeat is refused as still in flight, and past it the
 * claim is settled as indeterminate — never released, so the unique index
 * stays the only gate and the key is answered from the row rather than
 * refused for ever.
 *
 * Two different keys are two intents and both execute: a customer who reboots
 * at nine and again at noon meant both, and this deliberately does not
 * deduplicate a verb forever.
 *
 * ---------------------------------------------------------------------------
 * Every failure leaves through this class in the platform's own words
 * ---------------------------------------------------------------------------
 *
 * The BMC layer's exceptions are written for an operator and carry what an
 * operator needs: the adapter name, the Redfish path, the controller's status
 * and prose, the endpoint id, the management address, the configuration key
 * the controller's password is read from. The API renderer publishes a domain
 * exception's message and its whole context to the caller, so letting one of
 * those escape an HTTP request hands a customer the address of a machine on
 * the management network — the one thing every resource in this module is
 * written to withhold.
 *
 * They are therefore translated here rather than at one call site, so the safe
 * behaviour is what a future caller gets by default:
 * {@see DedicatedControlUnavailableException} carries the customer's own
 * server id and the verb they sent, and the original travels as `previous` so
 * the log keeps everything.
 */
final readonly class ChangeDedicatedServerPower
{
    public function __construct(
        private DedicatedProviderFactory $providers,
        private DedicatedOperationGuard $guard,
        private PowerClaimLease $lease,
    ) {}

    /**
     * @param  string|null  $clientKey  the caller's own idempotency key, when the caller has one
     *
     * @throws DedicatedOperationRefusedException the machine is not in service, is being rebuilt, or the same key is still in flight
     * @throws DedicatedControlUnavailableException the controller refused, or cannot be reached at all
     * @throws PowerOperationIndeterminateException the controller stopped answering
     */
    public function execute(
        DedicatedServer $server,
        DedicatedPowerAction $action,
        ?string $clientKey = null,
        ?string $requestedByUserId = null,
    ): BmcOperation {
        $this->guard->assertInService($server);

        /*
         * A machine the platform is in the middle of erasing is not a machine
         * to reset.
         *
         * `assertInService` does not catch this one, because a reinstall
         * request deliberately does not move the server out of `active` — the
         * status change belongs to the handler that carries the install out.
         * So between "the customer asked for a rebuild" and "the installer is
         * running", the only thing standing between a `cycle` and a chassis
         * reset mid-erase is this line.
         */
        $this->guard->assertNoReinstallInFlight($server);

        $serverId = (string) $server->getKey();

        $endpoint = $server->preferredBmcEndpoint();

        if ($endpoint === null) {
            // A machine with no controller cannot be powered by anybody. A
            // platform fault rather than a customer one, and the 500 says so.
            throw DedicatedControlUnavailableException::machineNotReachable(
                $serverId,
                $action,
                BmcNotConfiguredException::noEndpoint($serverId),
            );
        }

        /*
         * The claim, before anything is sent.
         *
         * Placed after the guards and after the endpoint lookup — a request
         * the platform refuses outright has no business consuming a key — and
         * before the provider is resolved, because everything from here on can
         * reach the chassis.
         */
        $record = $clientKey === null ? null : $this->claim($server, $action, $clientKey, $requestedByUserId);

        if ($record !== null && $record->outcome->isSettled()) {
            return $this->replay($record, $server, $action);
        }

        try {
            $provider = $this->providers->for($endpoint);
        } catch (BmcNotConfiguredException $e) {
            /*
             * A row with no address, no configured credential, or a protocol
             * this build has no adapter for. All three are the platform's
             * problem, and all three name internals — the endpoint id, the
             * management address, the configuration key the BMC password is
             * read from — that must not travel to a customer in an error body.
             */
            throw DedicatedControlUnavailableException::machineNotReachable($serverId, $action, $e);
        }

        /*
         * The chassis is asked what it is doing before it is cycled, and the
         * read is deliberately OUTSIDE the block that translates a timeout.
         *
         * Reads never mutate — that is a rule of the provider contract — so a
         * read that timed out leaves nothing in doubt and is reported as the
         * plain gateway failure it is. Translating it would tell a customer
         * their server might be rebooting when nothing was ever sent.
         */
        try {
            $observed = $action === DedicatedPowerAction::Cycle
                ? $provider->powerState($endpoint)
                : null;
        } catch (DedicatedProviderException $e) {
            throw DedicatedControlUnavailableException::controllerRefused($serverId, $action, $e);
        }

        try {
            $operation = match ($action) {
                DedicatedPowerAction::On => $provider->powerOn($endpoint),

                // ACPI, not the power rail. The hard cut exists on the
                // provider interface and is deliberately not reachable from
                // here: it costs a customer whatever the host had not yet
                // flushed, and it is an operator's decision.
                DedicatedPowerAction::Off => $provider->gracefulShutdown($endpoint),

                /*
                 * "Make it boot", and the test is "is it definitely OFF" rather
                 * than "is it definitely ON".
                 *
                 * A machine reported as unknown or as mid-transition is reset,
                 * the same as one reported as on. The alternative reading —
                 * treating not-on as off and sending a power-on — does nothing
                 * at all to a machine that is already running, so the customer
                 * is told their server is rebooting and it never does.
                 */
                DedicatedPowerAction::Cycle => $observed === PowerState::Off
                    ? $provider->powerOn($endpoint)
                    : $provider->reset($endpoint),
            };
        } catch (DedicatedProviderException $e) {
            if ($e->isIndeterminate()) {
                /*
                 * The platform stopped waiting. Nothing is retried here and
                 * nothing may be retried above: a reset that timed out may be
                 * running, and a second one interrupts it. The power_state
                 * column is left exactly as it was, because the alternative is
                 * recording a state nobody observed.
                 */
                $indeterminate = PowerOperationIndeterminateException::after($serverId, $action, $e);

                $this->settle($record, PowerOperationOutcome::Indeterminate, failureCode: $indeterminate->errorCode());

                throw $indeterminate;
            }

            // The controller answered and declined. Nothing happened, and the
            // caller may send the request again — with a new key, because this
            // one now carries this answer.
            $refused = DedicatedControlUnavailableException::controllerRefused($serverId, $action, $e);

            $this->settle($record, PowerOperationOutcome::Refused, failureCode: $refused->errorCode());

            throw $refused;
        }

        /*
         * What the controller says it did, and only that.
         *
         * Two conditions, and the second is the one that costs something to
         * get wrong.
         *
         * An adapter that reports no resulting state has not observed one, and
         * the column is left alone rather than filled in from the verb.
         *
         * A GRACEFUL request is never written either, whatever the adapter
         * says it expects. `off` is an ACPI request to an operating system —
         * the Redfish adapter reports `Off` the moment the controller accepts
         * one, and the controller accepts it whether or not anything is
         * listening. A host with no acpid, a hung kernel or a dialog box open
         * stays up for hours; writing `off` there would leave the customer
         * looking at `is_powered_on: false` under a machine that is still
         * serving traffic, and it is the platform's own record that would be
         * wrong rather than a display. What the machine actually did is
         * observed by discovery, which is the only thing here that looks.
         *
         * The 202 already says the right thing on its own: the instruction was
         * accepted, and the chassis takes its own time.
         */
        if ($operation->resultingPowerState !== null && ! $action->isGraceful()) {
            $server->forceFill(['power_state' => $operation->resultingPowerState])->save();
        }

        $this->settle($record, PowerOperationOutcome::Accepted, $operation);

        return $operation;
    }

    /**
     * Claim this intent, or hand back the row that already owns it.
     *
     * The insert is the lock. A read-then-write would let two concurrent
     * requests both find nothing and both call the controller, which is the
     * defect this table exists to close; the unique index means one of them
     * loses at the database and reads the winner's row instead.
     *
     * The row it reads may be a claim whose process died before the
     * controller answered — a row identical to a winner still waiting on a
     * slow BMC. Nothing in the row can tell them apart; the lease is sized so
     * that no honest request outlives it (config/dedicated.php argues the
     * arithmetic), so inside it the claim is treated as in flight, and past
     * it the claim is settled as indeterminate here, by the same conditional
     * write the sweep uses, and answered from the row. It is never deleted
     * and never claimed again, so this request does not reach the chassis
     * either way.
     *
     * @throws DedicatedOperationRefusedException the winning request has not settled yet and its lease has not lapsed
     */
    private function claim(
        DedicatedServer $server,
        DedicatedPowerAction $action,
        string $clientKey,
        ?string $requestedByUserId,
    ): DedicatedPowerOperation {
        $key = DedicatedIdempotencyKey::for($server, 'power:'.$action->value, $clientKey);

        try {
            /*
             * Wrapped in its own transaction, which is not decoration on
             * PostgreSQL: a constraint violation aborts the transaction it
             * happens in, and every statement after it fails with "current
             * transaction is aborted" until a rollback. The wrapper gives the
             * failed insert a savepoint to roll back to, so the read below
             * runs on a healthy connection — the same reason
             * `ProvisionOrderedService` wraps the service insert it races on.
             */
            /** @var DedicatedPowerOperation $claimed */
            $claimed = DB::transaction(fn (): DedicatedPowerOperation => DedicatedPowerOperation::query()->create([
                'dedicated_server_id' => $server->getKey(),
                'customer_id' => $server->customer_id,
                'requested_by_user_id' => $requestedByUserId,
                'action' => $action,
                'idempotency_key' => $key,
                'outcome' => PowerOperationOutcome::Claimed,
                'requested_at' => now(),
            ]));

            return $claimed;
        } catch (UniqueConstraintViolationException) {
            /** @var DedicatedPowerOperation $existing */
            $existing = DedicatedPowerOperation::query()->where('idempotency_key', $key)->firstOrFail();

            if ($this->lease->hasLapsed($existing)) {
                // Whether this call or another writer settled it, the row now
                // says what the key answers; the return value is not needed.
                $this->lease->settleAsAbandoned($existing);
            }

            if (! $existing->outcome->isSettled()) {
                throw DedicatedOperationRefusedException::becauseTheSameRequestIsStillInFlight(
                    (string) $server->getKey(),
                    $action,
                );
            }

            return $existing;
        }
    }

    /**
     * Answer a repeat from what the first request found out.
     *
     * The chassis is not touched. A refusal and a timeout are re-raised as the
     * same exceptions the first caller saw, because an idempotency key
     * promises the same answer and not a second attempt — and for a timeout
     * that promise is the safety property: the machine may be mid-reset.
     *
     * @throws DedicatedControlUnavailableException
     * @throws PowerOperationIndeterminateException
     */
    private function replay(
        DedicatedPowerOperation $record,
        DedicatedServer $server,
        DedicatedPowerAction $action,
    ): BmcOperation {
        $serverId = (string) $server->getKey();

        return match ($record->outcome) {
            PowerOperationOutcome::Accepted => new BmcOperation(
                operation: (string) $record->provider_operation,
                endpointId: (string) $record->bmc_endpoint_id,
                protocol: $record->bmc_protocol ?? BmcProtocol::Redfish,
                accepted: (bool) $record->accepted,
                taskId: $record->provider_task_id,
                resultingPowerState: $record->resulting_power_state,
            ),
            /*
             * Re-raised with no `previous`. There is no new provider failure
             * to carry — nothing was sent — and inventing one would put a
             * fabricated controller response in the log next to the real one
             * the first request recorded.
             */
            PowerOperationOutcome::Refused => throw DedicatedControlUnavailableException::controllerRefused(
                $serverId,
                $action,
            ),
            PowerOperationOutcome::Indeterminate => throw PowerOperationIndeterminateException::after(
                $serverId,
                $action,
            ),
            // Unreachable: the caller checked isSettled() first. Kept so that
            // a fifth outcome added later fails here rather than silently
            // returning an accepted operation nobody recorded.
            PowerOperationOutcome::Claimed => throw DedicatedOperationRefusedException::becauseTheSameRequestIsStillInFlight(
                $serverId,
                $action,
            ),
        };
    }

    /**
     * Record what the controller said.
     *
     * Unconditional, unlike the lease's write, and on purpose: this is the
     * owning process with a real answer, and a real answer beats the "we do
     * not know" the lease may have written while this request was still
     * waiting on a slow controller. See {@see PowerClaimLease}.
     */
    private function settle(
        ?DedicatedPowerOperation $record,
        PowerOperationOutcome $outcome,
        ?BmcOperation $operation = null,
        ?string $failureCode = null,
    ): void {
        $record?->forceFill([
            'outcome' => $outcome,
            'accepted' => $operation?->accepted,
            'resulting_power_state' => $operation?->resultingPowerState,
            'provider_operation' => $operation?->operation,
            'bmc_endpoint_id' => $operation?->endpointId,
            'bmc_protocol' => $operation?->protocol,
            'provider_task_id' => $operation?->taskId,
            'failure_code' => $failureCode,
            'settled_at' => now(),
        ])->save();
    }
}
