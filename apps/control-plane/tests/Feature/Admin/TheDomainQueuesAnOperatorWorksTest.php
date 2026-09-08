<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two lists nothing else will clear.
 *
 * The Timeout Rule deliberately leaves rows saying "nobody knows", and
 * reconciliation settles most of them by asking the registry. What is left is
 * a queue — and a queue nobody can list is a queue nobody works.
 */
final class TheDomainQueuesAnOperatorWorksTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function domain(DomainState $state, string $name, ?string $expiresAt = null): Domain
    {
        return Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => $name,
            'tld' => 'test',
            'state' => $state,
            'provider' => 'fake',
            'expires_at' => $expiresAt,
        ]);
    }

    #[Test]
    public function the_names_nobody_can_vouch_for_can_be_listed_on_their_own(): void
    {
        $this->domain(DomainState::Active, 'fine.test', now()->addYear()->toIso8601String());
        $this->domain(DomainState::Indeterminate, 'unsure.test');
        $this->domain(DomainState::NeedsReview, 'disputed.test');

        $response = $this->actingAs($this->operator())
            ->getJson('/api/admin/domains?needs_attention=1')
            ->assertOk();

        $names = array_column((array) $response->json('data'), 'name');
        sort($names);

        $this->assertSame(['disputed.test', 'unsure.test'], $names);
    }

    #[Test]
    public function the_names_about_to_lapse_come_back_soonest_first(): void
    {
        $this->domain(DomainState::Active, 'later.test', now()->addDays(25)->toIso8601String());
        $this->domain(DomainState::Active, 'sooner.test', now()->addDays(3)->toIso8601String());
        $this->domain(DomainState::Active, 'distant.test', now()->addYear()->toIso8601String());

        $response = $this->actingAs($this->operator())
            ->getJson('/api/admin/domains?expiring_within_days=30')
            ->assertOk();

        // A domain that lapses is gone rather than suspended, so the ordering
        // is the whole value of the list: the top row is the one with the
        // least time left.
        $this->assertSame(
            ['sooner.test', 'later.test'],
            array_column((array) $response->json('data'), 'name'),
        );
    }

    #[Test]
    public function the_operator_list_names_the_registrar_and_the_customer_list_does_not(): void
    {
        $this->domain(DomainState::Active, 'whose.test', now()->addYear()->toIso8601String());

        $body = (string) $this->actingAs($this->operator())
            ->getJson('/api/admin/domains')
            ->assertOk()
            ->getContent();

        // The first thing an operator needs and the one thing a customer has
        // no business seeing: which third party this platform buys from.
        $this->assertStringContainsString('"provider":"fake"', $body);
    }

    #[Test]
    public function money_spent_on_an_outcome_nobody_established_is_its_own_queue(): void
    {
        DomainOperation::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'stuck.test',
            'kind' => DomainOperationKind::Register,
            'state' => DomainOperationState::Indeterminate,
            'provider' => 'fake',
        ]);

        DomainOperation::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'done.test',
            'kind' => DomainOperationKind::Register,
            'state' => DomainOperationState::Completed,
            'provider' => 'fake',
        ]);

        $response = $this->actingAs($this->operator())
            ->getJson('/api/admin/domains/operations?needs_attention=1')
            ->assertOk();

        $this->assertSame(['stuck.test'], array_column((array) $response->json('data'), 'name'));

        // Both sides of the money, because the question is usually what it
        // cost against what was charged.
        $this->assertArrayHasKey('cost_minor', (array) $response->json('data.0'));
    }

    #[Test]
    public function a_person_without_the_permission_cannot_read_either_queue(): void
    {
        $this->domain(DomainState::Indeterminate, 'private.test');

        $nobody = User::factory()->create();

        $this->actingAs($nobody)->getJson('/api/admin/domains')->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/admin/domains/operations')->assertForbidden();
    }
}
