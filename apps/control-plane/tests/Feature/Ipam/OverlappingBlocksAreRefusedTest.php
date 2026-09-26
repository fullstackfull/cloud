<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which blocks may coexist, asked through the route an operator uses.
 *
 * ---------------------------------------------------------------------------
 * The realm, and why the pool's label is not in it
 * ---------------------------------------------------------------------------
 *
 * Two overlapping blocks are refused when they could be the same wire:
 *
 *  - **in one building, always** — whatever the space is, two pools in one
 *    datacenter cannot both hold an address and mean different machines;
 *  - **across buildings, when either block is not locally reusable** — a
 *    block that is not wholly inside space the address plan designates for
 *    reuse (RFC 1918, shared address space, link-local and the like) is
 *    unique in the world, so it is unique on this platform.
 *
 * And deliberately **not** reusable space in two buildings: `10.20.30.0/24`
 * in two datacenters is a normal estate, and refusing it would be a false
 * refusal the operator cannot work around.
 *
 * The classification is made from the *address*, never from the pool's
 * `scope`. The label is what the estate believes; the address is what the
 * world is. A realm rule that trusted the label let two private-labelled
 * pools in two buildings hold `203.0.113.0/24` and `203.0.113.0/25` and hand
 * `203.0.113.10` to two customers — see OneRealAddressReachesOneCustomerTest —
 * and the mirror of it refused a legitimate private repeat because RFC 1918
 * space had been misfiled in a public pool, a refusal the estate could not
 * undo because the pool-update route refuses `scope` edits. Both orders of
 * both mistakes are pinned below.
 *
 * ---------------------------------------------------------------------------
 * What the refusal says
 * ---------------------------------------------------------------------------
 *
 * The operator is told which block is in the way, which pool holds it and in
 * which building, because that is what they do next. The message is the
 * exception's own sentence: this is an operator code with no entry in the
 * customer catalogue, so it is not replaced by one.
 */
final class OverlappingBlocksAreRefusedTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    /** @var array<string, string> */
    private array $datacenters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::SuperAdmin->value]);
    }

    // ---- one building ------------------------------------------------------

    #[Test]
    public function a_block_overlapping_another_pool_in_the_same_building_is_refused_naming_the_block_and_the_site(): void
    {
        $held = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $other = $this->pool('kw-north', IpPoolScope::Public, 'north-reserve');

        $this->register($held, '203.0.113.0/24')->assertCreated();

        $refusal = $this->register($other, '203.0.113.0/25');

        $this->assertRefusedOver($refusal, '203.0.113.0/24', 'north-public', 'kw-north');
        $this->assertSame(['203.0.113.0/24'], $this->registered());
    }

    #[Test]
    public function adjacent_blocks_do_not_overlap(): void
    {
        $pool = $this->pool('kw-north', IpPoolScope::Public);

        // The last address of one and the first of the other are neighbours,
        // not the same address. Refusing this would be a false refusal.
        $this->register($pool, '203.0.113.0/25')->assertCreated();
        $this->register($pool, '203.0.113.128/25')->assertCreated();
    }

    #[Test]
    public function a_host_route_inside_a_registered_block_is_refused_and_so_is_a_block_around_one(): void
    {
        $pool = $this->pool('kw-north', IpPoolScope::Private, 'north-private');
        $other = $this->pool('kw-north', IpPoolScope::Private);

        $this->register($pool, '10.1.0.0/16')->assertCreated();
        $this->assertRefusedOver($this->register($other, '10.1.2.3/32'), '10.1.0.0/16', 'north-private', 'kw-north');

        // Containment is symmetric: the wider block arriving second is the
        // same collision seen from the other side.
        $this->register($pool, '10.9.8.7/32')->assertCreated();
        $this->assertRefusedOver($this->register($other, '10.9.0.0/16'), '10.9.8.7/32', 'north-private', 'kw-north');
    }

    #[Test]
    public function a_block_written_from_one_of_its_own_hosts_is_refused_by_the_guard_rather_than_the_index(): void
    {
        $pool = $this->pool('kw-north', IpPoolScope::Public, 'north-public');

        $this->register($pool, '203.0.113.0/24')->assertCreated();

        // Both normalise to the block already held. A rule comparing the raw
        // string passed them both on to the table's unique index, which
        // answered with a 500.
        foreach (['203.0.113.7/24', ' 203.0.113.0/24 '] as $spelling) {
            $this->assertRefusedOver($this->register($pool, $spelling), '203.0.113.0/24', 'north-public', 'kw-north');
        }

        $this->assertSame(['203.0.113.0/24'], $this->registered());
    }

    #[Test]
    public function a_private_block_still_conflicts_inside_its_own_building(): void
    {
        $held = $this->pool('kw-north', IpPoolScope::Private, 'north-private');
        $other = $this->pool('kw-north', IpPoolScope::Private);

        $this->register($held, '10.20.30.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($other, '10.20.30.128/25'), '10.20.30.0/24', 'north-private', 'kw-north');
    }

    #[Test]
    public function an_inactive_subnet_still_holds_its_addresses(): void
    {
        $held = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $other = $this->pool('kw-north', IpPoolScope::Public);

        $this->register($held, '203.0.113.0/24')->assertCreated();

        // `is_active = false` stops the allocator reading a subnet. It does
        // not release the addresses already assigned out of it.
        Subnet::query()->update(['is_active' => false]);

        $this->assertRefusedOver($this->register($other, '203.0.113.64/26'), '203.0.113.0/24', 'north-public', 'kw-north');
    }

    #[Test]
    public function a_block_in_an_inactive_pool_still_holds_its_addresses(): void
    {
        $held = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $other = $this->pool('kw-south', IpPoolScope::Public);

        $this->register($held, '203.0.113.0/24')->assertCreated();

        // The pool's switch stops new allocation from every subnet in it. The
        // customers already holding its addresses are still holding them.
        $held->forceFill(['is_active' => false])->save();

        $this->assertRefusedOver($this->register($other, '203.0.113.64/26'), '203.0.113.0/24', 'north-public', 'kw-north');
    }

    // ---- across buildings --------------------------------------------------

    #[Test]
    public function public_space_cannot_be_held_by_two_buildings(): void
    {
        $north = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $south = $this->pool('kw-south', IpPoolScope::Public);

        $this->register($north, '198.51.100.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($south, '198.51.100.128/25'), '198.51.100.0/24', 'north-public', 'kw-north');
    }

    #[Test]
    public function a_private_pool_may_not_hold_public_space_the_estate_already_has(): void
    {
        $north = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $south = $this->pool('kw-south', IpPoolScope::Private);

        $this->register($north, '198.51.100.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($south, '198.51.100.0/25'), '198.51.100.0/24', 'north-public', 'kw-north');
    }

    #[Test]
    public function public_space_misfiled_in_a_private_pool_first_is_still_refused_everywhere_else(): void
    {
        // The same mistake in the other order: the mislabelled row is the one
        // already there. A realm read from the label would let the second
        // building in, because the row it compares against says "private".
        $north = $this->pool('kw-north', IpPoolScope::Private, 'north-private');
        $south = $this->pool('kw-south', IpPoolScope::Public);

        $this->register($north, '198.51.100.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($south, '198.51.100.0/25'), '198.51.100.0/24', 'north-private', 'kw-north');
    }

    #[Test]
    public function a_private_block_may_repeat_in_another_building(): void
    {
        $north = $this->pool('kw-north', IpPoolScope::Private);
        $south = $this->pool('kw-south', IpPoolScope::Private);

        $this->register($north, '10.20.30.0/24')->assertCreated();
        $this->register($south, '10.20.30.0/24')->assertCreated();

        $this->assertSame(['10.20.30.0/24', '10.20.30.0/24'], $this->registered());
    }

    #[Test]
    public function shared_address_space_may_repeat_in_another_building(): void
    {
        // RFC 6598 space exists to be reused behind every carrier-grade NAT.
        $north = $this->pool('kw-north', IpPoolScope::Private);
        $south = $this->pool('kw-south', IpPoolScope::Private);

        $this->register($north, '100.64.0.0/24')->assertCreated();
        $this->register($south, '100.64.0.0/24')->assertCreated();
    }

    #[Test]
    public function private_space_misfiled_in_a_public_pool_does_not_refuse_a_private_repeat_elsewhere(): void
    {
        // The mirror of the harm: RFC 1918 space filed under a public label.
        // Trusting the label would refuse the legitimate repeat below, and the
        // estate could not undo it — the pool-update route refuses `scope`.
        $north = $this->pool('kw-north', IpPoolScope::Public);
        $south = $this->pool('kw-south', IpPoolScope::Private);

        $this->register($north, '10.20.30.0/24')->assertCreated();
        $this->register($south, '10.20.30.0/24')->assertCreated();
    }

    #[Test]
    public function a_block_that_straddles_private_and_public_space_is_compared_everywhere(): void
    {
        // 10.0.0.0/7 is half RFC 1918 and half the entirely public 11.0.0.0/8.
        // Reusable means wholly inside reusable space, so this is compared
        // platform-wide.
        $north = $this->pool('kw-north', IpPoolScope::Private, 'north-private');
        $south = $this->pool('kw-south', IpPoolScope::Private);

        $this->register($north, '10.20.30.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($south, '10.0.0.0/7'), '10.20.30.0/24', 'north-private', 'kw-north');
    }

    // ---- the scan ----------------------------------------------------------

    #[Test]
    public function every_registered_block_is_compared_not_only_the_first(): void
    {
        $north = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $south = $this->pool('kw-south', IpPoolScope::Private, 'south-private');
        $candidate = $this->pool('kw-north', IpPoolScope::Public);

        // First in scan order and nowhere near the candidate.
        $this->register($north, '10.0.0.0/24')->assertCreated();
        $this->register($north, '203.0.113.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($candidate, '203.0.113.0/25'), '203.0.113.0/24', 'north-public', 'kw-north');

        // First in scan order, overlapping, and in another realm: the scan
        // must keep going to the row that is in this one.
        $this->register($south, '10.20.0.0/16')->assertCreated();
        $this->register($north, '10.20.30.0/24')->assertCreated();

        $this->assertRefusedOver($this->register($candidate, '10.20.30.128/25'), '10.20.30.0/24', 'north-public', 'kw-north');
    }

    #[Test]
    public function the_refusal_names_the_first_conflicting_block_in_string_order(): void
    {
        /*
         * Two disjoint blocks inside one wider candidate: *the* conflicting
         * block is a choice, and the operator has to be told the same one
         * every time. The choice is the column's text order, not address
         * order — `subnets.cidr` is a varchar — so `10.0.0.0/8` sorts before
         * `9.0.0.0/8`.
         *
         * The fixture is built so that registration order and address order
         * agree with each other and disagree with text order: `9.0.0.0/8` is
         * written first and is lower as an address. A scan in either of those
         * orders would name it; the text order names the other one.
         */
        $pool = $this->pool('kw-north', IpPoolScope::Public, 'north-public');
        $other = $this->pool('kw-north', IpPoolScope::Public);

        $this->register($pool, '9.0.0.0/8')->assertCreated();
        $this->register($pool, '10.0.0.0/8')->assertCreated();

        $refusal = $this->register($other, '8.0.0.0/5');

        $this->assertRefusedOver($refusal, '10.0.0.0/8', 'north-public', 'kw-north');
        $this->assertStringNotContainsString('9.0.0.0/8', (string) $refusal->json('error.message'));
    }

    #[Test]
    public function the_refusal_names_the_same_pool_when_two_pools_tie_on_the_block(): void
    {
        /*
         * One private block legitimately held by two buildings, and a
         * candidate that is in the realm of both. The text of the block is a
         * tie, so the pool decides which of the two is named — by its slug.
         *
         * Two halves, registered in opposite orders, because a single pair can
         * agree with an undecided order by accident: whichever way a scan
         * without the tiebreak happens to return tied rows, one half names
         * the wrong pool.
         */
        $a = $this->pool('kw-a', IpPoolScope::Private, 'a-pool');
        $z = $this->pool('kw-z', IpPoolScope::Private, 'z-pool');
        $candidate = $this->pool('kw-c', IpPoolScope::Private);

        $this->register($z, '10.20.30.0/24')->assertCreated();
        $this->register($a, '10.20.30.0/24')->assertCreated();

        $first = $this->register($candidate, '10.0.0.0/7');
        $this->assertRefusedOver($first, '10.20.30.0/24', 'a-pool', 'kw-a');
        $this->assertStringNotContainsString('z-pool', (string) $first->json('error.message'));

        $this->register($a, '192.168.5.0/24')->assertCreated();
        $this->register($z, '192.168.5.0/24')->assertCreated();

        $second = $this->register($candidate, '192.0.0.0/8');
        $this->assertRefusedOver($second, '192.168.5.0/24', 'a-pool', 'kw-a');
        $this->assertStringNotContainsString('z-pool', (string) $second->json('error.message'));
    }

    // ---- helpers -----------------------------------------------------------

    private function pool(string $site, IpPoolScope $scope, ?string $slug = null): IpPool
    {
        $this->datacenters[$site] ??= (string) Datacenter::factory()->create(['slug' => $site])->id;

        $attributes = [
            'datacenter_id' => $this->datacenters[$site],
            'scope' => $scope,
        ];

        if ($slug !== null) {
            $attributes['slug'] = $slug;
        }

        return IpPool::factory()->create($attributes);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function register(IpPool $pool, string $cidr): TestResponse
    {
        return $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$pool->id.'/subnets', ['cidr' => $cidr]);
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    private function assertRefusedOver(TestResponse $response, string $block, string $pool, string $site): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'infrastructure.subnet_overlaps');

        $message = (string) $response->json('error.message');

        $this->assertStringContainsString($block, $message, 'The refusal does not name the block in the way.');
        $this->assertStringContainsString('"'.$pool.'"', $message, 'The refusal does not name the pool holding it.');
        $this->assertStringContainsString($site, $message, 'The refusal does not name the building.');
    }

    /**
     * @return list<string>
     */
    private function registered(): array
    {
        /** @var list<string> $blocks */
        $blocks = Subnet::query()->orderBy('cidr')->pluck('cidr')->all();

        return $blocks;
    }
}
