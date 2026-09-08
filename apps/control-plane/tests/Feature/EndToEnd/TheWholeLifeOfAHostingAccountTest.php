<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncAccountUsage;
use Lynomia\Modules\SharedHosting\Application\Actions\TerminateHostingAccount;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One hosting account, from the order that paid for it to the panel deleting
 * it.
 *
 * Two failures were only visible from this height, and both had the same
 * shape: a step the platform described and did not perform.
 *
 * A shared hosting order queued a job with no package in it, because nothing
 * resolved the catalogue's plan-to-package mapping — the customer paid, the
 * worker answered "no hosting package exists with the id ''", and no account
 * was ever created. And a hosting plan change moved the money and nothing
 * else: `changePackage` was implemented in both panel adapters, tested against
 * recorded HTTP exchanges, and called by nothing, so an upgraded customer kept
 * their old disk quota for ever.
 *
 * The panel is the fake. Everything above it is the real thing.
 */
final class TheWholeLifeOfAHostingAccountTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    private HostingNode $node;

    private ?Product $product = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        /*
         * One factory for the whole test. The container does not bind it as a
         * singleton in production — the real adapters hold no per-node state —
         * but the fake panel does, so without this each step would talk to a
         * different panel with no accounts on it.
         */
        $this->app->singleton(HostingProviderFactory::class);

        $this->node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'hostname' => 'shared-node-under-test.lynomia.test',
        ]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);
    }

    #[Test]
    public function a_customer_buys_hosting_upgrades_it_lapses_and_leaves(): void
    {
        // ---------------------------------------------------------------
        // Bought and opened
        // ---------------------------------------------------------------
        $starter = $this->hostingPlan('starter', diskMib: 10_240, monthlyMinor: 1_500);

        $service = $this->buy($starter);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);

        $account = HostingAccount::query()->where('service_id', $service->getKey())->sole();

        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertSame(
            (string) $this->packageOf($starter)->getKey(),
            (string) $account->hosting_package_id,
            'The account was opened on a package nobody chose.',
        );

        // The panel really has it: a row with no account behind it is the
        // failure this whole file exists to catch.
        $this->assertNotNull($this->atThePanel($account->username));

        $this->assertTrue(
            Notification::query()
                ->where('customer_id', $this->customer->getKey())
                ->where('type', NotificationType::ServiceReady)
                ->exists(),
        );

        // ---------------------------------------------------------------
        // Usage, read from the panel
        // ---------------------------------------------------------------
        $this->assertTrue(app(SyncAccountUsage::class)->execute($account->refresh()));
        $this->assertNotNull($account->refresh()->usage_synced_at);

        // ---------------------------------------------------------------
        // Upgraded — and the panel is told
        // ---------------------------------------------------------------
        $business = $this->hostingPlan('business', diskMib: 51_200, monthlyMinor: 4_000);

        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'hosting-life-upgrade-1')
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', [
                'plan_id' => (string) $business->getKey(),
                'price_id' => (string) $business->prices()->firstOrFail()->getKey(),
            ])
            ->assertOk();

        $this->assertSame((string) $business->getKey(), (string) $subscription->refresh()->plan_id);

        // Both halves. The money moved when the customer confirmed; the quota
        // is what they actually bought.
        $this->assertSame(
            (string) $this->packageOf($business)->getKey(),
            (string) $account->refresh()->hosting_package_id,
        );

        $this->assertSame(
            'lyn_business',
            $this->atThePanel($account->username)?->packageName,
            'The customer was charged for an upgrade the panel never heard about.',
        );

        // ---------------------------------------------------------------
        // Payment fails, and the account is switched off at the panel
        // ---------------------------------------------------------------
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::PastDue);
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Suspended);

        $this->assertSame(ServiceStatus::Suspended, $service->refresh()->status);
        $this->assertSame(HostingAccountStatus::Suspended, $account->refresh()->status);
        $this->assertTrue(
            $this->atThePanel($account->username)?->suspended,
            'The service was marked suspended and the site kept serving traffic.',
        );

        // ---------------------------------------------------------------
        // They pay, and it comes back
        // ---------------------------------------------------------------
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Active);

        $this->assertSame(ServiceStatus::Active, $service->refresh()->status);
        $this->assertSame(HostingAccountStatus::Active, $account->refresh()->status);
        $this->assertFalse($this->atThePanel($account->username)?->suspended);

        // ---------------------------------------------------------------
        // Somebody changes the account at the panel, and the sweep notices
        // ---------------------------------------------------------------
        /*
         * Real drift, injected the way it really happens: a person suspends
         * an account directly at the panel during an incident and nobody
         * tells the platform. The row goes on saying "active" and the
         * customer's site goes on being off, and until this sweep existed
         * nothing anywhere would ever have compared the two.
         */
        app(HostingProviderFactory::class)
            ->for($this->node)
            ->suspendAccount($this->node, $account->username, 'suspended at the panel by hand');

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['nodes']);

        $drift = ResourceDrift::query()
            ->where('kind', DriftKind::SuspensionMismatch->value)
            ->where('provider_reference', $account->username)
            ->firstOrFail();

        $this->assertSame('critical', $drift->severity->value);

        // Reported and not repaired. Whichever way the sweep guessed, half the
        // time it would be switching off a customer who has paid.
        $this->assertTrue($this->atThePanel($account->username)?->suspended);
        $this->assertSame(HostingAccountStatus::Active, $account->refresh()->status);

        // Put back by hand, as an operator would, so the rest of the story is
        // about what the customer does rather than about the drift.
        app(HostingProviderFactory::class)
            ->for($this->node)
            ->unsuspendAccount($this->node, $account->username);

        // ---------------------------------------------------------------
        // They leave
        // ---------------------------------------------------------------
        app(TransitionSubscription::class)->execute($subscription->refresh(), SubscriptionStatus::Suspended);

        // The retention window is a month, and it is the only thing between a
        // late invoice and a deleted website.
        $this->travel(31)->days();

        app(TerminateHostingAccount::class)->execute($account->refresh());

        $this->assertSame(HostingAccountStatus::Terminated, $account->refresh()->status);
        $this->assertNull(
            $this->atThePanel($account->username),
            'The account was marked terminated and the panel still has it.',
        );

        // And the node's slot is free for the next customer.
        $this->assertSame(0, $this->node->refresh()->account_count);
    }

    private function buy(Plan $plan): Service
    {
        $order = app(PlaceOrder::class)->execute(
            $this->customer,
            new CheckoutRequest(
                lines: [new CheckoutLine((string) $plan->getKey(), 1)],
                billingPeriod: BillingPeriod::Monthly,
                couponCode: null,
                idempotencyKey: null,
            ),
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $order->getKey())->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);

        /** @var Service $service */
        $service = Service::query()->where('order_id', $order->getKey())->sole();

        return $service;
    }

    /**
     * A hosting plan and the panel package that goes with it.
     *
     * Both, always: a plan without a package cannot be bought at all, which is
     * the defect this file found, and a fixture that made one without the
     * other would hide it again.
     */
    private function hostingPlan(string $slug, int $diskMib, int $monthlyMinor): Plan
    {
        $product = $this->product ??= Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'hosting-'.$slug.'-'.uniqid(),
            'resources' => [
                'disk_quota_mib' => $diskMib,
                'bandwidth_quota_mib' => $diskMib * 50,
                'max_addon_domains' => 10,
                'max_databases' => 10,
            ],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => 0,
        ]);

        HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'slug' => 'pkg-'.$slug.'-'.uniqid(),
            'panel_package_name' => 'lyn_'.$slug,
            'disk_quota_mib' => $diskMib,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }

    private function packageOf(Plan $plan): HostingPackage
    {
        return HostingPackage::query()->where('plan_id', $plan->getKey())->sole();
    }

    private function panel(): FakeHostingProvider
    {
        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node);

        return $panel;
    }

    /**
     * What the panel itself says about this account, read through its own
     * listing rather than from the platform's row.
     */
    private function atThePanel(string $username): ?RemoteAccount
    {
        foreach ($this->panel()->listAccounts($this->node) as $account) {
            if ($account->username === $username) {
                return $account;
            }
        }

        return null;
    }
}
