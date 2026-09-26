<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A block the estate cannot take, because a block it already holds shares an
 * address with it in the same realm.
 *
 * 422 rather than the 409 InventoryChangeRefused answers with. A 409 says a
 * reload and a retry may well succeed; this one will not, because no route
 * edits or removes a subnet — the same request is refused for as long as the
 * block in the way is registered, and the thing to change is the request.
 *
 * The sentence names the block in the way, the pool holding it and the
 * building it is in, because that is what the operator does next. It is an
 * operator code with no entry in the customer catalogue, so the operator is
 * answered with this sentence rather than a catalogue one.
 */
final class SubnetRegistrationRefused extends DomainException
{
    public static function becauseItOverlaps(string $block, string $registered, string $pool, string $datacenter): self
    {
        $exception = new self(sprintf(
            '%s overlaps %s, which pool "%s" already holds in %s. Two blocks that share an address would give '
            .'that address to two customers.',
            $block,
            $registered,
            $pool,
            $datacenter,
        ));

        return $exception->withContext([
            'cidr' => $block,
            'overlaps' => $registered,
            'pool' => $pool,
            'datacenter' => $datacenter,
        ]);
    }

    public function errorCode(): string
    {
        return 'infrastructure.subnet_overlaps';
    }
}
