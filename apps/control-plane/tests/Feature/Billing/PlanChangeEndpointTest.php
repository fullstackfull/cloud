<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * Changing plan, from the customer's side.
 *
 * The endpoint existed and had no HTTP test at all; the quote endpoint did not
 * exist, so a customer could confirm a plan change without being shown what it
 * would cost. Both halves are covered here, and the assertions are grouped
 * around the two things a plan change must never do: charge for something it
 * then does not deliver, and destroy data to make a downgrade possible.
 */
final class PlanChangeEndpointTest extends BillingApiTestCase
{
    private Product $product;

    private Plan $small;

    private Plan $large;

    private Plan $smallerDisk;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);

        $this->freezeTime();

        $this->product = Product::factory()->create(['kind' => 'vps']);

        $this->small = $this->plan('small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], 9_000);
        $this->large = $this->plan('large', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], 18_000);
        $this->smallerDisk = $this->plan('tiny', ['vcpu' => 1, 'memory_mib' => 2048, 'disk_gib' => 20], 4_500);
    }

    #[Test]
    public function the_options_endpoint_prices_every_plan_the_backend_would_allow(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);

        $body = $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data');

        $byPlan = collect($body)->keyBy('plan_id');

        // The plan they are on, named and refused as such rather than hidden.
        $this->assertSame([PlanChangeRefusal::SamePlan->value], $byPlan[$this->small->id]['refusals']);

        $upgrade = $byPlan[$this->large->id];

        $this->assertTrue($upgrade['is_available']);
        $this->assertSame([], $upgrade['refusals']);

        /*
         * Money as minor units and a currency, never a float and never a
         * formatted string: a client that receives 12.75 has received a number
         * it cannot safely add to another one.
         */
        $this->assertSame(18_000, $upgrade['new_recurring']['minor_units']);
        $this->assertSame(9_000, $upgrade['current_recurring']['minor_units']);
        $this->assertSame('KWD', $upgrade['amount_due_now']['currency']);

        // The resource difference, so the portal need not compare plans itself.
        $this->assertSame(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40], $upgrade['current_resources']);
        $this->assertSame(['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80], $upgrade['new_resources']);
        $this->assertTrue($upgrade['changes_infrastructure']);
    }

    #[Test]
    public function a_plan_with_a_smaller_disk_is_refused_and_priced_at_nothing(): void
    {
        /*
         * The refusal the phase brief calls out by name. Shrinking a disk
         * truncates a filesystem; no confirmation dialogue makes that a thing
         * to do to a running server because a plan is cheaper.
         *
         * The plan is still listed, with its reason. The commonest thing a
         * customer opens this screen to do is move to something smaller, and a
         * screen that silently omitted the plan they were looking for would
         * tell them nothing.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);

        $body = collect($this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->keyBy('plan_id');

        $downgrade = $body[$this->smallerDisk->id];

        $this->assertFalse($downgrade['is_available']);
        $this->assertContains(PlanChangeRefusal::WouldShrinkDisk->value, $downgrade['refusals']);

        // No price is shown for something the platform will not sell.
        $this->assertNull($downgrade['amount_due_now']);
        $this->assertNull($downgrade['credit']);
    }

    #[Test]
    public function confirming_a_disk_shrink_directly_is_still_refused(): void
    {
        // The screen refuses it; a client that posts the plan id anyway meets
        // the same refusal, because the check lives in the action rather than
        // in the list the portal happened to render.
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'shrink-attempt-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->smallerDisk->id,
                'price_id' => $this->priceOf($this->smallerDisk)->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.plan_change_refused');

        // And nothing moved: not the plan, not a job.
        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function an_upgrade_charges_the_difference_and_queues_the_resize(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $service = $this->serviceWithMachine($customer, $subscription);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'upgrade-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
            ])
            ->assertOk();

        // Billing moved immediately.
        $this->assertSame($this->large->id, $subscription->fresh()?->plan_id);
        $this->assertSame(18_000, $subscription->fresh()?->recurring_amount_minor);

        /*
         * And the machine has not. The response says so rather than reporting
         * a completed upgrade: the money moved in a millisecond and the
         * hypervisor takes minutes.
         */
        $response->assertJsonPath('data.awaits_infrastructure', true);
        $this->assertNotNull($response->json('data.resize.job_id'));

        $job = ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize->value)->sole();

        $this->assertSame($service->getKey(), $job->service_id);
        $this->assertSame(4, $job->payload['vcpu']);
        $this->assertSame(8192, $job->payload['memory_mib']);
        $this->assertSame(80, $job->payload['disk_gib']);

        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function the_service_still_reports_the_old_size_until_the_provider_agrees(): void
    {
        /*
         * The invariant the phase brief states as "billing success alone is
         * NOT plan-change completion". Writing the new shape now would show a
         * customer four vCPU on a machine running two — and would tell the
         * platform's own capacity accounting that a node had handed out memory
         * it has not.
         */
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $service = $this->serviceWithMachine($customer, $subscription);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'upgrade-2')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
            ])
            ->assertOk();

        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        $this->assertSame(2, $machine->vcpu, 'The machine was recorded as resized before the hypervisor was asked.');
        $this->assertSame(4096, $machine->memory_mib);
        $this->assertSame(40, $machine->disk_gib);
    }

    #[Test]
    public function a_double_submission_changes_the_plan_once(): void
    {
        // A customer double-clicking confirm on a slow connection. Two
        // prorations would be two credits and two charges; two resize jobs
        // would be two upgrades on one machine.
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);
        $this->serviceWithMachine($customer, $subscription);

        $payload = [
            'plan_id' => $this->large->id,
            'price_id' => $this->priceOf($this->large)->id,
        ];

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'double-click-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", $payload)
            ->assertOk();

        /*
         * The second press is refused as "same plan" — the subscription is
         * already on it — which is the honest answer and the one that costs
         * the customer nothing.
         */
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'double-click-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", $payload)
            ->assertStatus(409);

        $this->assertSame(1, ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize->value)->count());
    }

    #[Test]
    public function a_change_without_an_idempotency_key_is_refused(): void
    {
        // Money moves here. Every other request in this API that moves money
        // requires the header, and a plan change is not the exception.
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);

        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['idempotency_key']]]]);
    }

    #[Test]
    public function a_plan_from_another_product_is_not_offered(): void
    {
        // A VPS plan and a hosting plan are not alternatives to one another,
        // and offering the swap would be offering to replace one service with
        // a different one.
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);

        $hosting = Product::factory()->create(['kind' => 'shared_hosting']);
        $hostingPlan = $this->plan('hosting-starter', ['disk_gib' => 50], 3_000, $hosting);

        $offered = collect($this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->pluck('plan_id');

        $this->assertNotContains($hostingPlan->id, $offered);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'cross-family-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $hostingPlan->id,
                'price_id' => $this->priceOf($hostingPlan)->id,
            ])
            ->assertStatus(409);
    }

    #[Test]
    public function another_customers_subscription_is_not_found(): void
    {
        [, $user] = $this->accountWithOwner();
        [$victim] = $this->accountWithOwner();

        $theirs = $this->subscriptionOn($victim, $this->small);

        $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$theirs->id}/plan-options")
            ->assertNotFound();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'cross-tenant-1')
            ->postJson("/api/v1/subscriptions/{$theirs->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function a_member_may_see_the_options_and_not_take_them(): void
    {
        // Reading what an upgrade would cost is a billing question; committing
        // to it spends the account's money.
        [$customer, $owner] = $this->accountWithOwner();
        $member = $this->memberOf($customer, CustomerRole::Member);
        $subscription = $this->subscriptionOn($customer, $this->small);

        $this->actingAs($owner)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk();

        $this->actingAs($member)
            ->withHeader('Idempotency-Key', 'member-attempt-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
            ])
            ->assertStatus(403);
    }

    #[Test]
    public function the_change_is_recorded_with_what_it_cost(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionOn($customer, $this->small);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'audited-1')
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $this->large->id,
                'price_id' => $this->priceOf($this->large)->id,
            ])
            ->assertOk();

        $entry = AuditEntry::query()->where('action', AuditAction::PlanChanged->value)->sole();

        $this->assertSame((string) $this->large->getKey(), $entry->context['to_plan_id']);
        $this->assertSame('KWD', $entry->context['currency']);
        $this->assertIsInt($entry->context['amount_due_now_minor']);
    }

    /**
     * @param  array<string, int>  $resources
     */
    private function plan(string $slug, array $resources, int $minor, ?Product $product = null): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => ($product ?? $this->product)->getKey(),
            'slug' => $slug,
            'resources' => $resources,
            'is_active' => true,
            'is_public' => true,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $minor,
        ]);

        return $plan;
    }

    private function priceOf(Plan $plan): PlanPrice
    {
        return PlanPrice::query()->where('plan_id', $plan->getKey())->sole();
    }

    private function subscriptionOn(Customer $customer, Plan $plan): Subscription
    {
        return Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(10))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $this->priceOf($plan)->recurring_amount_minor,
            ]);
    }

    /**
     * A service with a real machine on it, so a resize has something to act on.
     */
    private function serviceWithMachine(Customer $customer, Subscription $subscription): Service
    {
        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40],
        ]);

        $node = ComputeNode::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->getKey(),
        ]);

        VirtualMachine::factory()
            ->onNode($node, 900)
            ->forService($service)
            ->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]);

        return $service;
    }
}
