<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\SharedHosting\Domain\Enums\PreflightRefusalReason;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\PreflightRefusal;

/**
 * A machine failed its installation preflight and must not have a panel put
 * on it.
 *
 * Thrown rather than returned so that a caller which forgets to inspect the
 * report cannot proceed to the installer anyway. The report itself is still
 * available — an operator needs every refusal, not only the first, because
 * fixing them one round trip at a time on a machine that is being commissioned
 * wastes the one window in which reinstalling it is still cheap.
 */
final class HostingPreflightFailedException extends DomainException
{
    /**
     * The refusal this exception reports under.
     *
     * NOT named $code. Exception already declares an untyped $code, and
     * redeclaring it with a type is a fatal at class load.
     */
    private string $errorCode = 'hosting.preflight_failed';

    /**
     * @param  list<PreflightRefusal>  $refusals
     */
    public static function refused(string $hostname, string $panel, array $refusals): self
    {
        /*
         * The licence refusal is reported first when it is present, whatever
         * order the checks ran in. It is the only one of these an operator
         * cannot fix by editing the machine, and reporting a missing licence
         * behind "port 80 is in use" sends somebody to debug the wrong thing.
         */
        $primary = self::primary($refusals);

        $exception = new self(sprintf(
            'The host %s cannot have %s installed: %s.',
            $hostname,
            $panel,
            $primary === null
                ? 'preflight refused it'
                : $primary->summary(),
        ));

        $exception->errorCode = $primary?->reason->errorCode() ?? 'hosting.preflight_failed';

        return $exception->withContext([
            'hostname' => $hostname,
            'panel' => $panel,
            'refusals' => implode(', ', array_map(
                static fn (PreflightRefusal $refusal): string => $refusal->reason->errorCode(),
                $refusals,
            )),
            'refusal_count' => count($refusals),
        ]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * A refused preflight is a fact about the machine, not a malformed
     * request, and it is not something a retry resolves.
     */
    public function httpStatus(): int
    {
        return 409;
    }

    /**
     * @param  list<PreflightRefusal>  $refusals
     */
    private static function primary(array $refusals): ?PreflightRefusal
    {
        foreach ($refusals as $refusal) {
            if ($refusal->reason === PreflightRefusalReason::LicenceRequired) {
                return $refusal;
            }
        }

        return $refusals[0] ?? null;
    }
}
