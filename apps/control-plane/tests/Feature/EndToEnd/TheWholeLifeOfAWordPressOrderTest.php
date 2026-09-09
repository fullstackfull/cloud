<?php

declare(strict_types=1);

namespace Tests\Feature\EndToEnd;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\SharedHosting\Application\Actions\OrderWordPressSite;
use Lynomia\Modules\SharedHosting\Application\Actions\VerifyWordPressSites;
use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Probes\FakeSiteProbe;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ordering a WordPress site and ending up with one that answers.
 *
 * ===========================================================================
 * THE DEFECT THIS FILE WAS WRITTEN TO CATCH, AND CAUGHT
 * ===========================================================================
 *
 * Every piece of the WordPress feature existed — the provider contract, the
 * fake, the install handler, the job kind, the state machine, the portal
 * screen, the sweep that verifies a site — and nothing joined them. A customer
 * could order a site, pay for it, watch the hosting account appear, and the
 * site would sit at "requested" for ever because nothing was ever going to
 * install anything.
 *
 * Both architecture gates were green throughout: the handler is registered in
 * a service provider, which looks like a caller to any textual search. It took
 * walking the customer's path end to end to see it.
 */
final class TheWholeLifeOfAWordPressOrderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private HostingNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // One panel for the whole test: the fake holds its accounts per
        // instance, and the container does not bind the factory as a singleton
        // in production because the real adapters hold no per-node state.
        $this->app->singleton(HostingProviderFactory::class);
        $this->app->bind(SiteProbe::class, FakeSiteProbe::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $this->node = HostingNode::factory()
            ->panel(HostingPanel::Fake)
            ->diskUsedPercent(10)
            ->withAccounts(1)
            ->create();
    }

    #[Test]
    public function a_customer_orders_a_site_pays_for_it_and_ends_up_with_one_that_answers(): void
    {
        $plan = $this->hostingPlan();

        $site = app(OrderWordPressSite::class)->execute(
            $this->customer,
            'mywholesite.test',
            WordPressDomainSource::External,
            (string) $plan->getKey(),
            'sitemanager',
            'owner@mywholesite.test',
        );

        // Nothing is built. The order exists and the invoice is waiting.
        $this->assertSame(WordPressSiteState::AwaitingDns, $site->state);
        $this->assertNotNull($site->order_id);

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $site->order_id)->sole();

        $this->pay($invoice);

        $built = $site->fresh();
        $this->assertInstanceOf(WordPressSite::class, $built);

        /*
         * One settlement did all of it: the hosting account was created, the
         * event dispatched the install, and the install ran. This is the
         * assertion that would have failed before the listener existed.
         */
        $this->assertNotNull($built->hosting_account_id);
        $this->assertNotNull(HostingAccount::query()->find($built->hosting_account_id));
        $this->assertTrue($built->installed);

        // Installed and not yet verified — the installer's word, not the
        // platform's.
        $this->assertSame(WordPressSiteState::AwaitingCertificate, $built->state);
        $this->assertNull($built->verified_at);

        $this->assertSame(1, app(VerifyWordPressSites::class)->execute()['verified']);

        $live = $site->fresh();
        $this->assertSame(WordPressSiteState::Ready, $live?->state);
        $this->assertNotNull($live->verified_at);
        $this->assertNotNull($live->site_url);
    }

    #[Test]
    public function the_generated_wordpress_password_is_never_written_to_the_site(): void
    {
        $plan = $this->hostingPlan();

        $site = app(OrderWordPressSite::class)->execute(
            $this->customer,
            'secrets.test',
            WordPressDomainSource::External,
            (string) $plan->getKey(),
            'sitemanager',
            'owner@secrets.test',
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $site->order_id)->sole();
        $this->pay($invoice);

        /*
         * The password exists in the job payload, goes to the toolkit, and
         * reaches nothing else. A copy on the site row would be every
         * customer's site credentials in one table, protecting nothing a
         * password reset does not already protect.
         */
        $row = (array) DB::table('wordpress_sites')
            ->where('id', $site->getKey())
            ->first();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                'password',
                (string) $column,
                'The site row has a column that looks like it holds a password.',
            );
        }
    }

    private function pay(Invoice $invoice): void
    {
        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);
    }

    private function hostingPlan(): Plan
    {
        $product = Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'wordpress-starter-'.uniqid(),
            'resources' => [
                'disk_quota_mib' => 10_240,
                'bandwidth_quota_mib' => 512_000,
                'max_addon_domains' => 10,
                'max_databases' => 10,
            ],
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
        ]);

        HostingPackage::factory()->create([
            'plan_id' => $plan->getKey(),
            'slug' => 'pkg-wordpress-'.uniqid(),
            'panel_package_name' => 'lyn_wordpress',
            'disk_quota_mib' => 10_240,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }
}
