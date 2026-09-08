<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedControlUnavailableException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PowerOperationIndeterminateException;
use Lynomia\Modules\Dedicated\Domain\Services\DedicatedOperationGuard;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
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
    ) {}

    /**
     * @throws DedicatedOperationRefusedException the machine is not in service, or is being rebuilt
     * @throws DedicatedControlUnavailableException the controller refused, or cannot be reached at all
     * @throws PowerOperationIndeterminateException the controller stopped answering
     */
    public function execute(DedicatedServer $server, DedicatedPowerAction $action): BmcOperation
    {
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
                throw PowerOperationIndeterminateException::after(
                    $serverId,
                    $action,
                    $e,
                );
            }

            // The controller answered and declined. Nothing happened, and the
            // caller may send the request again — which is all the customer
            // needs to know, and all they are told.
            throw DedicatedControlUnavailableException::controllerRefused($serverId, $action, $e);
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

        return $operation;
    }
}
