<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Domains\Application\Actions\ReconcileDomains;
use Lynomia\Modules\Domains\Application\Actions\SweepDomainLifecycle;
use Lynomia\Modules\Domains\Application\Jobs\RedeemDomainAtRegistrar;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Enums\RedemptionSupport;
use Lynomia\Modules\Domains\Domain\Services\RedemptionAvailability;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Jobs\DeliverNotification;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A name that lapsed, recovered: the addendum's target flow, end to end.
 *
 *   domain in redemption → the screen says whether recovery is possible and
 *   why not if not → an authoritative quote for the registry's penalty →
 *   invoice → payment → the REDEEM operation → the registrar → the Timeout
 *   Rule where the registrar goes quiet → reconciliation → active, expired
 *   or needs review.
 *
 * The things a customer, a support agent and a registry would each be
 * upset about are the things proven: the price is never the client's, a
 * timeout is never retried, a paid recovery is never swept to deleted, and
 * nobody recovers anybody else's name.
 */
final class TheWholeLifeOfADomainRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'domains.fake.tlds' => ['test', 'sy'],
            'domains.fake.state_path' => storage_path('framework/testing/fake-registrar-'.Str::random(8).'.json'),
        ]);

        DomainTld::factory()->onSale()->named('test')->create([
            'redemption_price_minor' => 30_000,
            'redemption_cost_minor' => 25_000,
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

        Queue::fake([DeliverNotification::class]);
    }

    protected function tearDown(): void
    {
        $path = config('domains.fake.state_path');
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function acting(): array
    {
        return ['X-Lynomia-Customer' => (string) $this->customer->getKey()];
    }

    private function lapsedName(string $name, ?Customer $customer = null): Domain
    {
        // Seeded rather than registered: the fake refuses to REGISTER a name
        // carrying a failure marker, and a lapsed name is one it already holds.
        /** @var FakeDomainRegistrarProvider $fake */
        $fake = app(DomainRegistrarFactory::class)->make('fake');
        $fake->seedHolding($name, CarbonImmutable::now()->subDays(45));

        return Domain::factory()->create([
            'customer_id' => ($customer ?? $this->customer)->getKey(),
            'name' => $name,
            'tld' => 'test',
            'state' => DomainState::Redemption,
            'provider' => 'fake',
            'auto_renew' => false,
            'expires_at' => now()->subDays(45),
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

        app(SettleInvoice::class)->execute($invoice, $transaction);
    }

    private function quoteFor(string $name): string
    {
        return $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => $name, 'operation' => 'redeem'])
            ->assertCreated()
            ->json('data.id');
    }

    #[Test]
    public function a_lapsed_name_is_quoted_invoiced_paid_for_and_recovered(): void
    {
        $domain = $this->lapsedName('comeback.test');

        // The screen's facts, before any money: recoverable, at the catalogue price.
        $shown = $this->actingAs($this->owner)->withHeaders($this->acting())
            ->getJson('/api/v1/domains/'.$domain->getKey())
            ->assertOk();
        $shown->assertJsonPath('data.state', 'redemption');
        $shown->assertJsonPath('data.is_redeemable', true);
        $shown->assertJsonPath('data.is_renewable', false);
        $shown->assertJsonPath('data.redemption.support', 'supported');
        $shown->assertJsonPath('data.redemption.price_minor', 30_000);
        $shown->assertJsonPath('data.redemption.currency', 'KWD');
        $shown->assertJsonPath('data.redemption.attempt', null);

        // The quote is written from the catalogue, not from the request.
        $quoteId = $this->quoteFor('comeback.test');
        $quote = DomainQuote::query()->findOrFail($quoteId);
        $this->assertSame(DomainOperationKind::Redeem, $quote->operation);
        $this->assertSame(30_000, $quote->price_minor);
        $this->assertSame(25_000, $quote->cost_minor);

        $ordered = $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId, 'price_minor' => 1])
            ->assertCreated();
        $ordered->assertJsonPath('data.kind', 'redeem');
        $ordered->assertJsonPath('data.state', 'requested');
        $ordered->assertJsonPath('data.price_minor', 30_000);

        $operation = DomainOperation::query()->findOrFail($ordered->json('data.id'));
        $invoice = Invoice::query()->findOrFail($operation->invoice_id);
        $this->assertSame(30_000, $invoice->subtotal_minor);
        $this->assertSame(InvoiceItemKind::Plan, $invoice->items()->sole()->kind);
        $this->assertStringContainsString('redemption', strtolower((string) $invoice->items()->sole()->description));
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::DomainRedemptionOrdered->value]);

        // Nothing at the registry yet: the name is still lapsed.
        $this->assertSame(DomainState::Redemption, $domain->fresh()?->state);

        // The screen now says a recovery is awaiting payment, with the invoice.
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->getJson('/api/v1/domains/'.$domain->getKey())
            ->assertJsonPath('data.redemption.attempt.state', 'requested')
            ->assertJsonPath('data.redemption.attempt.invoice_id', $invoice->getKey());

        $this->pay($invoice);

        $this->assertSame(DomainOperationState::Completed, $operation->fresh()?->state);
        $this->assertSame(DomainState::Active, $domain->fresh()?->state);
        $this->assertTrue($domain->fresh()?->expires_at?->isFuture());
        $this->assertNull($domain->fresh()?->review_reason);

        $this->assertSame(1, Notification::query()->where('type', NotificationType::DomainRedeemed->value)->count());
    }

    #[Test]
    public function the_price_is_the_quotes_and_a_quote_for_another_name_is_refused(): void
    {
        $this->lapsedName('cheap.test');
        $expensive = $this->lapsedName('expensive.test');

        $cheapQuote = $this->quoteFor('cheap.test');

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$expensive->getKey().'/redemptions', ['quote_id' => $cheapQuote])
            ->assertConflict();

        $this->assertSame(0, DomainOperation::query()->count());
    }

    #[Test]
    public function an_expired_or_spent_quote_is_refused_and_two_orders_from_one_quote_make_one_operation(): void
    {
        $domain = $this->lapsedName('twice.test');
        $quoteId = $this->quoteFor('twice.test');

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->assertCreated();

        // Spent.
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->assertStatus(409);

        // A fresh quote, but a recovery is already in flight.
        $again = $this->quoteFor('twice.test');
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $again])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'domain.operation_in_flight');

        // Expired.
        DomainQuote::query()->whereKey($again)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $again])
            ->assertStatus(409);

        $this->assertSame(1, DomainOperation::query()->count());
    }

    #[Test]
    public function a_settlement_that_arrives_twice_recovers_the_name_once(): void
    {
        $domain = $this->lapsedName('paid-twice.test');
        $quoteId = $this->quoteFor('paid-twice.test');
        $operationId = $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->json('data.id');
        $operation = DomainOperation::query()->findOrFail($operationId);
        $invoice = Invoice::query()->findOrFail($operation->invoice_id);

        Queue::fake([DeliverNotification::class, RedeemDomainAtRegistrar::class]);

        $this->pay($invoice);
        // The second settlement of the same invoice — a redelivered webhook.
        app(SettleInvoice::class)->execute($invoice->fresh(), Transaction::query()->firstOrFail());

        Queue::assertPushed(RedeemDomainAtRegistrar::class, 1);
        $this->assertSame(DomainOperationState::Queued, $operation->fresh()?->state);
    }

    #[Test]
    public function a_registrar_that_never_answered_leaves_the_name_indeterminate_and_reconciliation_settles_it(): void
    {
        $domain = $this->lapsedName('quiet-timeout.test');
        $quoteId = $this->quoteFor('quiet-timeout.test');
        $operationId = $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->json('data.id');
        $operation = DomainOperation::query()->findOrFail($operationId);

        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        // The Timeout Rule: indeterminate on both rows, one attempt, told plainly.
        $this->assertSame(DomainOperationState::Indeterminate, $operation->fresh()?->state);
        $this->assertSame(1, $operation->fresh()?->attempts);
        $this->assertSame(DomainState::Indeterminate, $domain->fresh()?->state);
        $this->assertStringContainsString('did not answer', (string) $domain->fresh()?->review_reason);
        $this->assertSame(1, Notification::query()->where('type', NotificationType::DomainNeedsReview->value)->count());

        // Nothing here retries. A second sweep of the payment listener changes nothing.
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->getJson('/api/v1/domains/'.$domain->getKey())
            ->assertJsonPath('data.needs_attention', true)
            ->assertJsonPath('data.redemption.attempt.state', 'indeterminate')
            ->assertJsonPath('data.redemption.attempt.needs_attention', true);

        // The registry, asked, says the name was restored after all.
        $result = app(ReconcileDomains::class)->execute();
        $this->assertSame(1, $result['settled']);
        $this->assertSame(DomainState::Active, $domain->fresh()?->state);
        $this->assertTrue($domain->fresh()?->expires_at?->isFuture());
        $this->assertSame(DomainOperationState::Completed, $operation->fresh()?->state);
    }

    #[Test]
    public function a_refused_redemption_leaves_the_name_in_redemption_and_says_a_refund_is_owed(): void
    {
        $domain = $this->lapsedName('window-refused.test');
        $quoteId = $this->quoteFor('window-refused.test');
        $operationId = $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->json('data.id');
        $operation = DomainOperation::query()->findOrFail($operationId);

        $this->pay(Invoice::query()->findOrFail($operation->invoice_id));

        $this->assertSame(DomainOperationState::Failed, $operation->fresh()?->state);
        $this->assertSame(DomainState::Redemption, $domain->fresh()?->state);
        $this->assertStringContainsString('owed a refund', (string) $domain->fresh()?->review_reason);
        $this->assertSame(1, Notification::query()->where('type', NotificationType::DomainRedemptionFailed->value)->count());

        // A failed attempt is not in flight: the customer may try again.
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $this->quoteFor('window-refused.test')])
            ->assertCreated();
    }

    #[Test]
    public function a_paid_recovery_in_flight_is_not_swept_to_deleted_on_the_platforms_clock(): void
    {
        $domain = $this->lapsedName('lingering.test');
        $domain->forceFill(['expires_at' => now()->subDays(80)])->save();
        $quoteId = $this->quoteFor('lingering.test');
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$domain->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->assertCreated();

        $result = app(SweepDomainLifecycle::class)->execute();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(DomainState::Redemption, $domain->fresh()?->state);
    }

    #[Test]
    public function a_customer_cannot_see_price_or_recover_another_customers_name(): void
    {
        $other = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $theirs = $this->lapsedName('theirs.test', $other);
        $quoteId = $this->quoteFor('theirs.test');

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->getJson('/api/v1/domains/'.$theirs->getKey())
            ->assertNotFound();

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$theirs->getKey().'/redemptions', ['quote_id' => $quoteId])
            ->assertNotFound();

        $this->assertSame(0, DomainOperation::query()->count());
    }

    #[Test]
    public function a_name_that_is_not_in_redemption_cannot_be_redeemed_and_one_that_is_cannot_be_renewed(): void
    {
        $active = $this->lapsedName('still-fine.test');
        $active->forceFill(['state' => DomainState::Active, 'expires_at' => now()->addYear()])->save();
        $lapsed = $this->lapsedName('lapsed.test');

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$active->getKey().'/redemptions', ['quote_id' => $this->quoteFor('still-fine.test')])
            ->assertStatus(409);

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'lapsed.test', 'operation' => 'renew'])
            ->assertCreated();
        $renewQuote = DomainQuote::query()->where('operation', DomainOperationKind::Renew->value)->firstOrFail();
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/'.$lapsed->getKey().'/renewals', ['quote_id' => $renewQuote->getKey()])
            ->assertStatus(409);
    }

    #[Test]
    public function a_namespace_whose_penalty_or_windows_are_unknown_refuses_with_the_reason_and_never_quotes(): void
    {
        DomainTld::query()->where('tld', 'test')->update(['redemption_price_minor' => null, 'redemption_cost_minor' => null]);
        $domain = $this->lapsedName('unpriced.test');

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->getJson('/api/v1/domains/'.$domain->getKey())
            ->assertJsonPath('data.redemption.support', 'blocked_configuration')
            ->assertJsonPath('data.redemption.price_minor', null);

        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->postJson('/api/v1/domains/quotes', ['name' => 'unpriced.test', 'operation' => 'redeem'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'domain.redemption_unavailable');

        DomainTld::query()->where('tld', 'test')->update(['redemption_price_minor' => 30_000, 'grace_days' => null, 'redemption_days' => null]);
        $this->actingAs($this->owner)->withHeaders($this->acting())
            ->getJson('/api/v1/domains/'.$domain->getKey())
            ->assertJsonPath('data.redemption.support', 'blocked_configuration');
    }

    #[Test]
    public function the_sy_namespace_answers_unknown_because_no_policy_has_been_published(): void
    {
        $sy = DomainTld::factory()->onSale()->named('sy')->withUnknownLifecycle()->create(['provider' => 'sy_registry']);

        $answer = app(RedemptionAvailability::class)->forTld($sy);

        $this->assertSame(RedemptionSupport::Unknown, $answer->support);
        $this->assertStringContainsString('has not published', $answer->reason);
        $this->assertNull($answer->price);
    }
}
