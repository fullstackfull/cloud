<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * U-1 for Shared Hosting: a package change is queued under the plan change it
 * delivers, not under the customer's Idempotency-Key.
 *
 * Measured by the re-audit after round five: downgrade big -> small with key
 * K, upgrade back (paid), downgrade again with K. The second downgrade was
 * handed the first one's completed job, so the panel stayed on the big
 * package while the subscription billed the small plan and the downgrade's
 * credit was posted.
 */
final class AHostingPlanChangeIsQueuedUnderItsOwnKeyTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    private ?Product $hostingProduct = null;

    #[Test]
    public function a_key_reused_on_a_later_downgrade_moves_the_account_back_to_the_small_package(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $small = $this->tier('small', 1_500);
        $big = $this->tier('big', 4_000);
        $order = $this->buySharedHosting($customer, $big);
        $account = HostingAccount::query()->where('service_id', Service::query()->where('order_id', $order->getKey())->sole()->getKey())->sole();
        $subscription = Subscription::query()->where('customer_id', $customer->getKey())->sole();

        $this->change($user, $subscription, $small, 'reused-customer-key-1');
        $this->assertSame('lyn_small', $this->atThePanel($account->username));

        $this->change($user, $subscription, $big, 'fresh-upgrade-key-1');
        $upgrade = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->create([
            'customer_id' => $customer->getKey(), 'invoice_id' => $upgrade->getKey(), 'amount_minor' => $upgrade->total_minor, 'currency' => 'KWD',
        ]));
        $this->assertSame('lyn_big', $this->atThePanel($account->username));

        $this->change($user, $subscription, $small, 'reused-customer-key-1');

        $this->assertSame((string) $small->getKey(), (string) $subscription->fresh()?->plan_id);
        $this->assertSame(3, ProvisioningJob::query()->where('kind', ProvisioningJobKind::ChangeHostingPackage->value)->count(), 'The second downgrade was handed the first one\'s job.');
        $this->assertSame('lyn_small', $this->atThePanel($account->username), 'The account stayed on the big package while billed as the small plan.');
    }

    private function change(User $user, Subscription $subscription, Plan $to, string $key): void
    {
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', [
                'plan_id' => (string) $to->getKey(),
                'price_id' => (string) $to->prices()->firstOrFail()->getKey(),
            ])->assertOk();
    }

    private function tier(string $tier, int $minor): Plan
    {
        $this->sharedHostingNode();
        $this->hostingProduct ??= Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $this->hostingProduct->getKey(),
            'slug' => 'h-'.$tier.'-'.uniqid(),
            'resources' => ['disk_quota_mib' => 10_240, 'bandwidth_quota_mib' => 512_000, 'max_addon_domains' => $minor, 'max_databases' => 10],
            'is_active' => true,
            'is_public' => true,
        ]);
        PlanPrice::factory()->create(['plan_id' => $plan->getKey(), 'currency' => 'KWD', 'billing_period' => BillingPeriod::Monthly, 'recurring_amount_minor' => $minor, 'setup_amount_minor' => 0]);
        HostingPackage::factory()->create(['plan_id' => $plan->getKey(), 'slug' => 'pkg-'.$tier.'-'.uniqid(), 'panel_package_name' => 'lyn_'.$tier, 'disk_quota_mib' => 10_240, 'is_active' => true]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }

    private function atThePanel(string $username): ?string
    {
        $node = $this->sharedHostingNode();

        foreach (app(HostingProviderFactory::class)->for($node)->listAccounts($node) as $remote) {
            if ($remote->username === $username) {
                return $remote->packageName;
            }
        }

        return null;
    }
}
