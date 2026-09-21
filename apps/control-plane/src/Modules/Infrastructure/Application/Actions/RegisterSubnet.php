<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Cidr;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * The actual addresses, under a pool.
 *
 * ---------------------------------------------------------------------------
 * The rules are the allocator's, not this form's
 * ---------------------------------------------------------------------------
 *
 * Everything true about a block here — that it parses, what version it is, how
 * long the prefix is, whether the gateway falls inside it — is decided by
 * {@see Cidr}, which is what IpAllocator reads the same block with. A second
 * set of rules in an admin request would be a second answer to "is this a
 * valid subnet", and the one that disagrees would be the one that let an
 * operator write a block the allocator then refuses to allocate from.
 *
 * The version is derived rather than asked for: a form that takes both a block
 * and its version can be given a pair that disagree, and then one of the two
 * is wrong on a row the allocator reads.
 *
 * A network is optional and, when given, must be in the same building as the
 * pool. An address plan that joins a pool in one datacenter to a segment in
 * another describes somewhere that does not exist.
 */
final readonly class RegisterSubnet
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws InvalidIpAddressException
     */
    public function execute(IpPool $pool, string $cidr, ?string $gateway, ?Network $network, User $operator): Subnet
    {
        $block = Cidr::fromString($cidr);

        if ($block->version() !== $pool->ip_version) {
            throw InvalidIpAddressException::forCidr(
                $cidr,
                sprintf('the pool holds IPv%d addresses', $pool->ip_version->value),
            );
        }

        if ($gateway !== null && ! $block->contains($gateway)) {
            throw InvalidIpAddressException::outsideSubnet($gateway, (string) $block);
        }

        if ($network !== null && $network->datacenter_id !== $pool->datacenter_id) {
            throw InvalidIpAddressException::forCidr(
                $cidr,
                'the network is in a different datacenter from the pool',
            );
        }

        return $this->record->execute(
            act: fn (): Subnet => Subnet::query()->create([
                'ip_pool_id' => $pool->getKey(),
                'network_id' => $network?->getKey(),
                'cidr' => (string) $block,
                'ip_version' => $block->version(),
                'prefix_length' => $block->prefixLength(),
                'gateway' => $gateway,
                'is_active' => true,
            ]),
            describe: fn (Subnet $subnet): AuditedAct => new AuditedAct(
                action: AuditAction::SubnetRegistered,
                subject: $subnet,
                context: [
                    'cidr' => (string) $block,
                    'pool' => $pool->slug,
                    'network' => $network?->slug,
                    'usable_hosts' => $block->usableHostCount(),
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
