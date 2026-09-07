<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * The platform stopped waiting for the controller, and does not know what the
 * machine did.
 *
 * This is a separate exception from {@see DedicatedProviderException} because
 * it is a separate fact, and collapsing the two is the mistake the whole
 * module is arranged to avoid. A controller that refused out loud means
 * nothing happened and the request may be sent again. A controller that stopped
 * answering means the reset may be running right now — on physical hardware,
 * possibly into a network install — and the correct behaviour for every
 * caller, including the customer's own client library, is to go and look
 * rather than to try again.
 *
 * A 504 rather than the 502 a plain provider failure carries, for exactly that
 * reason: "the upstream failed" invites a retry, and "the platform timed out"
 * is the status a sensible client treats as unknown. Nothing in the HTTP path
 * retries, and nothing releases or rolls anything back — a timeout is not a
 * failure to be compensated.
 */
final class PowerOperationIndeterminateException extends DomainException
{
    public static function after(
        string $serverId,
        DedicatedPowerAction $action,
        ?Throwable $previous = null,
    ): self {
        $exception = new self(
            'The platform stopped waiting for this server\'s management controller before it confirmed the power request. '
            .'The operation may still have been carried out; check the server before asking again.',
            previous: $previous,
        );

        return $exception->withContext([
            'dedicated_server_id' => $serverId,
            'action' => $action->value,
            // Stated rather than implied. A client reading this branch must
            // not treat it as a failure, and support reading the log must not
            // either.
            'indeterminate' => true,
            'safe_to_retry' => false,
        ]);
    }

    public function errorCode(): string
    {
        return 'dedicated.power_operation_indeterminate';
    }

    public function httpStatus(): int
    {
        return 504;
    }
}
