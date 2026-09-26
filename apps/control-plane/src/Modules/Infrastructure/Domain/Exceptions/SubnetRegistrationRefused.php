<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A block the estate cannot take: a block it already holds shares an address
 * with it in the same realm, or it is too wide to be expanded into the address
 * rows the allocator hands out.
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
    private string $refusal = 'infrastructure.subnet_overlaps';

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

    /**
     * A block registered for allocation becomes one row per address, written
     * inside the registration's own transaction. Past the limit that is a
     * write the request should not be making. The estate registers either
     * the pieces it hands out, or the aggregate as held space; the pieces
     * cannot be registered inside held space, which is overlap-checked like
     * any block.
     */
    public static function becauseItIsTooWideToAllocateFrom(string $block, int $addresses, int $limit): self
    {
        $exception = new self(sprintf(
            '%s holds %d addresses, and a block registered for allocation is expanded into one row per address; '
            .'the most one registration expands is %d. Register the pieces you will allocate from instead, or '
            .'register this block with "allocatable": false to hold the space without allocating from any of it.',
            $block,
            $addresses,
            $limit,
        ));

        $exception->refusal = 'infrastructure.subnet_too_wide_to_allocate_from';

        return $exception->withContext([
            'cidr' => $block,
            'addresses' => $addresses,
            'limit' => $limit,
        ]);
    }

    public function errorCode(): string
    {
        return $this->refusal;
    }
}
