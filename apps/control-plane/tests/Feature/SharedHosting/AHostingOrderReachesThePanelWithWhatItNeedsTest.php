<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Application\Actions\ProvisionOrderedService;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingHostingProvider;
use Tests\TestCase;

/**
 * F-04: what a real shared-hosting order hands the panel.
 *
 * The audit's sentence, clause by clause: *"A real shared-hosting order
 * carries no password, no contact email and a `.invalid` primary domain to the
 * panel."* Fulfilment built the job from the plan's resources and the package
 * id and nothing else, so the handler fell back three times — to `''` for the
 * password, `''` for the contact address and `<username>.hosting.invalid` for
 * the domain — and the controlled panel, which read none of them, answered
 * "created" to all three.
 *
 * Every assertion here is on what the panel was HANDED, recorded by a
 * decorator over the controlled provider. That is the one place the defect is
 * observable: the account row carries no password by design, and a row whose
 * domain says `.invalid` still reports `active`.
 */
final class AHostingOrderReachesThePanelWithWhatItNeedsTest extends TestCase
{
    use RefreshDatabase;

    private RecordingHostingProvider $panel;

    private HostingNode $node;

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

        $this->panel = new RecordingHostingProvider(new FakeHostingProvider);
        app(HostingProviderFactory::class)->swap($this->node, $this->panel);
    }

    #[Test]
    public function the_panel_is_handed_the_domain_that_was_ordered_a_contact_address_and_a_real_password(): void
    {
        [$customer, $user] = $this->customerWithOwner();
        $plan = $this->hostingPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-through-checkout')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $plan->id, 'quantity' => 1, 'domain' => 'Shop.Example.Test.']],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertCreated();

        $this->fulfil(Order::query()->where('customer_id', $customer->getKey())->sole());

        $this->assertCount(1, $this->panel->creates, 'the account was never asked for');
        $request = $this->panel->creates[0];

        // The name the customer bought it for, folded once, and never `.invalid`.
        $this->assertSame('shop.example.test', $request->primaryDomain);
        $this->assertStringEndsNotWith('.invalid', $request->primaryDomain);

        // Somebody the panel can write to about the account.
        $this->assertSame((string) $customer->billing_email, $request->contactEmail);

        // A password, of the length the handler promises, and not a redaction
        // marker that a cast put where the value should have been.
        $this->assertSame(CreateHostingAccountHandler::PASSWORD_LENGTH, strlen($request->password));
        $this->assertStringNotContainsString('redacted', $request->password);

        // Letters and digits only, as PASSWORD_LENGTH promises: a symbol one
        // side of a form body encodes and the other decodes differently is a
        // password nobody can type back.
        $this->assertMatchesRegularExpression(
            sprintf('/\A[A-Za-z0-9]{%d}\z/', CreateHostingAccountHandler::PASSWORD_LENGTH),
            $request->password,
        );

        $account = HostingAccount::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame(HostingAccountStatus::Active, $account->status);
        $this->assertSame('shop.example.test', $account->primary_domain);
    }

    #[Test]
    public function the_password_the_panel_was_handed_is_kept_nowhere_the_platform_writes(): void
    {
        [$customer, $user] = $this->customerWithOwner();
        $plan = $this->hostingPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-kept-nowhere')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $plan->id, 'quantity' => 1, 'domain' => 'kept-nowhere.example.test']],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertCreated();

        $this->fulfil(Order::query()->where('customer_id', $customer->getKey())->sole());

        $password = $this->panel->creates[0]->password ?? '';
        $this->assertNotSame('', $password);

        foreach (['provisioning_jobs', 'provisioning_attempts', 'hosting_accounts', 'audit_log', 'services'] as $table) {
            $this->assertStringNotContainsString(
                $password,
                DB::table($table)->get()->toJson(),
                'the panel password was written to '.$table,
            );
        }
    }

    #[Test]
    public function a_password_carried_in_the_payload_is_not_the_one_the_panel_is_given(): void
    {
        /*
         * The payload column is cast through the redactor, so a `password` key
         * written there is `[redacted]` by the time a worker reads it — the
         * panel would be handed the redaction marker as a credential. The
         * handler mints its own and reads no password from the job at all.
         */
        $job = $this->job(['primary_domain' => 'minted.example.test', 'password' => 'from-the-payload']);

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->successful);
        $handed = $this->panel->creates[0]->password;
        $this->assertNotSame('from-the-payload', $handed);
        $this->assertNotSame('[redacted]', $handed);
        $this->assertSame(CreateHostingAccountHandler::PASSWORD_LENGTH, strlen($handed));
    }

    #[Test]
    public function every_attempt_is_handed_a_fresh_password(): void
    {
        $first = $this->job(['primary_domain' => 'first.example.test', 'username' => 'firstone']);
        $second = $this->job(['primary_domain' => 'second.example.test', 'username' => 'secondone']);

        app(CreateHostingAccountHandler::class)->execute($first);
        app(CreateHostingAccountHandler::class)->execute($second);

        $this->assertCount(2, $this->panel->creates);
        $this->assertNotSame($this->panel->creates[0]->password, $this->panel->creates[1]->password);
    }

    #[Test]
    public function two_attempts_at_one_job_are_handed_two_different_passwords(): void
    {
        /*
         * One job, attempted twice: the panel refuses the first outright
         * (nothing built, slot handed back) and is asked again. A password
         * derived from anything the job carries — its id, its payload —
         * would be the same both times and predictable by anyone who can
         * read the job; one minted per attempt is not.
         */
        $job = $this->job([
            'primary_domain' => 'twice.example.test',
            'username' => 'x'.FakeHostingProvider::PROVIDER_FAILURE_MARKER,
        ]);

        $first = app(CreateHostingAccountHandler::class)->execute($job);
        $second = app(CreateHostingAccountHandler::class)->execute($job->fresh() ?? $job);

        $this->assertSame(FailureClass::Transient, $first->failureClass);
        $this->assertSame(FailureClass::Transient, $second->failureClass);
        $this->assertCount(2, $this->panel->creates, 'the second attempt never reached the panel');
        $this->assertSame($this->panel->creates[0]->username, $this->panel->creates[1]->username);
        $this->assertNotSame($this->panel->creates[0]->password, $this->panel->creates[1]->password);
    }

    #[Test]
    public function a_job_that_names_no_domain_is_refused_rather_than_built_under_an_invalid_one(): void
    {
        $job = $this->job();

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.domain_missing', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);

        // Nothing reached the panel, nothing was reserved, and nothing carries
        // a provider reference — so the operator's remedy (name the domain,
        // then retry) is not refused by the retry guard.
        $this->assertSame([], $this->panel->creates);
        $this->assertSame(0, HostingAccount::query()->count());
        $this->assertSame(0, $this->node->fresh()?->account_count);
        $this->assertNull($result->providerReference);
    }

    #[Test]
    public function a_job_whose_domain_is_not_a_host_name_is_refused_before_the_panel(): void
    {
        $result = app(CreateHostingAccountHandler::class)->execute(
            $this->job(['primary_domain' => 'https://shop.example.test/']),
        );

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.domain_unusable', $result->errorCode);
        $this->assertSame([], $this->panel->creates);
    }

    #[Test]
    public function a_job_carrying_no_contact_address_takes_the_customers_own(): void
    {
        $customer = Customer::factory()->create(['billing_email' => 'billing@owner.example.test']);

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $customer->getKey(),
            'payload' => [
                'hosting_package_id' => (string) HostingPackage::factory()->create()->getKey(),
                'primary_domain' => 'contact.example.test',
            ],
        ]);

        app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertSame('billing@owner.example.test', $this->panel->creates[0]->contactEmail);
    }

    /**
     * A blank billing address is not an address, and the chain does not stop
     * at it.
     *
     * `??` falls through on null and on nothing else, so an empty or
     * whitespace-only billing address used to be taken as the answer,
     * trimmed to nothing, and the job refused as having no address at all —
     * a paid order failed Permanent, while the owner the chain names next
     * had one.
     */
    #[Test]
    #[DataProvider('billingAddressesThatHoldNoAddress')]
    public function a_blank_billing_address_is_passed_over_for_the_owners(?string $billing): void
    {
        [$customer, $owner] = $this->customerWithOwner();
        $customer->forceFill(['billing_email' => $billing])->save();

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $customer->getKey(),
            'payload' => [
                'hosting_package_id' => (string) HostingPackage::factory()->create()->getKey(),
                'primary_domain' => 'blank-billing.example.test',
                'contact_email' => '   ',
            ],
        ]);

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->successful, (string) $result->errorCode);
        $this->assertNotSame('', $owner->email);
        $this->assertSame($owner->email, $this->panel->creates[0]->contactEmail ?? null);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function billingAddressesThatHoldNoAddress(): array
    {
        return [
            'no billing address' => [null],
            'an empty one' => [''],
            'one of spaces' => ['   '],
        ];
    }

    #[Test]
    public function a_paid_order_on_an_account_whose_billing_address_is_empty_reaches_the_owner(): void
    {
        [$customer, $owner] = $this->customerWithOwner();
        $customer->forceFill(['billing_email' => ''])->save();
        $plan = $this->hostingPlan();

        $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'f04-empty-billing')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $plan->id, 'quantity' => 1, 'domain' => 'empty-billing.example.test']],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertCreated();

        $this->fulfil(Order::query()->where('customer_id', $customer->getKey())->sole());

        $job = ProvisioningJob::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) json_encode($job->result));
        $this->assertSame($owner->email, $this->panel->creates[0]->contactEmail ?? null);
    }

    #[Test]
    public function the_panel_writes_to_the_address_the_order_was_billed_to_not_the_one_on_the_account_now(): void
    {
        [$customer, $user] = $this->customerWithOwner();
        $customer->forceFill(['billing_email' => 'at-checkout@example.test'])->save();
        $plan = $this->hostingPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'f04-billed-then-changed')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $plan->id, 'quantity' => 1, 'domain' => 'billed.example.test']],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertCreated();

        // The account's billing address moves after the purchase and before
        // the build. The purchase was billed to the old one.
        $customer->forceFill(['billing_email' => 'changed-later@example.test'])->save();

        $this->fulfil(Order::query()->where('customer_id', $customer->getKey())->sole());

        $this->assertSame('at-checkout@example.test', $this->panel->creates[0]->contactEmail ?? null);
    }

    /**
     * Nothing anywhere to write to: refused, before anything is placed,
     * reserved or handed to the panel — rather than built with an empty
     * contact address, which is the audit's own clause.
     */
    #[Test]
    #[DataProvider('billingAddressesThatHoldNoAddress')]
    public function a_job_with_no_address_anywhere_is_refused_before_the_panel(?string $billing): void
    {
        // No member at all, so no owner to fall back to.
        $customer = Customer::factory()->create(['billing_email' => $billing]);

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $customer->getKey(),
            'payload' => [
                'hosting_package_id' => (string) HostingPackage::factory()->create()->getKey(),
                'primary_domain' => 'nobody.example.test',
            ],
        ]);

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.contact_email_missing', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame([], $this->panel->creates);
        $this->assertSame(0, HostingAccount::query()->count());
        $this->assertSame(0, $this->node->fresh()?->account_count);
        $this->assertNull($result->providerReference);
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * Fulfilment, as the paid order would drive it, with the queue running
     * the job in-process.
     */
    private function fulfil(Order $order): void
    {
        foreach ($order->items as $item) {
            app(ProvisionOrderedService::class)->execute($order, $item);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function job(array $payload = []): ProvisioningJob
    {
        return ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'payload' => [
                'hosting_package_id' => (string) HostingPackage::factory()->create()->getKey(),
                ...$payload,
            ],
        ]);
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function customerWithOwner(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    private function hostingPlan(): Plan
    {
        $product = Product::factory()->create(['kind' => ProductKind::SharedHosting->value]);
        $plan = Plan::factory()->create(['product_id' => $product->getKey()]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 4_500,
            'setup_amount_minor' => 0,
        ]);

        HostingPackage::factory()->create(['plan_id' => $plan->getKey()]);

        return $plan->fresh(['prices', 'product']);
    }
}
