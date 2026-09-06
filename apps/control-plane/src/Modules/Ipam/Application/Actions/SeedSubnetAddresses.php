<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Actions;

use Illuminate\Support\Str;
use Lynomia\Modules\Ipam\Application\DTOs\SubnetSeedResult;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\AddressNotAllocatableException;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * Turns a CIDR block into the rows the allocator hands out.
 *
 * Three properties are non-negotiable, and each of them is a production
 * incident somewhere in somebody's history:
 *
 *  1. The network, broadcast and gateway addresses exist as rows and are
 *     marked unavailable. They exist rather than being skipped so that nothing
 *     can later "discover" them as unused space; they are unavailable because
 *     an allocatable gateway does not break one customer, it removes the
 *     default route for every host on the subnet at once.
 *
 *  2. Running it twice is safe. Subnets get extended, gateways get corrected
 *     and seeds get re-run by an operator who is not sure whether the first
 *     one finished, so the insert is ON CONFLICT DO NOTHING against the
 *     (subnet_id, address) unique index rather than a check-then-insert.
 *
 *  3. It never loads a subnet into memory. A /16 is 65,536 rows and a /8 is
 *     16.7 million; the address generator is lazy and the inserts are chunked,
 *     so peak memory is one chunk regardless of prefix length.
 */
final readonly class SeedSubnetAddresses
{
    /**
     * Rows per INSERT. Large enough that a /24 is one statement, small enough
     * that a /16 never builds a multi-megabyte query string.
     */
    private const int CHUNK_SIZE = 1000;

    /**
     * @throws AddressNotAllocatableException when the subnet is IPv6
     * @throws InvalidIpAddressException when the gateway is not inside the block
     */
    public function execute(Subnet $subnet, int $chunkSize = self::CHUNK_SIZE): SubnetSeedResult
    {
        $block = $subnet->block();

        /*
         * IPv6 is never expanded, and this is a modelling decision rather than
         * a performance one.
         *
         * The smallest subnet a v6 host may sit on is a /64 — that is what
         * SLAAC requires — and a /64 holds 18,446,744,073,709,551,616
         * addresses. There is no chunk size that makes that a table. More to
         * the point, there is no reason to have one: v6 is not scarce, so the
         * platform does not allocate v6 address by address at all. Each
         * service is delegated its own prefix (a /64 per VM out of the
         * customer's /48 or the datacenter's /44), the prefix is recorded on
         * the service, and everything inside it belongs to that service by
         * construction. Nothing in ip_addresses is ever v6.
         */
        if (! $block->version()->isEnumerable()) {
            throw AddressNotAllocatableException::ipv6IsDelegatedNotEnumerated((string) $block);
        }

        $this->assertGatewayIsInside($subnet, (string) $block);

        $nonHostAddresses = $subnet->nonHostAddresses();
        // A hash lookup, because this is consulted once per address and a /16
        // would otherwise be 65,536 linear scans of the same three-item list.
        $nonHostIndex = array_flip($nonHostAddresses);

        $inserted = 0;
        $buffer = [];
        $now = now();

        foreach ($block->addresses() as $address) {
            $buffer[] = [
                'id' => (string) Str::ulid(),
                'subnet_id' => $subnet->getKey(),
                'address' => $address,
                'ip_version' => $subnet->ip_version->value,
                'status' => isset($nonHostIndex[$address])
                    ? IpAddressStatus::Unavailable->value
                    : IpAddressStatus::Available->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= $chunkSize) {
                $inserted += $this->flush($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $inserted += $this->flush($buffer);
        }

        $conflicting = $this->enforceNonHostAddresses($subnet, $nonHostAddresses);

        return new SubnetSeedResult(
            subnetId: (string) $subnet->getKey(),
            cidr: (string) $block,
            inserted: $inserted,
            total: IpAddress::query()->where('subnet_id', $subnet->getKey())->count(),
            allocatable: IpAddress::query()->where('subnet_id', $subnet->getKey())->available()->count(),
            unavailableAddresses: $nonHostAddresses,
            conflictingAddresses: $conflicting,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        /*
         * insertOrIgnore, not a SELECT followed by an INSERT. The check and
         * the write have to be one statement: two operators re-seeding the
         * same subnet at once both pass a check, and the second one then dies
         * on the unique index having already written half a subnet.
         */
        return IpAddress::query()->insertOrIgnore($rows);
    }

    /**
     * Bring the infrastructure addresses back to unavailable on a re-run.
     *
     * This is what makes the action a repair as well as a seed: a subnet whose
     * gateway was corrected after seeding has a row that is now allocatable
     * and must not be. Only rows that are still `available` are touched — an
     * address that is reserved or assigned is somebody's, and silently
     * flipping it to unavailable would hide the real problem (a customer
     * holding the gateway) instead of surfacing it.
     *
     * @param  list<string>  $nonHostAddresses
     * @return list<string> the ones that are already held and need a human
     */
    private function enforceNonHostAddresses(Subnet $subnet, array $nonHostAddresses): array
    {
        if ($nonHostAddresses === []) {
            return [];
        }

        IpAddress::query()
            ->where('subnet_id', $subnet->getKey())
            ->whereIn('address', $nonHostAddresses)
            ->where('status', IpAddressStatus::Available->value)
            ->update([
                'status' => IpAddressStatus::Unavailable->value,
                'updated_at' => now(),
            ]);

        /** @var list<string> $held */
        $held = IpAddress::query()
            ->where('subnet_id', $subnet->getKey())
            ->whereIn('address', $nonHostAddresses)
            ->whereIn('status', [
                IpAddressStatus::Reserved->value,
                IpAddressStatus::Assigned->value,
            ])
            ->orderBy('address')
            ->pluck('address')
            ->all();

        return $held;
    }

    private function assertGatewayIsInside(Subnet $subnet, string $cidr): void
    {
        $gateway = $subnet->gateway;

        if ($gateway === null || $gateway === '') {
            return;
        }

        // Validates the shape as well as the membership: a gateway that is not
        // an address at all would otherwise be written as an unavailable row
        // that matches nothing and protects nothing.
        $parsed = IpAddressValue::fromString($gateway);

        if (! $subnet->block()->contains($parsed->value())) {
            throw InvalidIpAddressException::outsideSubnet($parsed->value(), $cidr);
        }
    }
}
