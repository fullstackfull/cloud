<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A correction to the estate that the platform will not make.
 *
 * Both codes answer 409 rather than 422, because neither is a malformed
 * request: the operator asked for something coherent and the world said no.
 * A client that retries a 422 unchanged gets the same answer for ever; a
 * client that reloads and retries a 409 may well succeed, and that is the
 * difference an operator screen needs to know about.
 */
final class InventoryChangeRefused extends DomainException
{
    private string $errorCode = 'infrastructure.refused';

    /**
     * Switching something off while a customer is still living on it.
     *
     * The count is in the message because it is the thing an operator does
     * next — find the twelve accounts, move them, try again — and it is not a
     * disclosure: they are already entitled to read the list it came from.
     */
    public static function becauseSomethingStillDependsOnIt(string $what, int $dependants): self
    {
        $exception = new self(sprintf(
            'That cannot be switched off while %d %s still depend%s on it. Stop new work from arriving first, '
            .'move what is there, then try again.',
            $dependants,
            $what,
            $dependants === 1 ? 's' : '',
        ));

        $exception->withContext(['depends_on' => $what, 'count' => $dependants]);

        return $exception->as('infrastructure.still_in_use');
    }

    /**
     * Two operators, one row, and the later save silently winning.
     *
     * The estate is the one place in the platform where a lost update is not
     * an inconvenience: an endpoint or a TLS setting quietly reverting is a
     * change nobody made and nobody can see in a diff.
     */
    public static function becauseSomebodyElseChangedItFirst(): self
    {
        $exception = new self(
            'Somebody else changed this while you had it open. Reload it, check what they did, and try again.'
        );

        return $exception->as('infrastructure.stale_write');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }
}
