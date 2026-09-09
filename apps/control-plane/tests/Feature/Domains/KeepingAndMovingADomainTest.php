<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Domains\Application\Actions\SweepDomainLifecycle;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification as AppNotification;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keeping a name, and moving one in.
 *
 * The auto-renew tests are the ones that matter most. `auto_renew` is on by
 * default on every domain this platform sells, and a default nothing acts on
 * is a promise the product does not keep — the customer who finds out is one
 * who has already lost their domain.
 */
final class KeepingAndMovingADomainTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private string $registrarState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrarState = tempnam(sys_get_temp_dir(), 'domains-fake-');

        config([
            'domains.fake.tlds' => ['test'],
            'domains.fake.state_path' => $this->registrarState,
        ]);

        DomainTld::factory()->onSale()->named('test')->create([
            'grace_days' => 30,
            'redemption_days' => 30,
        ]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->owner->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->registrarState);

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function acting(): array
    {
        return ['X-Lynomia-Customer' => (string) $this->customer->getKey()];
    }

    private function heldName(string $name, ?string $expiresAt = null): Domain
    {
        app(DomainRegistrarFactory::class)
            ->make('fake')
            ->register(new RegistrationRequest($name, 1, []));

        return Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => $name,
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'auto_renew' => true,
            'expires_at' => $expiresAt ?? now()->addYear(),
        ]);
    }

    private function pay(Invoice $invoice): void
    {
        $transaction = Transaction::query()->create([
            'customer_id' => $invoice->customer_id,
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
            'provider_reference' => 'pi_'.Str::random(16),
            'processed_at' => now(),
        ]);

        app(PaymentProviderRegistry::class)->get('fake');

        app(SettleInvoice::class)->execute($invoice, $transaction);
    }

    #[Test]
    public function a_renewal_is_invoiced_first_and_extends_the_term_when_it_is_paid(): void
    {
        $domain = $this->heldName('keepme.test');
        $before = $domain->expires_at;

        $quote = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'keepme.test', 'operation' => 'renew'])
            ->assertCreated()
            ->json('data.id');

        $operationId = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/renewals', ['quote_id' => $quote])
            ->assertCreated()
            ->json('data.id');

        $operation = DomainOperation::query()->findOrFail($operationId);

        // Nothing has been renewed. The registry is asked when the money
        // arrives, not when the button is clicked.
        $this->assertSame(DomainOperationState::Requested, $operation->state);
        $this->assertEquals($before, $domain->fresh()?->expires_at);

        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        $this->assertSame(DomainOperationState::Completed, $operation->fresh()?->state);
        $this->assertTrue($domain->fresh()?->expires_at?->greaterThan($before));
    }

    #[Test]
    public function a_second_renewal_cannot_be_queued_while_one_is_in_flight(): void
    {
        $domain = $this->heldName('doubled.test');

        foreach (['first', 'second'] as $attempt) {
            $quote = $this->actingAs($this->owner)
                ->withHeaders($this->acting())
                ->postJson('/api/v1/domains/quotes', ['name' => 'doubled.test', 'operation' => 'renew'])
                ->assertCreated()
                ->json('data.id');

            $response = $this->actingAs($this->owner)
                ->withHeaders($this->acting())
                ->postJson('/api/v1/domains/'.$domain->getKey().'/renewals', ['quote_id' => $quote]);

            /*
             * The second one is refused. Two renewals in flight for one name
             * is two years bought, and registries do not give the extra one
             * back.
             */
            $attempt === 'first'
                ? $response->assertCreated()
                : $response->assertStatus(409);
        }
    }

    #[Test]
    public function auto_renew_orders_a_renewal_before_the_name_lapses(): void
    {
        // Inside the lead time, which is the whole point of the flag.
        $domain = $this->heldName('autorenew.test', now()->addDays(10)->toIso8601String());

        $outcome = app(SweepDomainLifecycle::class)->execute();

        $this->assertSame(1, $outcome['renewals_ordered']);

        $operation = DomainOperation::query()
            ->where('domain_id', $domain->getKey())
            ->where('kind', DomainOperationKind::Renew->value)
            ->firstOrFail();

        // An invoice, not a renewal. The automatic path and the manual one
        // reach the registry through exactly the same listener.
        $this->assertNotNull($operation->invoice_id);
        $this->assertSame(DomainOperationState::Requested, $operation->state);
    }

    #[Test]
    public function auto_renew_left_off_orders_nothing(): void
    {
        $domain = $this->heldName('manual.test', now()->addDays(10)->toIso8601String());
        $domain->forceFill(['auto_renew' => false])->save();

        $this->assertSame(0, app(SweepDomainLifecycle::class)->execute()['renewals_ordered']);
    }

    #[Test]
    public function the_sweep_does_not_order_a_second_renewal_on_its_next_run(): void
    {
        $this->heldName('idempotent.test', now()->addDays(10)->toIso8601String());

        $this->assertSame(1, app(SweepDomainLifecycle::class)->execute()['renewals_ordered']);

        // A sweep that ordered again every night would invoice a customer once
        // a day for thirty days.
        $this->assertSame(0, app(SweepDomainLifecycle::class)->execute()['renewals_ordered']);
    }

    #[Test]
    public function a_customer_is_warned_before_a_name_lapses_whether_or_not_it_auto_renews(): void
    {
        $manual = $this->heldName('warnme.test', now()->addDays(20)->toIso8601String());
        $manual->forceFill(['auto_renew' => false])->save();

        app(SweepDomainLifecycle::class)->execute();

        /*
         * The warning goes out for names with auto-renew off as well as on. A
         * customer who turned it off asked to decide for themselves, not to
         * lose the domain in silence.
         */
        $this->assertSame(1, AppNotification::query()
            ->where('type', NotificationType::DomainExpiring->value)
            ->count());
    }

    #[Test]
    public function the_expiry_warning_goes_out_once_per_term_and_not_once_a_night(): void
    {
        $this->heldName('nagme.test', now()->addDays(20)->toIso8601String());

        app(SweepDomainLifecycle::class)->execute();
        app(SweepDomainLifecycle::class)->execute();
        app(SweepDomainLifecycle::class)->execute();

        // Keyed on the expiry date, so a renewal that moves the date earns the
        // next term its own warning and nothing else does.
        $this->assertSame(1, AppNotification::query()
            ->where('type', NotificationType::DomainExpiring->value)
            ->count());
    }

    #[Test]
    public function a_name_claimed_for_an_order_nobody_paid_for_is_given_back(): void
    {
        $abandoned = Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'abandoned.test',
            'tld' => 'test',
            'state' => DomainState::RegistrationPending,
            'provider' => 'fake',
            'created_at' => now()->subDays(30),
        ]);

        DomainOperation::factory()->create([
            'domain_id' => $abandoned->getKey(),
            'customer_id' => $this->customer->getKey(),
            'name' => 'abandoned.test',
            'kind' => DomainOperationKind::Register,
            'state' => DomainOperationState::Requested,
            'provider' => 'fake',
        ]);

        $this->assertSame(1, app(SweepDomainLifecycle::class)->execute()['abandoned']);

        $released = $abandoned->fresh();

        /*
         * The claim is what stops two customers buying one name in the same
         * minute. Without an expiry on it, one unpaid order holds a name
         * against everybody — this platform included — for ever.
         */
        $this->assertSame(DomainState::Failed, $released?->state);
        $this->assertFalse($released->state->holdsTheName());
        $this->assertStringContainsString('never paid', (string) $released->review_reason);
    }

    #[Test]
    public function a_claim_with_a_paid_registration_behind_it_is_left_alone(): void
    {
        $paid = Domain::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'name' => 'paidfor.test',
            'tld' => 'test',
            'state' => DomainState::RegistrationPending,
            'provider' => 'fake',
            'created_at' => now()->subDays(30),
        ]);

        DomainOperation::factory()->create([
            'domain_id' => $paid->getKey(),
            'customer_id' => $this->customer->getKey(),
            'name' => 'paidfor.test',
            'kind' => DomainOperationKind::Register,
            // Queued: the money arrived and a worker has it.
            'state' => DomainOperationState::Queued,
            'provider' => 'fake',
        ]);

        $this->assertSame(0, app(SweepDomainLifecycle::class)->execute()['abandoned']);

        // Releasing a name under a registration that is running is how a
        // customer pays for a name the platform just gave away.
        $this->assertSame(DomainState::RegistrationPending, $paid->fresh()?->state);
    }

    #[Test]
    public function a_lapsed_name_walks_the_registrys_own_clock(): void
    {
        $domain = $this->heldName('lapsed.test', now()->subDay()->toIso8601String());
        $domain->forceFill(['auto_renew' => false])->save();

        app(SweepDomainLifecycle::class)->execute();
        $this->assertSame(DomainState::Grace, $domain->fresh()?->state);

        $domain->forceFill(['expires_at' => now()->subDays(40)])->save();
        app(SweepDomainLifecycle::class)->execute();
        $this->assertSame(DomainState::Redemption, $domain->fresh()?->state);

        $domain->forceFill(['expires_at' => now()->subDays(80)])->save();
        app(SweepDomainLifecycle::class)->execute();
        $this->assertSame(DomainState::Deleted, $domain->fresh()?->state);
    }

    #[Test]
    public function a_namespace_whose_windows_are_unknown_is_not_marched_through_invented_deadlines(): void
    {
        DomainTld::query()->where('tld', 'test')->update([
            'grace_days' => null,
            'redemption_days' => null,
        ]);

        $domain = $this->heldName('unknown-clock.test', now()->subDays(200)->toIso8601String());
        $domain->forceFill(['auto_renew' => false])->save();

        app(SweepDomainLifecycle::class)->execute();

        /*
         * Two hundred days past expiry and still only `expired`. Deleting a
         * domain on a guessed schedule is the one mistake in that sweep that
         * cannot be undone.
         */
        $this->assertSame(DomainState::Expired, $domain->fresh()?->state);
    }

    #[Test]
    public function a_transfer_is_invoiced_and_then_waits_on_the_other_registrar(): void
    {
        $quote = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'incoming.test', 'operation' => 'transfer'])
            ->assertCreated()
            ->json('data.id');

        $operationId = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/transfers', [
                'quote_id' => $quote,
                'authorisation_code' => 'auth-code-from-the-old-registrar',
            ])
            ->assertCreated()
            ->json('data.id');

        $operation = DomainOperation::query()->findOrFail($operationId);

        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        $settled = $operation->fresh();

        /*
         * A transfer does not finish when it is sent. The losing registrar has
         * up to five days, and a customer told "done" who finds their name
         * still elsewhere has been lied to by a status field.
         */
        $this->assertContains($settled?->state, [
            DomainOperationState::AwaitingRegistry,
            DomainOperationState::Completed,
        ]);
    }

    #[Test]
    public function the_authorisation_code_is_erased_once_it_has_been_sent(): void
    {
        $quote = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'sensitive.test', 'operation' => 'transfer'])
            ->assertCreated()
            ->json('data.id');

        $operationId = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/transfers', [
                'quote_id' => $quote,
                'authorisation_code' => 'a-real-bearer-credential',
            ])
            ->assertCreated()
            ->json('data.id');

        $operation = DomainOperation::query()->findOrFail($operationId);
        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        // A bearer credential for a whole domain does not sit in a table
        // waiting for a retry the Timeout Rule forbids anyway.
        $this->assertNull($operation->fresh()?->authorisation_code);
    }

    #[Test]
    public function the_authorisation_code_never_appears_in_a_payload(): void
    {
        $quote = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'hidden.test', 'operation' => 'transfer'])
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/transfers', [
                'quote_id' => $quote,
                'authorisation_code' => 'must-not-come-back',
            ])
            ->assertCreated();

        $this->assertStringNotContainsString('must-not-come-back', (string) $response->getContent());
    }
}
