<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * The platform could not carry out a power request, and the customer is told
 * only that.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists at all
 * ---------------------------------------------------------------------------
 *
 * {@see DedicatedProviderException}, {@see BmcNotConfiguredException} and
 * {@see UnknownBmcProtocolException} are excellent exceptions — for an
 * operator. They carry the adapter's name, the operation verb, the Redfish
 * path, the controller's HTTP status, the controller's own prose, the BMC
 * endpoint id, the management address and the name of the configuration key the
 * BMC password is read from. Every one of those is deliberate, because until
 * now those exceptions were raised only inside a queue worker, an inventory
 * sync or a console command, where the audience is somebody holding a runbook.
 *
 * The API renderer publishes a DomainException's message and its whole context
 * as `error.message` and `error.details`. So the moment a customer-facing HTTP
 * endpoint let one of them escape, all of it became a response body — a BMC
 * address on the management network and the key its credential is read from,
 * handed to whoever asked a server to power on. That is the exact list of
 * fields the module's server resource is written to keep out of a document —
 * deliberately not referenced by class here, because the domain layer does not
 * import the HTTP layer even for a docblock — and it must not arrive through
 * the error path instead.
 *
 * So the customer surface translates. The original travels as `previous`, so
 * the log, the report and any operator reading it keep every detail; the
 * response carries the machine's own id, the verb the caller sent, and nothing
 * else.
 *
 * ---------------------------------------------------------------------------
 * Two statuses, one code
 * ---------------------------------------------------------------------------
 *
 * A client's only sensible reaction is the same either way — the request did
 * not happen, look at the server, ask again — so there is one code to branch
 * on. The status separates whose fault it is: 502 when a controller answered
 * and refused, 500 when the platform has no usable way to reach the machine at
 * all, which is a machine that was sold without a working out-of-band path and
 * is the platform's problem rather than the customer's.
 *
 * Neither is {@see PowerOperationIndeterminateException}, and that separation
 * is the one that matters most: both cases here mean *nothing happened*, and
 * the caller may ask again. A timeout means nobody knows, and is never retried.
 */
final class DedicatedControlUnavailableException extends DomainException
{
    private int $status = 502;

    /**
     * A controller answered and would not do it.
     *
     * Nothing happened — a refusal spoken out loud settles that — so the
     * caller may send the request again.
     */
    public static function controllerRefused(
        string $serverId,
        DedicatedPowerAction $action,
        ?Throwable $previous = null,
    ): self {
        $exception = new self(
            'This server\'s management controller did not carry out the power request. '
            .'Nothing was changed on the machine; try again, and contact support if it keeps failing.',
            previous: $previous,
        );

        return $exception->describe($serverId, $action, 502);
    }

    /**
     * The platform has no usable out-of-band path to this machine.
     *
     * No endpoint row, no address, no configured credential, or a protocol
     * this build has no adapter for. All four are a machine that cannot be
     * operated by anybody — an operator included — and none of them is
     * something the customer can act on, so the message says so plainly and
     * says nothing about which of the four it was.
     */
    public static function machineNotReachable(
        string $serverId,
        DedicatedPowerAction $action,
        ?Throwable $previous = null,
    ): self {
        $exception = new self(
            'This server cannot currently be operated remotely: the platform has no working management path '
            .'to the machine. Nothing was changed. Support has been notified by the failure itself.',
            previous: $previous,
        );

        return $exception->describe($serverId, $action, 500);
    }

    public function errorCode(): string
    {
        return 'dedicated.server_control_unavailable';
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /**
     * The whole published context: the caller's own machine and the verb they
     * sent. Both were already in their hands before the request was made.
     */
    private function describe(string $serverId, DedicatedPowerAction $action, int $status): self
    {
        $this->status = $status;

        return $this->withContext([
            'dedicated_server_id' => $serverId,
            'action' => $action->value,
            // Stated, because the distinction a client must not get wrong is
            // this one against a 504: here the platform knows nothing was
            // done.
            'safe_to_retry' => true,
        ]);
    }
}
