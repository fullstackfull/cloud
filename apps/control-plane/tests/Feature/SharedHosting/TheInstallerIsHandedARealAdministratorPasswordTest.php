<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

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
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Application\Actions\OrderWordPressSite;
use Lynomia\Modules\SharedHosting\Application\Handlers\InstallWordPressHandler;
use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Probes\FakeSiteProbe;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingWordPressInstaller;
use Tests\TestCase;

/**
 * The password a WordPress site is installed with is one the platform minted,
 * not the redactor's placeholder (F-45).
 *
 * ===========================================================================
 * THE DEFECT
 * ===========================================================================
 *
 * The listener that queues the install minted the administrator password and
 * put it in the job's payload. That column is cast through RedactedJsonCast,
 * whose redactor matches `password` inside `admin_password`, so the value was
 * replaced by the ten characters `[redacted]` on the way into the row — and the
 * handler read those back and handed them to the installer. Every site built
 * by this path would have shared one publicly known administrator password,
 * and the install reported success.
 *
 * The cast was doing its job, and still is. What was wrong was a credential in
 * a persisted payload, and a handler that sent on whatever it read. So the
 * handler now mints the password itself, and refuses a job whose payload
 * carries `admin_password` at all.
 *
 * Nothing here can be seen on a row afterwards — the password is kept nowhere,
 * by design — so the installer is wrapped in a recorder, and what it was handed
 * is what these tests read.
 */
final class TheInstallerIsHandedARealAdministratorPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private HostingNode $node;

    private RecordingWordPressInstaller $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->app->singleton(HostingProviderFactory::class);
        $this->app->bind(SiteProbe::class, FakeSiteProbe::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $this->node = HostingNode::factory()
            ->panel(HostingPanel::Fake)
            ->diskUsedPercent(10)
            ->withAccounts(1)
            ->create();

        $this->panel = new RecordingWordPressInstaller(new FakeHostingProvider);
        app(HostingProviderFactory::class)->swap($this->node, $this->panel);
    }

    #[Test]
    public function a_paid_wordpress_order_hands_the_installer_a_password_and_not_the_placeholder(): void
    {
        /*
         * Through the production path end to end — the order, the settlement,
         * the account build, the listener that queues the install, and the
         * handler — with no hand-built payload anywhere. A fixture could only
         * describe a payload; this is the one the platform actually writes.
         */
        $site = $this->orderAndPay('handedover.test');

        $this->assertCount(1, $this->panel->installs, 'The paid order never reached the installer.');

        $password = $this->panel->installs[0]->adminPassword;

        $this->assertNotSame(
            SecretRedactor::PLACEHOLDER,
            $password,
            "the installer was handed the redactor's placeholder as the administrator password",
        );
        $this->assertSame(InstallWordPressHandler::ADMIN_PASSWORD_LENGTH, strlen($password));

        $this->assertTrue($site->fresh()?->installed);
    }

    #[Test]
    public function the_install_job_a_paid_order_queues_carries_no_administrator_password(): void
    {
        $this->orderAndPay('nopayload.test');

        $row = DB::table('provisioning_jobs')
            ->where('kind', ProvisioningJobKind::InstallWordPress->value)
            ->sole();

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);

        /*
         * Not the credential, and not the placeholder either: the key itself
         * is absent. A payload is written once and read back by whichever
         * worker runs it, so a credential in one is either kept in a table or
         * destroyed on the way in, and both have happened to this platform.
         */
        $this->assertArrayNotHasKey('admin_password', $payload);

        // What the install needs is all still there.
        $this->assertArrayHasKey('wordpress_site_id', $payload);
        $this->assertArrayHasKey('admin_username', $payload);
        $this->assertArrayHasKey('admin_email', $payload);
    }

    #[Test]
    public function a_job_whose_payload_carries_the_administrator_password_fails_loudly_and_installs_nothing(): void
    {
        $site = $this->siteOn('placeholder.test');

        $job = $this->installJobFor($site, 'Wp-minted-at-dispatch-4c1e');

        /*
         * The premise, measured rather than assumed: the cast leaves the
         * placeholder in the row, which is what the handler reads.
         */
        $this->assertSame(SecretRedactor::PLACEHOLDER, $this->storedAdminPassword($job));

        $result = app(InstallWordPressHandler::class)->execute($job);

        /*
         * RedactedJsonCast promises that a handler receiving "[redacted]"
         * fails loudly. Before this guard it installed and succeeded.
         */
        $this->assertTrue($result->isFailure(), 'the handler installed with the placeholder and reported success');
        $this->assertSame('wordpress.credential_in_payload', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);

        $this->assertSame([], $this->panel->installs, 'the installer was called with a job the handler should have refused');

        $untouched = $site->fresh();
        $this->assertFalse($untouched?->installed);
        $this->assertSame(WordPressSiteState::Requested, $untouched->state);
    }

    #[Test]
    public function a_live_credential_in_the_payload_is_refused_as_well_as_the_placeholder(): void
    {
        /*
         * The redaction list is a literal in config/security.php with no
         * environment hook, so this is not a deployment setting: it is the day
         * somebody edits that list. On that day `admin_password` stops
         * matching, a credential written to a payload lands in the jsonb
         * column in the clear, and a guard that refused only the placeholder
         * or a blank would install with it. That is the worse case, and the
         * one RedactedJsonCast exists to prevent — so the handler refuses the
         * key, whatever it holds.
         */
        $keys = config('security.redacted_keys');
        $this->assertIsArray($keys);
        $this->assertContains('password', $keys);

        /** @var list<string> $keys */
        $this->app->instance(SecretRedactor::class, new SecretRedactor(
            array_values(array_filter($keys, static fn (string $key): bool => $key !== 'password')),
        ));

        $live = 'Wp-live-credential-8d2f0a';
        $site = $this->siteOn('liveone.test');
        $job = $this->installJobFor($site, $live);

        // The premise: with the list edited, the credential really is in the row.
        $this->assertSame($live, $this->storedAdminPassword($job));

        $result = app(InstallWordPressHandler::class)->execute($job);

        $this->assertTrue($result->isFailure(), 'the handler installed with a credential it read from a persisted payload');
        $this->assertSame('wordpress.credential_in_payload', $result->errorCode);
        $this->assertSame([], $this->panel->installs, 'the installer was handed a credential from a persisted payload');

        // And the refusal does not repeat the credential it refused.
        $this->assertStringNotContainsString($live, (string) $result->errorMessage);
        $this->assertStringNotContainsString($live, (string) json_encode($result->metadata, JSON_THROW_ON_ERROR));
    }

    private function orderAndPay(string $domain): WordPressSite
    {
        $plan = $this->hostingPlan();

        $site = app(OrderWordPressSite::class)->execute(
            $this->customer,
            $domain,
            WordPressDomainSource::External,
            (string) $plan->getKey(),
            'sitemanager',
            'owner@'.$domain,
        );

        /** @var Invoice $invoice */
        $invoice = Invoice::query()->where('order_id', $site->order_id)->sole();

        $transaction = Transaction::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $transaction);

        return $site;
    }

    private function siteOn(string $domain): WordPressSite
    {
        $username = 'wpguard'.substr(md5($domain), 0, 6);

        $this->panel->createAccount($this->node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $domain,
            password: 'panel-password-not-stored',
            packageName: 'starter',
            contactEmail: 'owner@'.$domain,
        ));

        $account = HostingAccount::factory()->named($username)->create([
            'customer_id' => $this->customer->getKey(),
            'hosting_node_id' => $this->node->getKey(),
            'primary_domain' => $domain,
        ]);

        return WordPressSite::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'hosting_account_id' => $account->getKey(),
            'domain' => $domain,
            'domain_source' => WordPressDomainSource::Existing,
            'state' => WordPressSiteState::Requested,
            'admin_username' => 'sitemanager',
        ]);
    }

    /**
     * An install job built the way the listener used to build one.
     */
    private function installJobFor(WordPressSite $site, string $adminPassword): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => ProvisioningJobKind::InstallWordPress,
            'payload' => [
                'wordpress_site_id' => (string) $site->getKey(),
                'admin_username' => 'sitemanager',
                'admin_password' => $adminPassword,
                'admin_email' => 'owner@'.$site->domain,
                'site_title' => $site->domain,
            ],
        ]);
    }

    /**
     * What the column holds under `admin_password`, read past the model.
     */
    private function storedAdminPassword(ProvisioningJob $job): mixed
    {
        $raw = DB::table('provisioning_jobs')->where('id', $job->getKey())->value('payload');

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);

        return $payload['admin_password'] ?? null;
    }

    private function hostingPlan(): Plan
    {
        $product = Product::factory()->create(['kind' => 'shared_hosting']);

        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => 'wordpress-guard-'.uniqid(),
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
            'slug' => 'pkg-wordpress-guard-'.uniqid(),
            'panel_package_name' => 'lyn_wordpress',
            'disk_quota_mib' => 10_240,
        ]);

        return $plan->fresh(['prices', 'product']) ?? $plan;
    }
}
