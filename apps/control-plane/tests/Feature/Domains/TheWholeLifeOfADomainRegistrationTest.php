<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Domains\Application\Jobs\RegisterDomainAtRegistrar;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;
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
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Searching for a name and ending up holding it.
 *
 * The chain a customer actually walks, in one test, because every phase of
 * this project has found the same class of defect: each link works and the
 * chain does not. The interesting assertions are the ones about order —
 * nothing is registered before the money arrives, and nothing is registered
 * twice when the settlement webhook arrives twice.
 */
final class TheWholeLifeOfADomainRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['domains.fake.tlds' => ['test']]);

        DomainTld::factory()->onSale()->named('test')->create();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->owner->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function acting(): array
    {
        return ['X-Lynomia-Customer' => (string) $this->customer->getKey()];
    }

    /**
     * @return array<string, string>
     */
    private function registrant(): array
    {
        return [
            'name' => 'Layla Haddad',
            'email' => 'layla@example.test',
            'phone' => '+96550000000',
            'address_line_one' => '12 Gulf Road',
            'city' => 'Kuwait City',
            'country' => 'KW',
        ];
    }

    /**
     * Search, price, and order — the part before any money moves.
     */
    private function orderTheName(string $name): DomainOperation
    {
        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/domains/search?name='.$name)
            ->assertOk()
            ->assertJsonPath('data.0.is_orderable', true);

        $quote = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => $name, 'operation' => 'register'])
            ->assertCreated()
            ->json('data.id');

        $operationId = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains', [
                'quote_id' => $quote,
                'registrant' => $this->registrant(),
            ])
            ->assertCreated()
            ->json('data.id');

        return DomainOperation::query()->findOrFail($operationId);
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
    public function a_name_is_searched_priced_ordered_paid_for_and_held(): void
    {
        $operation = $this->orderTheName('wholelife.test');

        $domain = $operation->domain;
        $this->assertInstanceOf(Domain::class, $domain);

        /*
         * Nothing is registered yet, and that is the point of the ordering.
         * A registry fee is spent the moment a registration succeeds and
         * cannot be reclaimed, so the platform waits for the money.
         */
        $this->assertSame(DomainState::RegistrationPending, $domain->state);
        $this->assertSame(DomainOperationState::Requested, $operation->state);
        $this->assertNull($domain->registered_at);

        $invoice = Invoice::query()->findOrFail($operation->invoice_id);
        $this->assertSame($operation->price_minor, $invoice->subtotal_minor);

        $this->pay($invoice);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);

        /*
         * Settling the invoice is the whole trigger. The listener moves the
         * operation to `queued` and dispatches; on the synchronous queue this
         * suite runs, the worker follows immediately — so by the time the
         * payment returns, the name is held. That the dispatch happens once
         * and not twice is proved separately, with the bus faked.
         */
        $held = $domain->fresh();
        $this->assertInstanceOf(Domain::class, $held);

        $this->assertSame(DomainState::Active, $held->state);
        $this->assertNotNull($held->registered_at);
        $this->assertNotNull($held->expires_at);
        $this->assertSame(DomainOperationState::Completed, $operation->fresh()?->state);

        // Auto-renew on, because a lapsed domain is not recoverable at the
        // ordinary price and takes the customer's mail with it.
        $this->assertTrue($held->auto_renew);

        $this->assertSame(1, AppNotification::query()
            ->where('type', NotificationType::DomainRegistered->value)
            ->count());
    }

    #[Test]
    public function the_registrant_is_recorded_and_never_published_in_the_clear(): void
    {
        $operation = $this->orderTheName('privacy.test');

        $contact = DomainContact::query()->where('domain_id', $operation->domain_id)->firstOrFail();
        $this->assertSame('Layla Haddad', $contact->name);

        // Encrypted at rest. The column holds ciphertext, not the name.
        $stored = (string) DB::table('domain_contacts')
            ->where('id', $contact->getKey())
            ->value('name');

        $this->assertNotSame('Layla Haddad', $stored);
        $this->assertStringNotContainsString('Layla', $stored);
    }

    #[Test]
    public function the_order_is_audited_without_the_registrants_address(): void
    {
        $operation = $this->orderTheName('audited.test');

        $entry = AuditEntry::query()
            ->where('action', AuditAction::DomainRegistrationOrdered->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('audited.test', $entry->context['domain'] ?? null);

        /*
         * An audit entry is read by operators and kept for years. The domain
         * row holds the registrant under encryption; the audit trail has no
         * business holding a second, unencrypted copy.
         */
        $this->assertStringNotContainsString('Gulf Road', json_encode($entry->context, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('layla@', json_encode($entry->context, JSON_THROW_ON_ERROR));
        $this->assertSame((string) $operation->customer_id, (string) $entry->customer_id);
    }

    #[Test]
    public function a_settlement_that_arrives_twice_buys_the_name_once(): void
    {
        Bus::fake([RegisterDomainAtRegistrar::class]);

        $operation = $this->orderTheName('once.test');
        $invoice = Invoice::query()->findOrFail($operation->invoice_id);

        $this->pay($invoice);

        // Webhooks arrive twice. That is a property of every payment provider
        // worth using, and the second one must buy nothing.
        event(new InvoicePaid(
            invoiceId: (string) $invoice->getKey(),
            customerId: (string) $this->customer->getKey(),
            orderId: null,
            subscriptionId: null,
            paidAt: now()->toImmutable(),
        ));

        Bus::assertDispatchedTimes(RegisterDomainAtRegistrar::class, 1);
    }

    #[Test]
    public function a_registrar_that_never_answered_leaves_the_name_indeterminate_and_does_not_try_again(): void
    {
        $operation = $this->orderTheName('lost-timeout.test');
        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        $this->runTheWorker($operation);

        $operation = $operation->fresh();
        $domain = Domain::query()->findOrFail($operation?->domain_id);

        /*
         * The Timeout Rule. The fake registers the name and then throws, which
         * is exactly the case that matters: the money moved, the name may be
         * held, and a retry would buy a second term.
         */
        $this->assertSame(DomainOperationState::Indeterminate, $operation?->state);
        $this->assertSame(DomainState::Indeterminate, $domain->state);
        $this->assertNotNull($domain->review_reason);

        /*
         * And the customer is told, in words that say not to try again. A
         * screen that merely looks failed invites a second order, which is the
         * one action that turns an uncertainty into a double charge.
         */
        $this->assertSame(1, AppNotification::query()
            ->where('type', NotificationType::DomainNeedsReview->value)
            ->count());

        // And the platform will not spend again on its own.
        $this->assertFalse($operation->state->permitsAnotherAttempt());
        $this->assertFalse($operation->state->mayBeStarted());

        // A redelivered message finds a row it may not touch and does nothing.
        $this->runTheWorker($operation);
        $this->assertSame(DomainOperationState::Indeterminate, $operation->fresh()?->state);
    }

    #[Test]
    public function a_refused_registration_frees_the_name_and_says_a_refund_is_owed(): void
    {
        $operation = $this->orderTheName('nope-refused.test');
        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        $this->runTheWorker($operation);

        $domain = Domain::query()->findOrFail($operation->domain_id);

        $this->assertSame(DomainOperationState::Failed, $operation->fresh()?->state);
        $this->assertSame(DomainState::Failed, $domain->state);

        // The row stays, because the invoice refers to it and a row that
        // disappears takes the explanation with it. What it must not do is go
        // on holding the name.
        $this->assertFalse($domain->state->holdsTheName());
        $this->assertStringContainsString('refund', (string) $domain->review_reason);

        // Paid for something they did not get, and told so by the platform
        // rather than by noticing the name is still for sale.
        $this->assertSame(1, AppNotification::query()
            ->where('type', NotificationType::DomainRegistrationFailed->value)
            ->count());
    }

    #[Test]
    public function two_accounts_cannot_order_the_same_name(): void
    {
        $this->orderTheName('contested.test');

        $rival = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $rivalOwner = User::factory()->create();
        $rival->members()->create([
            'user_id' => $rivalOwner->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $quote = $this->actingAs($rivalOwner)
            ->withHeaders(['X-Lynomia-Customer' => (string) $rival->getKey()])
            ->postJson('/api/v1/domains/quotes', ['name' => 'contested.test', 'operation' => 'register'])
            ->assertCreated()
            ->json('data.id');

        /*
         * The second account was quoted a price — the quote is cheap and
         * carries no claim — and is refused at the point it tries to buy,
         * which is the only point where the answer can be authoritative.
         */
        $this->actingAs($rivalOwner)
            ->withHeaders(['X-Lynomia-Customer' => (string) $rival->getKey()])
            ->postJson('/api/v1/domains', ['quote_id' => $quote, 'registrant' => $this->registrant()])
            ->assertStatus(409);
    }

    #[Test]
    public function a_registration_ordered_without_a_registrant_is_refused_before_the_invoice(): void
    {
        $quote = $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'nocontact.test', 'operation' => 'register'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains', ['quote_id' => $quote])
            ->assertStatus(422);

        // Nothing was claimed and nothing was invoiced.
        $this->assertSame(0, Domain::query()->where('name', 'nocontact.test')->count());
    }

    /**
     * Run the job the way the queue would.
     *
     * Through the container rather than by handing `handle()` its arguments,
     * so that a dependency added to the job tomorrow does not break every test
     * that runs it — and so the test exercises the same resolution the worker
     * does.
     */
    private function runTheWorker(DomainOperation $operation): void
    {
        app()->call([new RegisterDomainAtRegistrar((string) $operation->getKey()), 'handle']);
    }
}
