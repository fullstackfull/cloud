<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Application\Services\LocalPlacementFeasibility;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Application\Queries\HostingPackageForPlan;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\TestCase;

/**
 * F-32: which hosting package a plan is sold under is chosen, not whatever
 * row the heap hands back first.
 *
 * `hosting_packages.slug` is unique and `plan_id` is not. Withdrawing a
 * package is `is_active = false` and nothing ever deletes one, so replacing
 * the package behind a plan leaves two rows naming it. Checkout, the build and
 * a plan change each asked `where('plan_id', …)->first()` — no `is_active`, no
 * `ORDER BY` — so the package a customer got was a fact about the physical
 * order of the table: a paid order could open its account on the withdrawn
 * package and report the old quota, and a paid upgrade could tell the panel
 * the legacy package.
 *
 * Much of what follows is built the way a customer does it — checkout, a
 * settled invoice, the fake panel — because the wrong answer only costs money
 * at the end of that chain. Where a plan's package was replaced, the withdrawn
 * one is written first, as it would have been, so on an unfixed tree a scan
 * meets it first. The test about determinism writes them the other way round
 * and then moves a row, so that the heap and the right answer disagree.
 */
final class TheHostingPackageBehindAPlanIsChosenNotStumbledOnTest extends TestCase
{
    use BuysSharedHosting;
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    private ?Product $product = null;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        Log::listen(function (MessageLogged $event): void {
            $this->logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
    }

    // -----------------------------------------------------------------
    // Where it costs money
    // -----------------------------------------------------------------

    #[Test]
    public function a_paid_order_opens_the_account_on_the_package_on_sale_not_the_one_withdrawn(): void
    {
        [$plan, $legacy, $current] = $this->replacedPlan('business', legacyMib: 10_240, currentMib: 51_200);

        $order = $this->buySharedHosting($this->customer, $plan);

        $account = $this->accountFor($order);

        $this->assertSame(
            (string) $current->getKey(),
            (string) $account->hosting_package_id,
            sprintf(
                'The account was opened on "%s", the package withdrawn when "%s" replaced it.',
                $legacy->panel_package_name,
                $current->panel_package_name,
            ),
        );
        $this->assertSame(51_200, $account->package()->firstOrFail()->disk_quota_mib);
        $this->assertSame('lyn_business', $this->atThePanel($account->username));
    }

    #[Test]
    public function a_paid_upgrade_tells_the_panel_the_package_on_sale_not_the_one_withdrawn(): void
    {
        $starter = $this->sharedHostingPlanUnder('starter', 10_240, 1_500);
        $account = $this->accountFor($this->buySharedHosting($this->customer, $starter));

        [$business, , $current] = $this->replacedPlan('business', legacyMib: 10_240, currentMib: 51_200, monthlyMinor: 4_000);

        $this->upgradeAndSettle($business, 'f32-upgrade-replaced');

        $this->assertSame((string) $current->getKey(), (string) $account->refresh()->hosting_package_id);
        $this->assertSame(
            'lyn_business',
            $this->atThePanel($account->username),
            'The customer paid for the business tier and the panel was told the package it replaced.',
        );
    }

    #[Test]
    public function the_heap_does_not_decide(): void
    {
        /*
         * The same two rows in both physical orders. An UPDATE writes a new
         * row version after the old one, so touching the package on sale puts
         * the withdrawn one first for a sequential scan — which is the whole
         * of what `first()` without an `ORDER BY` consulted.
         *
         * Index scans are switched off for this transaction first. With the
         * plan_id index in place, a table with no statistics (every table a
         * test run migrates) is read through the index, and an UPDATE that
         * touches no indexed column is HOT: the index entry keeps pointing at
         * the row's original slot, so an index scan answers in the original
         * order and this demonstration would show nothing. The migration that
         * adds the index says the same.
         */
        DB::statement('set local enable_indexscan = off');
        DB::statement('set local enable_bitmapscan = off');
        DB::statement('set local enable_indexonlyscan = off');

        $plan = $this->planUnder('business', 51_200, 4_000);
        $current = $this->packageFor($plan, 'lyn_business', 51_200, onSale: true);
        $this->packageFor($plan, 'lyn_business_legacy', 10_240, onSale: false);

        $asked = fn (): string => (string) app(LocalPlacementFeasibility::class)->resolve($plan)->values['hosting_package_id'];

        $this->assertSame((string) $current->getKey(), $asked());

        DB::table('hosting_packages')->where('id', $current->getKey())->update(['updated_at' => now()->addSecond()]);

        $this->assertSame(
            'lyn_business_legacy',
            DB::selectOne('select panel_package_name from hosting_packages where plan_id = ? limit 1', [(string) $plan->getKey()])->panel_package_name,
            'The rewrite did not put the withdrawn package first in the heap, so this test would prove nothing.',
        );

        $this->assertSame(
            (string) $current->getKey(),
            $asked(),
            'Rewriting a row changed which package the plan is sold under.',
        );
    }

    #[Test]
    public function checkout_refuses_a_plan_with_two_packages_on_sale_and_tells_the_operator_why(): void
    {
        $plan = $this->planUnder('business', 51_200, 4_000);
        $this->packageFor($plan, 'lyn_business', 51_200, onSale: true);
        $this->packageFor($plan, 'lyn_business_too', 20_480, onSale: true);

        $basket = [
            'items' => [['plan_id' => (string) $plan->getKey(), 'quantity' => 1, 'domain' => 'two-on-sale.example.test']],
            'billing_period' => BillingPeriod::Monthly->value,
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/orders/quote', $basket)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.not_deliverable');

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', 'f32-two-on-sale')
            ->postJson('/api/v1/orders', $basket)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.not_deliverable');

        $this->assertSame(0, Order::query()->count(), 'An order was placed for a plan nobody can say which package it sells.');

        $warning = $this->warningsAbout((string) $plan->getKey());

        $this->assertNotSame([], $warning, 'The refusal left the operator nothing to act on.');
        $this->assertStringContainsString('more than one hosting package on sale', (string) $warning[0]['context']['reason']);
    }

    // -----------------------------------------------------------------
    // The resolver's answers
    // -----------------------------------------------------------------

    #[Test]
    public function a_plan_never_mapped_is_told_from_one_whose_packages_were_all_withdrawn(): void
    {
        /*
         * Three plans and two packages, so the table is not empty when the
         * never-mapped plan is asked about. On an empty table "has this plan
         * any package?" and "has any plan any package?" have the same answer,
         * and a probe that lost its `plan_id` narrowing would pass unseen.
         */
        $sold = $this->planUnder('starter', 10_240, 1_500);
        $withdrawn = $this->planUnder('business', 51_200, 4_000);
        $neverMapped = $this->planUnder('agency', 204_800, 9_000);

        $onSale = $this->packageFor($sold, 'lyn_starter', 10_240, onSale: true);
        $this->packageFor($withdrawn, 'lyn_business', 51_200, onSale: false);

        $packages = app(HostingPackageForPlan::class);

        $this->assertSame((string) $onSale->getKey(), (string) $packages->resolve((string) $sold->getKey())->package?->getKey());
        $this->assertSame(HostingPackageForPlan::ALL_WITHDRAWN, $packages->resolve((string) $withdrawn->getKey())->refusal);
        $this->assertSame(HostingPackageForPlan::NAMES_NONE, $packages->resolve((string) $neverMapped->getKey())->refusal);

        $this->assertNotSame(
            $packages->resolve((string) $withdrawn->getKey())->reason,
            $packages->resolve((string) $neverMapped->getKey())->reason,
            'Two different things for an operator to fix were reported in the same words.',
        );
    }

    #[Test]
    public function two_packages_on_sale_for_one_plan_are_refused_in_either_physical_order(): void
    {
        $plan = $this->planUnder('business', 51_200, 4_000);
        $first = $this->packageFor($plan, 'lyn_business', 51_200, onSale: true);
        $second = $this->packageFor($plan, 'lyn_business_too', 20_480, onSale: true);
        $this->packageFor($plan, 'lyn_business_legacy', 10_240, onSale: false);

        $packages = app(HostingPackageForPlan::class);

        foreach ([$first, $second] as $touched) {
            DB::table('hosting_packages')->where('id', $touched->getKey())->update(['updated_at' => now()->addSecond()]);

            $choice = $packages->resolve((string) $plan->getKey());

            $this->assertNull($choice->package, 'One of two packages on sale was picked, and the heap picked it.');
            $this->assertSame(HostingPackageForPlan::NAMES_SEVERAL, $choice->refusal);
        }

        $this->assertStringContainsString('more than one hosting package on sale', $packages->resolve((string) $plan->getKey())->reason);
    }

    #[Test]
    public function no_plan_at_all_names_no_package(): void
    {
        $plan = $this->planUnder('starter', 10_240, 1_500);
        $this->packageFor($plan, 'lyn_starter', 10_240, onSale: true);

        $packages = app(HostingPackageForPlan::class);

        foreach ([null, ''] as $missing) {
            $choice = $packages->resolve($missing);

            $this->assertNull($choice->package);
            $this->assertSame(HostingPackageForPlan::NAMES_NONE, $choice->refusal);
        }

        // The words the blocked-service screen has always shown for this.
        $this->assertSame(
            'the plan names no hosting package, so no panel quota can be applied',
            $packages->resolve(null)->reason,
        );
    }

    #[Test]
    public function a_blank_plan_id_is_refused_before_the_database_is_asked(): void
    {
        /*
         * The separating input for the `$planId === ''` arm: a plan whose id
         * is blank, and a package filed under it. `plan_id = ''` finds that
         * package — on `character(26)` '' and twenty-six blanks compare equal,
         * so it would however the blank was written. A resolver that let ''
         * through to the query would hand this package back while it is on
         * sale, and answer ALL_WITHDRAWN once it is withdrawn. Refused up
         * front, '' names no package both times.
         */
        $package = $this->packageUnderABlankPlan();

        $packages = app(HostingPackageForPlan::class);

        $this->assertNull($packages->resolve('')->package, 'A blank plan id was sold the package filed under a blank plan.');
        $this->assertSame(HostingPackageForPlan::NAMES_NONE, $packages->resolve('')->refusal);

        $package->forceFill(['is_active' => false])->save();

        $this->assertSame(HostingPackageForPlan::NAMES_NONE, $packages->resolve('')->refusal);
    }

    #[Test]
    public function the_blank_plan_fixture_is_one_the_database_really_holds(): void
    {
        /*
         * The premise the test above stands on, measured rather than assumed:
         * the schema accepts a plan whose id is '' and a package filed under
         * it, and a query for `plan_id = ''` finds that package. If it did
         * not, the test above would pass whatever the resolver did with ''.
         *
         * What would falsify this is the schema refusing a blank id — a CHECK
         * on `plans.id` or `hosting_packages.plan_id`, say. Changing the
         * column to `varchar(26)` would not: a row stored as '' still matches
         * `= ''`, and the arm is still the only thing between '' and that row.
         */
        $package = $this->packageUnderABlankPlan();

        $this->assertSame(1, DB::table('plans')->where('id', '')->count());
        $this->assertSame(
            [(string) $package->getKey()],
            HostingPackage::query()->where('plan_id', '')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all(),
        );
    }

    // -----------------------------------------------------------------
    // A plan change, and which of its refusals are said out loud
    // -----------------------------------------------------------------

    #[Test]
    public function an_upgrade_onto_a_plan_with_two_packages_on_sale_leaves_the_quota_and_says_so(): void
    {
        $starter = $this->sharedHostingPlanUnder('starter', 10_240, 1_500);
        $account = $this->accountFor($this->buySharedHosting($this->customer, $starter));
        $starterPackage = (string) $account->hosting_package_id;

        $business = $this->planUnder('business', 51_200, 4_000);
        $this->packageFor($business, 'lyn_business', 51_200, onSale: true);
        $this->packageFor($business, 'lyn_business_too', 20_480, onSale: true);

        $this->upgradeAndSettle($business, 'f32-upgrade-ambiguous');

        $this->assertSame(0, $this->packageChangesQueued(), 'A package change was queued onto one of two packages, chosen by the heap.');
        $this->assertSame($starterPackage, (string) $account->refresh()->hosting_package_id);

        $warning = $this->warningsAbout((string) $business->getKey());

        $this->assertCount(1, $warning, 'The customer paid, the quota did not move, and nothing said so.');
        $this->assertStringContainsString('more than one hosting package on sale', (string) $warning[0]['context']['reason']);
        $this->assertArrayHasKey('subscription_id', $warning[0]['context']);
    }

    #[Test]
    public function a_plan_change_onto_a_plan_never_mapped_stays_silent(): void
    {
        $account = $this->accountFor($this->buySharedHosting($this->customer, $this->sharedHostingPlanUnder('starter', 10_240, 1_500)));
        $target = $this->planUnder('agency', 204_800, 9_000);

        // The refusal this test is named for, measured on a table that is not
        // empty — so it cannot quietly be measuring ALL_WITHDRAWN instead.
        $this->assertGreaterThan(0, HostingPackage::query()->count());
        $this->assertSame(
            HostingPackageForPlan::NAMES_NONE,
            app(HostingPackageForPlan::class)->resolve((string) $target->getKey())->refusal,
        );

        $this->assertNull($this->queuePlanChange($account, $target));
        $this->assertSame([], $this->warningsAbout((string) $target->getKey()));
    }

    #[Test]
    public function a_plan_change_onto_a_plan_whose_packages_were_all_withdrawn_stays_silent(): void
    {
        $account = $this->accountFor($this->buySharedHosting($this->customer, $this->sharedHostingPlanUnder('starter', 10_240, 1_500)));
        $target = $this->planUnder('agency', 204_800, 9_000);
        $this->packageFor($target, 'lyn_agency', 204_800, onSale: false);

        $this->assertSame(
            HostingPackageForPlan::ALL_WITHDRAWN,
            app(HostingPackageForPlan::class)->resolve((string) $target->getKey())->refusal,
        );

        $this->assertNull(
            $this->queuePlanChange($account, $target),
            'A plan change was queued onto a package the operator withdrew.',
        );
        $this->assertSame([], $this->warningsAbout((string) $target->getKey()));
    }

    // -----------------------------------------------------------------
    // The index
    // -----------------------------------------------------------------

    #[Test]
    public function hosting_packages_are_indexed_by_plan(): void
    {
        $definitions = collect(DB::select(
            "select indexdef from pg_indexes where schemaname = current_schema() and tablename = 'hosting_packages'",
        ))->map(fn (object $row): string => (string) $row->indexdef);

        $this->assertTrue(
            $definitions->contains(fn (string $definition): bool => str_ends_with($definition, '(plan_id)')),
            "No index leads with hosting_packages.plan_id:\n  ".$definitions->implode("\n  "),
        );
    }

    // -----------------------------------------------------------------

    /**
     * A plan whose package was replaced: the old one withdrawn, a new one on
     * sale. The withdrawn row is written first, as it would have been.
     *
     * @return array{0: Plan, 1: HostingPackage, 2: HostingPackage}
     */
    private function replacedPlan(string $tier, int $legacyMib, int $currentMib, int $monthlyMinor = 1_500): array
    {
        $plan = $this->planUnder($tier, $currentMib, $monthlyMinor);

        $legacy = $this->packageFor($plan, 'lyn_'.$tier.'_legacy', $legacyMib, onSale: false);
        $current = $this->packageFor($plan, 'lyn_'.$tier, $currentMib, onSale: true);

        return [$plan, $legacy, $current];
    }

    private function sharedHostingPlanUnder(string $tier, int $diskMib, int $monthlyMinor): Plan
    {
        $plan = $this->planUnder($tier, $diskMib, $monthlyMinor);
        $this->packageFor($plan, 'lyn_'.$tier, $diskMib, onSale: true);

        return $plan;
    }

    private function planUnder(string $tier, int $diskMib, int $monthlyMinor): Plan
    {
        $this->sharedHostingNode();

        $product = $this->product ??= Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'hosting-'.$tier.'-'.uniqid(),
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

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }

    private function packageFor(Plan $plan, string $panelName, int $diskMib, bool $onSale): HostingPackage
    {
        return HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'slug' => 'pkg-'.$panelName.'-'.uniqid(),
            'panel_package_name' => $panelName,
            'disk_quota_mib' => $diskMib,
            'is_active' => $onSale,
        ]);
    }

    private function packageUnderABlankPlan(): HostingPackage
    {
        $plan = $this->planUnder('blank', 10_240, 1_500);
        $plan->prices()->delete();

        // Written past Eloquent, whose ULID trait would replace an empty key.
        DB::table('plans')->where('id', $plan->getKey())->update(['id' => '']);

        return HostingPackage::factory()->create([
            'plan_id' => '',
            'slug' => 'pkg-blank-'.uniqid(),
            'panel_package_name' => 'lyn_blank',
            'is_active' => true,
        ]);
    }

    private function accountFor(Order $order): HostingAccount
    {
        $service = Service::query()->where('order_id', $order->getKey())->sole();

        return HostingAccount::query()->where('service_id', $service->getKey())->sole();
    }

    private function upgradeAndSettle(Plan $to, string $idempotencyKey): void
    {
        $subscription = Subscription::query()->where('customer_id', $this->customer->getKey())->sole();

        $this->actingAs($this->user)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/subscriptions/'.$subscription->getKey().'/plan', [
                'plan_id' => (string) $to->getKey(),
                'price_id' => (string) $to->prices()->firstOrFail()->getKey(),
            ])
            ->assertOk();

        // An upgrade is a purchase: the panel hears about it when the
        // proration invoice is paid, not when the button is pressed.
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('subscription_id', $subscription->getKey())->sole();

        app(SettleInvoice::class)->execute($invoice, Transaction::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]));
    }

    private function queuePlanChange(HostingAccount $account, Plan $to): ?ProvisioningJob
    {
        $subscription = Subscription::query()->where('customer_id', $account->customer_id)->sole();

        return app(QueuePlanChangeAtProvider::class)->execute(
            $subscription,
            (string) $to->getKey(),
            PlanResources::fromArray($to->resources),
        );
    }

    private function packageChangesQueued(): int
    {
        return ProvisioningJob::query()->where('kind', ProvisioningJobKind::ChangeHostingPackage->value)->count();
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function warningsAbout(string $planId): array
    {
        return array_values(array_filter(
            $this->logged,
            static fn (array $line): bool => $line['level'] === 'warning'
                && (string) ($line['context']['plan_id'] ?? '') === $planId,
        ));
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
