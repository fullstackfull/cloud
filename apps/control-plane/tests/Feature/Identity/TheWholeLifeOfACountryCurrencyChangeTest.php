<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * Changing what an account is billed in, from the ask to the audit row.
 *
 * What the suite establishes: that nothing on the account changes when
 * the customer asks; that everything still priced in the old currency is a
 * blocker, named in words; that a country-only change is not blocked by
 * them and says what the tax will be; that approval re-checks and refuses
 * past a blocker; that a scheduled change is applied by the sweep after
 * checking again and held when a blocker appeared in between; that the
 * applied change converts nothing already written; and that every step
 * is audited and the customer told.
 */
final class TheWholeLifeOfACountryCurrencyChangeTest extends VpsApiTestCase
{
    private const string LIST = '/api/v1/account/country-currency-changes';

    private const string QUEUE = '/api/admin/customers/country-currency-changes';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_currency_change_is_blocked_by_what_is_still_priced_in_the_old_currency_then_approved_and_applied_without_converting_anything(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        PlanPrice::factory()->currency('USD', 1_000)->create();

        $invoice = Invoice::factory()->open()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);
        $total = $invoice->total_minor;
        $subscription = Subscription::factory()->priced(9_000, 'KWD')->create(['customer_id' => $customer->id]);
        Wallet::factory()->create(['customer_id' => $customer->id, 'currency' => 'KWD', 'balance_minor' => 2_500]);

