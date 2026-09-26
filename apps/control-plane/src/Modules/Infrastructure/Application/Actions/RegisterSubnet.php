<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SubnetRegistrationRefused;
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
 *
 * ---------------------------------------------------------------------------
 * No two blocks in one realm share an address
 * ---------------------------------------------------------------------------
 *
 * Everything downstream of a subnet row is correct, which is what makes an
 * overlap bite. SeedSubnetAddresses writes one row per address per subnet,
 * and the allocator's locks and its two partial unique indexes are keyed on
 * the row. So `203.0.113.0/24` in one pool and `203.0.113.0/25` in another
 * become two rows each holding `203.0.113.10`, every lock and index is
 * satisfied, and two customers are handed one address. This is the only
 * place that can refuse it, and it compares parsed blocks — never the text,
 * which is how `203.0.113.7/24` used to pass a string rule and reach the
 * table's unique index as a 500.
 *
 * Two overlapping blocks are refused when they are in one realm:
 *
 *  - **the same datacenter, always.** Whatever the space, two pools in one
 *    building cannot both hold an address and mean different machines;
 *  - **any two datacenters, when either block is not locally reusable**
 *    (Cidr::isLocallyReusable()). A block with any space in it that is not
 *    designated for reuse is unique in the world, so it is unique here.
 *
 * And deliberately not reusable space in two buildings: `10.20.30.0/24` in
 * two datacenters is a normal estate, and refusing it would be a false
 * refusal.
 *
 * **The pool's `scope` appears nowhere in this.** The label is what the
 * estate believes; the address is what the world is. A realm read from the
 * label let two private-labelled pools in two buildings hold public space and
 * hand one address to two customers, and refused a legitimate private repeat
 * because RFC 1918 space had been misfiled in a public pool — a refusal the
 * estate could not undo, because the pool-update route refuses `scope` edits.
 *
 * Inactive subnets and inactive pools are compared too. `is_active = false`
 * stops the allocator reading a subnet; it does not release the addresses
 * already assigned out of it.
 *
 * The whole comparison is one PHP predicate, sameRealm(), rather than SQL:
 * the realm turns on classifying an address, which the table's varchar cannot
 * do, and a rule split between a query and a loop is two rules.
 *
 * ---------------------------------------------------------------------------
 * The lock
 * ---------------------------------------------------------------------------
 *
 * The check reads the estate and then writes to it, so two registrations that
 * both read before either writes would each pass and both commit. Each one
 * therefore takes a transaction-scoped advisory lock before it reads anything,
 * and a second registration waits for the first to commit or roll back.
 *
 * One key for the whole platform, not one per datacenter: whether two blocks
 * may coexist is decided across buildings as well as within one, so a key
 * scoped to the building would let two buildings race each other on public
 * space. Registration is an occasional operator act, and serialising all of
 * it is the price of a rule that is answered across buildings.
 *
 * The lock is taken at one site only, and a gate in tests/Architecture holds
 * that from the token stream rather than from anything written here.
 *
 * ---------------------------------------------------------------------------
 * What it does not cover
 * ---------------------------------------------------------------------------
 *
 * This is the only production-reachable writer of `subnets`: no route edits
 * or deletes a subnet, and the pool-update route refuses `scope` and
 * `ip_version` and does not accept `datacenter_id`, so a registered block
 * cannot be moved into an overlap afterwards. Two writers do not come through
 * here — the reference topology loader, which refuses to run in production,
 * and SubnetFactory, which the tests and the E2E seeder build with — and
 * neither would a hand-written INSERT. Overlaps an estate already held before
 * this check existed are not reported by it.
 *
 * A PostgreSQL exclusion constraint was considered and declined. It could
 * express only the one-pool realm, because the building a block is in lives
 * on `ip_pools` rather than `subnets`, so it would be a second, partial answer
 * to "may this block exist" — refusing some of what this refuses, with a
 * constraint violation instead of a 422.
 */
final readonly class RegisterSubnet
{
    /**
     * The advisory lock every registration takes before it reads the estate.
     */
    private const string REGISTRATION_LOCK = 'infrastructure.register-subnet';

    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws InvalidIpAddressException
     * @throws SubnetRegistrationRefused when the block shares an address with one in its realm
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
            act: function () use ($pool, $network, $block, $gateway): Subnet {
                // Before the read, and inside the transaction the write commits in.
                DB::statement('select pg_advisory_xact_lock(hashtext(?))', [self::REGISTRATION_LOCK]);

                $this->assertNothingInItsRealmOverlaps($block, $pool);

                return Subnet::query()->create([
                    'ip_pool_id' => $pool->getKey(),
                    'network_id' => $network?->getKey(),
                    'cidr' => (string) $block,
                    'ip_version' => $block->version(),
                    'prefix_length' => $block->prefixLength(),
                    'gateway' => $gateway,
                    'is_active' => true,
                ]);
            },
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

    /**
     * @throws SubnetRegistrationRefused
     */
    private function assertNothingInItsRealmOverlaps(Cidr $block, IpPool $pool): void
    {
        $registered = DB::table('subnets')
            ->join('ip_pools', 'ip_pools.id', '=', 'subnets.ip_pool_id')
            ->join('datacenters', 'datacenters.id', '=', 'ip_pools.datacenter_id')
            /*
             * Every registered block, active or not, in every pool. The family
             * is left to Cidr::overlaps() rather than filtered on the
             * `ip_version` column, which nothing ties to the text of `cidr`.
             *
             * The order decides which block the refusal names when more than
             * one is in the way — two disjoint blocks can sit inside one wider
             * candidate — and it has to name the same one every time. It is
             * the column's text order, not address order: `subnets.cidr` is a
             * varchar, so `10.0.0.0/8` sorts before `9.0.0.0/8`. Address order
             * would need `cidr::inet`, and that cast fails on any value that
             * is not an address — the column has no check constraint, so only
             * the writers' parsers keep such values out. Sorting text cannot
             * fail. (Parsing each row in the loop below can, on the same bad
             * row: a value that did not come through here would stop every
             * later registration either way, and the sort does not widen
             * that.) Deterministic is what the operator needs.
             *
             * The pool's slug breaks a tie on the text, which is not rare: one
             * private block is legitimately held by several buildings.
             */
            ->orderBy('subnets.cidr')
            ->orderBy('ip_pools.slug')
            ->get([
                'subnets.cidr',
                'ip_pools.slug as pool',
                'ip_pools.datacenter_id',
                'datacenters.slug as datacenter',
            ]);

        foreach ($registered as $row) {
            $existing = Cidr::fromString((string) $row->cidr);

            if ($block->overlaps($existing)
                && self::sameRealm($block, $pool->datacenter_id, $existing, (string) $row->datacenter_id)) {
                throw SubnetRegistrationRefused::becauseItOverlaps(
                    (string) $block,
                    (string) $existing,
                    (string) $row->pool,
                    (string) $row->datacenter,
                );
            }
        }
    }

    /**
     * Whether two blocks could be the same wire: always within one building,
     * and across buildings unless both are space designated for reuse.
     *
     * Read from the addresses alone. A pool's `scope` is an operator's label
     * and is deliberately not an argument here.
     */
    private static function sameRealm(Cidr $block, string $datacenter, Cidr $existing, string $existingDatacenter): bool
    {
        if ($datacenter === $existingDatacenter) {
            return true;
        }

        return ! $block->isLocallyReusable() || ! $existing->isLocallyReusable();
    }
}
