<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Application\Actions\ReserveHostingNodeCapacity;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingUsernameConflictException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The (node, username) row is an idempotency key only within one customer.
 *
 * ReserveHostingNodeCapacity treats a row it finds for the requested name as
 * "the slot this job already holds": it returns it, or re-arms it to pending,
 * and the handler then marks it active. That reading is right for a retry of
 * the same job and catastrophic for a different customer — the live panel
 * account would belong to one customer while the row describing it named
 * another, so the portal listing, an SSO session, a suspend and a terminate
 * would all cross the tenant boundary, in whichever direction they were used.
 *
 * A panel username is unique per machine at the panel as well as in this
 * table, so a collision with another customer has no safe resolution on this
 * node and is refused outright.
 */
final class HostingUsernameOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private HostingNode $node;

    private Customer $victim;

    private Customer $attacker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]);

        $this->victim = Customer::factory()->create();
        $this->attacker = Customer::factory()->create();
    }

    #[Test]
    public function another_customers_row_is_never_taken_over_by_a_colliding_username(): void
    {
        // Terminated: the name is free again at the panel, so the create would
        // succeed and the row would go active still owned by the first
        // customer.
        $original = HostingAccount::factory()->create([
            'hosting_node_id' => $this->node->id,
            'customer_id' => $this->victim->id,
            'username' => 'shopco',
            'primary_domain' => 'shopco.example',
            'status' => HostingAccountStatus::Terminated,
        ]);

        try {
            app(ReserveHostingNodeCapacity::class)->execute(
                node: $this->node,
                username: 'shopco',
                primaryDomain: 'attacker.example',
                customerId: (string) $this->attacker->getKey(),
            );

            $this->fail('A username belonging to another customer was accepted as this job\'s own reservation.');
        } catch (HostingUsernameConflictException $e) {
            $this->assertSame('hosting.username_conflict', $e->errorCode());
        }

        $original->refresh();

        // Untouched: still terminated, still the first customer's, still their
        // domain — and no slot was taken for it.
        $this->assertSame(HostingAccountStatus::Terminated, $original->status);
        $this->assertSame($this->victim->id, $original->customer_id);
        $this->assertSame('shopco.example', $original->primary_domain);
        $this->assertSame(0, $this->node->fresh()?->account_count);
        $this->assertSame(1, HostingAccount::query()->count());
    }

    #[Test]
    public function a_live_account_of_another_customer_is_refused_too(): void
    {
        HostingAccount::factory()->create([
            'hosting_node_id' => $this->node->id,
            'customer_id' => $this->victim->id,
            'username' => 'shopco',
            'status' => HostingAccountStatus::Active,
        ]);

        $this->expectException(HostingUsernameConflictException::class);

        app(ReserveHostingNodeCapacity::class)->execute(
            node: $this->node,
            username: 'shopco',
            primaryDomain: 'attacker.example',
            customerId: (string) $this->attacker->getKey(),
        );
    }

    #[Test]
    public function the_same_customers_own_earlier_attempt_is_still_a_retry(): void
    {
        // The idempotency the lookup exists for has to survive the fix: a
        // retried job finds its own row and does not make a second one.
        $first = app(ReserveHostingNodeCapacity::class)->execute(
            node: $this->node,
            username: 'shopco',
            primaryDomain: 'shopco.example',
            customerId: (string) $this->victim->getKey(),
        );

        $second = app(ReserveHostingNodeCapacity::class)->execute(
            node: $this->node->fresh() ?? $this->node,
            username: 'shopco',
            primaryDomain: 'shopco.example',
            customerId: (string) $this->victim->getKey(),
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, HostingAccount::query()->count());
        $this->assertSame(1, $this->node->fresh()?->account_count);
    }

    #[Test]
    public function the_provisioning_job_fails_permanently_rather_than_building_on_another_customers_row(): void
    {
        HostingAccount::factory()->create([
            'hosting_node_id' => $this->node->id,
            'customer_id' => $this->victim->id,
            'username' => 'shopco',
            'primary_domain' => 'shopco.example',
            'status' => HostingAccountStatus::Terminated,
        ]);

        $package = HostingPackage::factory()->named('lyn_starter')->create();

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $this->attacker->getKey(),
            'payload' => [
                'hosting_package_id' => (string) $package->getKey(),
                'username' => 'shopco',
                'primary_domain' => 'attacker.example',
                'password' => 's3cret-panel-password',
                'contact_email' => 'owner@attacker.example',
            ],
        ]);

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->isFailure());
        // It will ask for the same name on every retry, and no other node in
        // the fleet was ever the problem.
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('hosting.username_conflict', $result->errorCode);

        $account = HostingAccount::query()->where('username', 'shopco')->sole();
        $this->assertSame($this->victim->id, $account->customer_id);
        $this->assertSame(HostingAccountStatus::Terminated, $account->status);
    }
}