        // The ask. Nothing on the account changes; the blockers are named.
        $asked = $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'SA', 'currency' => 'USD', 'reason' => 'We are moving the company to Riyadh.'])
            ->assertCreated()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Blocked->value)
            ->assertJsonPath('data.from_currency', 'KWD')
            ->assertJsonPath('data.to_currency', 'USD')
            ->assertJsonPath('data.impact.facts.open_invoices', 1)
            ->assertJsonPath('data.impact.facts.active_subscriptions', 1)
            ->assertJsonPath('data.impact.facts.wallet_balance_minor', 2_500)
            ->assertJsonPath('data.impact.facts.wallet_currency', 'KWD')
            ->assertJsonPath('data.impact.facts.target_currency_in_catalogue', true);

        $blockers = implode(' | ', $asked->json('data.impact.blockers'));
        $this->assertStringContainsString('open invoice', $blockers);
        $this->assertStringContainsString('never silently repriced', $blockers);
        $this->assertStringContainsString('never exchanged', $blockers);
        $this->assertSame('KWD', $customer->refresh()->currency);
        $this->assertSame('KW', $customer->country);

        $changeId = (string) $asked->json('data.id');
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::CountryCurrencyChangeRequested->value)->count());

        // A second ask while one is open is refused.
        $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'SA', 'currency' => 'USD', 'reason' => 'Again.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account.country_currency_change.already_open');

        // An operator cannot approve past a blocker.
        $operator = $this->operator();
        $this->actingAs($operator)
            ->postJson(self::QUEUE.'/'.$changeId.'/approve', ['note' => 'Fine by me.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'account.country_currency_change.blocked');
        $this->assertSame('KWD', $customer->refresh()->currency);

        // The customer clears the blockers and checks again.
        $invoice->forceFill(['status' => InvoiceStatus::Paid, 'amount_paid_minor' => $total])->save();
        $subscription->forceFill(['status' => 'cancelled'])->save();
        Wallet::query()->where('customer_id', $customer->id)->update(['balance_minor' => 0]);

        $this->actingAs($owner)
            ->postJson(self::LIST.'/'.$changeId.'/reanalyse')
            ->assertOk()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::AwaitingApproval->value)
            ->assertJsonPath('data.impact.blockers', []);

        // The queue shows it; the approval applies it now.
        $this->actingAs($operator)
            ->getJson(self::QUEUE)
            ->assertOk()
            ->assertJsonPath('data.0.id', $changeId)
            ->assertJsonPath('data.0.customer.display_name', $customer->display_name);

        $this->actingAs($operator)
            ->postJson(self::QUEUE.'/'.$changeId.'/approve', ['note' => 'Verified the move with the customer.'])
            ->assertOk()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Applied->value)
            ->assertJsonPath('data.decision_note', 'Verified the move with the customer.');

        $customer->refresh();
        $this->assertSame('USD', $customer->currency);
        $this->assertSame('SA', $customer->country);

        // Nothing already written was converted.
        $this->assertSame('KWD', $invoice->refresh()->currency);
        $this->assertSame($total, $invoice->total_minor);
        $this->assertSame('KWD', $subscription->refresh()->currency);
        $this->assertSame('KWD', Wallet::query()->where('customer_id', $customer->id)->sole()->currency);

        foreach ([AuditAction::CountryCurrencyChangeApproved, AuditAction::CountryCurrencyChangeApplied] as $action) {
            $this->assertSame(1, AuditEntry::query()->where('action', $action->value)->count(), $action->value);
        }

        $applied = AuditEntry::query()->where('action', AuditAction::CountryCurrencyChangeApplied->value)->sole();
        $this->assertSame(['country' => 'KW', 'currency' => 'KWD'], $applied->context['from']);
        $this->assertSame(['country' => 'SA', 'currency' => 'USD'], $applied->context['to']);

        $this->assertSame(1, Notification::query()->where('type', NotificationType::CountryCurrencyChangeApplied->value)->count());

        // The customer sees it applied; and may now ask again.
        $this->actingAs($owner)->getJson(self::LIST)->assertOk()
            ->assertJsonPath('data.0.state', 'applied')
            ->assertJsonPath('meta.currencies', ['USD']);
    }

    #[Test]
    public function a_country_only_change_is_not_blocked_by_the_old_currency_and_says_what_the_tax_becomes(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        Invoice::factory()->open()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);
        Subscription::factory()->priced(9_000, 'KWD')->create(['customer_id' => $customer->id]);

        $asked = $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'SA', 'currency' => 'KWD', 'reason' => 'Registered office moved.'])
            ->assertCreated()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::AwaitingApproval->value)
            ->assertJsonPath('data.impact.facts.country_changes', true)
            ->assertJsonPath('data.impact.facts.currency_changes', false)
            ->assertJsonPath('data.impact.blockers', []);

        $warnings = implode(' | ', $asked->json('data.impact.warnings'));
        $this->assertStringContainsString('tax', $warnings);
        $this->assertStringContainsString('keep the tax they were issued with', $warnings);
    }

    #[Test]
    public function asking_for_what_the_account_already_has_or_a_currency_nothing_is_priced_in_is_refused_or_blocked(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'KW', 'currency' => 'KWD', 'reason' => 'Nothing really.'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'account.country_currency_change.nothing_changes');

        $asked = $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'KW', 'currency' => 'XAF', 'reason' => 'Try it.'])
            ->assertCreated()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Blocked->value)
            ->assertJsonPath('data.impact.facts.target_currency_in_catalogue', false);

        $this->assertStringContainsString('Nothing is priced in XAF', implode(' ', $asked->json('data.impact.blockers')));

        // Bad shapes never reach the analysis.
        $this->actingAs($owner)->postJson(self::LIST, ['country' => 'Kuwait', 'currency' => 'USD', 'reason' => 'x'])->assertStatus(422);
        $this->actingAs($owner)->postJson(self::LIST, ['country' => 'KW', 'currency' => 'US$', 'reason' => 'Reason enough.'])->assertStatus(422);

        $this->assertSame(1, CountryCurrencyChange::query()->count());
    }

    #[Test]
    public function a_scheduled_change_is_applied_by_the_sweep_after_checking_again_and_held_when_a_blocker_appeared(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        PlanPrice::factory()->currency('USD', 1_000)->create();

        $changeId = (string) $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'KW', 'currency' => 'USD', 'reason' => 'Paying from a USD account.'])
            ->assertCreated()
            ->json('data.id');

        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson(self::QUEUE.'/'.$changeId.'/approve', ['note' => 'From the first of next month.', 'apply_at' => now()->addDays(3)->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Scheduled->value);

        $this->assertSame('KWD', $customer->refresh()->currency);

        // Too early: the sweep leaves it alone.
        $this->artisan('customers:apply-country-currency-changes')->assertExitCode(0);
        $this->assertSame(CountryCurrencyChangeState::Scheduled, CountryCurrencyChange::query()->findOrFail($changeId)->state);

        // An invoice is issued in the meantime.
        Invoice::factory()->open()->create(['customer_id' => $customer->id, 'currency' => 'KWD']);

        $this->travel(4)->days();
        $this->artisan('customers:apply-country-currency-changes')->assertExitCode(0);

        $held = CountryCurrencyChange::query()->findOrFail($changeId);
        $this->assertSame(CountryCurrencyChangeState::NeedsReview, $held->state);
        $this->assertNotSame([], $held->impact['blockers']);
        $this->assertSame('KWD', $customer->refresh()->currency);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::CountryCurrencyChangeBlocked->value)->count());
        $this->assertSame(1, Notification::query()->where('type', NotificationType::CountryCurrencyChangeNeedsReview->value)->count());

        // The customer sees it needs attention; the operator decides once the invoice is settled.
        $this->actingAs($owner)->getJson(self::LIST)->assertOk()->assertJsonPath('data.0.needs_attention', true);

        Invoice::query()->where('customer_id', $customer->id)->update(['status' => InvoiceStatus::Paid->value]);

        $this->actingAs($operator)
            ->postJson(self::QUEUE.'/'.$changeId.'/approve', ['note' => 'Invoice settled; applying.'])
            ->assertOk()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Applied->value);

        $this->assertSame('USD', $customer->refresh()->currency);
    }

    #[Test]
    public function a_request_can_be_rejected_with_a_note_or_withdrawn_and_neither_touches_the_account(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        PlanPrice::factory()->currency('USD', 1_000)->create();

        $first = (string) $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'KW', 'currency' => 'USD', 'reason' => 'Prefer USD.'])
            ->assertCreated()
            ->json('data.id');

        $operator = $this->operator();
        $this->actingAs($operator)
            ->postJson(self::QUEUE.'/'.$first.'/reject', ['note' => 'USD billing is not offered to Kuwaiti registrations.'])
            ->assertOk()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Rejected->value);

        $this->assertSame(1, Notification::query()->where('type', NotificationType::CountryCurrencyChangeRejected->value)->count());
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::CountryCurrencyChangeRejected->value)->count());

        // Rejected is settled: the customer may ask again, and withdraw that.
        $second = (string) $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'SA', 'currency' => 'KWD', 'reason' => 'Office moved.'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)
            ->postJson(self::LIST.'/'.$second.'/withdraw')
            ->assertOk()
            ->assertJsonPath('data.state', CountryCurrencyChangeState::Withdrawn->value);

        // Nothing settled can be decided or withdrawn again.
        $this->actingAs($owner)->postJson(self::LIST.'/'.$second.'/withdraw')->assertStatus(409);
        $this->actingAs($operator)->postJson(self::QUEUE.'/'.$first.'/approve', ['note' => 'Changed my mind.'])->assertStatus(409);

        $this->assertSame('KWD', $customer->refresh()->currency);
        $this->assertSame('KW', $customer->country);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::CountryCurrencyChangeWithdrawn->value)->count());
    }

    #[Test]
    public function only_the_accounts_managers_may_ask_and_only_an_operator_with_customer_update_may_decide(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        PlanPrice::factory()->currency('USD', 1_000)->create();

        $billing = $this->memberOf($customer, CustomerRole::Billing);
        $this->actingAs($billing)
            ->postJson(self::LIST, ['country' => 'KW', 'currency' => 'USD', 'reason' => 'I pay the bills.'])
            ->assertForbidden();

        $changeId = (string) $this->actingAs($owner)
            ->postJson(self::LIST, ['country' => 'KW', 'currency' => 'USD', 'reason' => 'Prefer USD.'])
            ->assertCreated()
            ->json('data.id');

        // Another account cannot see or touch it.
        [, $stranger] = $this->accountWithOwner();
        $this->actingAs($stranger)->postJson(self::LIST.'/'.$changeId.'/withdraw')->assertNotFound();
        $this->actingAs($stranger)->getJson(self::LIST)->assertOk()->assertJsonCount(0, 'data');

        // Finance reads the queue and cannot decide; a customer cannot reach it at all.
        $finance = $this->operator(Role::Finance);
        $this->actingAs($finance)->getJson(self::QUEUE)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($finance)->postJson(self::QUEUE.'/'.$changeId.'/approve', ['note' => 'Sure.'])->assertForbidden();
        $this->actingAs($owner)->postJson(self::QUEUE.'/'.$changeId.'/approve', ['note' => 'Sure.'])->assertForbidden();

        $this->assertSame(CountryCurrencyChangeState::AwaitingApproval, CountryCurrencyChange::query()->findOrFail($changeId)->state);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }
}
