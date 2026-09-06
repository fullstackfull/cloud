<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/subscriptions/{subscription}/cancel.
 *
 * The endpoint publishes both forms the underlying action supports, because
 * they are different commercial events: one lets the customer keep the time
 * they have paid for, the other takes it back. Most of what is asserted here
 * is that the default is the reversible one, and that the destructive form
 * cannot be reached with somebody else's id.
 */
final class CancelSubscriptionEndpointTest extends BillingApiTestCase
{
    #[Test]
    public function the_default_cancellation_lets_the_paid_period_run_out(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // Inside the period, so "the service keeps running until the end of
        // what you paid for" is a claim the assertions can actually see.
        $this->travelTo(CarbonImmutable::parse('2026-01-10T12:00:00Z'));

        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $subscription = Subscription::factory()
            ->startingOn($start)
            ->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")
            ->assertOk()
            // Still active, and still serving: the customer owns the rest of
            // the period they paid for.
            ->assertJsonPath('data.status', SubscriptionStatus::Active->value)
            ->assertJsonPath('data.service_is_running', true)
            ->assertJsonPath('data.is_scheduled_to_cancel', true)
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.cancel_at', '2026-02-01T00:00:00+00:00')
            ->assertJsonPath('data.ended_at', null);

        $subscription->refresh();
        $this->assertFalse($subscription->auto_renew);
        $this->assertSame('2026-02-01 00:00:00', $subscription->cancel_at?->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
    }

    #[Test]
    public function an_immediate_cancellation_ends_the_subscription_now(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => true])
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionStatus::Cancelled->value)
            ->assertJsonPath('data.service_is_running', false)
            ->assertJsonPath('data.auto_renew', false)
            // Nothing further is owed, so the renewal worker's selector is
            // cleared rather than left pointing at a date.
            ->assertJsonPath('data.next_invoice_at', null);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertNotNull($subscription->ended_at);
        $this->assertNull($subscription->next_invoice_at);
    }

    #[Test]
    public function nothing_is_refunded_by_cancelling(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        // A paid invoice for the period being given up. Cancelling must not
        // touch it: returning the unused remainder is a refund, a different
        // operation with different authorisation, and issuing one from here
        // would move money through an endpoint nobody reviewed as a refund.
        $invoice = $this->invoiceFor($customer, [
            'subscription_id' => $subscription->id,
            'amount_paid_minor' => 9000,
            'issued_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => true])
            ->assertOk();

        $invoice->refresh();
        $this->assertSame(0, $invoice->amount_refunded_minor);
        $this->assertSame(9000, $invoice->amount_paid_minor);
    }

    #[Test]
    public function repeating_a_scheduled_cancellation_keeps_the_date_the_customer_was_told(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $subscription = Subscription::factory()
            ->startingOn($start)
            ->create(['customer_id' => $customer->id]);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")
            ->assertOk();

        // A double-clicked button, or a client retrying a request whose
        // response it never saw. The second must not move the end date.
        $second = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")
            ->assertOk();

        $this->assertSame($first->json('data.cancel_at'), $second->json('data.cancel_at'));
    }

    #[Test]
    public function repeating_an_immediate_cancellation_converges_instead_of_failing(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => true])
            ->assertOk();

        // The state machine returns an already-cancelled subscription
        // unchanged rather than restamping when it ended — but the endpoint
        // still says plainly that there is nothing left to cancel, so a client
        // is not told it just did something it did not do.
        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.already_ended');

        $subscription->refresh();
        $this->assertSame($first->json('data.ended_at'), $subscription->ended_at?->toIso8601String());
    }

    #[Test]
    public function a_subscription_that_has_already_ended_cannot_be_cancelled_again(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // Terminated is the platform's ending, not the customer's, and there
        // is no edge out of it. A scheduled cancellation would otherwise
        // quietly stamp a future cancel_at onto a row that ended months ago.
        $terminated = Subscription::factory()
            ->status(SubscriptionStatus::Terminated)
            ->create(['customer_id' => $customer->id, 'ended_at' => now()->subMonths(3)]);

        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$terminated->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'subscription.already_ended')
            ->assertJsonPath('error.details.status', SubscriptionStatus::Terminated->value);

        $terminated->refresh();
        $this->assertNull($terminated->cancel_at);
    }

    #[Test]
    public function another_accounts_subscription_cannot_be_cancelled_and_is_not_found(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $notMine = $this->subscriptionFor($theirs);

        // 404, not 403: a 403 would confirm the id exists. And the row must be
        // untouched — the lookup is scoped through the acting account, so it
        // was never in hand to cancel.
        $this->actingAs($mine)
            ->postJson("/api/v1/subscriptions/{$notMine->id}/cancel", ['immediately' => true])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $notMine->refresh();
        $this->assertSame(SubscriptionStatus::Active, $notMine->status);
        $this->assertTrue($notMine->auto_renew);
        $this->assertNull($notMine->cancel_at);
    }

    #[Test]
    public function another_accounts_subscription_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $real = $this->actingAs($mine)
            ->postJson('/api/v1/subscriptions/'.$this->subscriptionFor($theirs)->id.'/cancel');

        $invented = $this->actingAs($mine)
            ->postJson('/api/v1/subscriptions/01JZZZZZZZZZZZZZZZZZZZZZZZ/cancel');

        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function reading_the_account_is_not_permission_to_end_its_subscriptions(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        // An administrator can see the billing but does not hold billing.pay.
        $administrator = $this->memberOf($customer, CustomerRole::Administrator);
        $subscription = $this->subscriptionFor($customer);

        $this->actingAs($administrator)
            ->getJson("/api/v1/subscriptions/{$subscription->id}")
            ->assertOk();

        $this->actingAs($administrator)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        $subscription->refresh();
        $this->assertTrue($subscription->auto_renew);
        $this->assertNull($subscription->cancel_at);
    }

    #[Test]
    public function a_member_with_no_billing_permission_is_refused_before_the_lookup(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $member = $this->memberOf($customer, CustomerRole::Member);
        $subscription = $this->subscriptionFor($customer);

        $own = $this->actingAs($member)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel");

        $invented = $this->actingAs($member)
            ->postJson('/api/v1/subscriptions/01JZZZZZZZZZZZZZZZZZZZZZZZ/cancel');

        // Identical, because the permission check runs before any lookup. Were
        // it the other way round, the difference between the two answers would
        // tell an unauthorised member which ids are real.
        $own->assertForbidden()->assertJsonPath('error.code', 'auth.forbidden');
        $invented->assertForbidden()->assertJsonPath('error.code', 'auth.forbidden');
        $this->assertSame($own->json('error.code'), $invented->json('error.code'));
    }

    #[Test]
    public function a_non_boolean_immediately_flag_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        // Refused rather than coerced. "maybe" is not a decision about whether
        // to switch a customer's service off today.
        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['immediately']]]]);

        $subscription->refresh();
        $this->assertTrue($subscription->auto_renew);
        $this->assertNull($subscription->cancel_at);
    }

    #[Test]
    public function the_cancelled_subscription_comes_back_without_anything_internal_on_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = Subscription::factory()
            ->pastDue(failedPayments: 4)
            ->create(['customer_id' => $customer->id, 'coupon_cycles_remaining' => 2]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => true])
            ->assertOk();

        $row = $response->json('data');
        $this->assertArrayNotHasKey('customer_id', $row);
        $this->assertArrayNotHasKey('failed_payment_count', $row);
        $this->assertArrayNotHasKey('suspended_at', $row);
        $this->assertArrayNotHasKey('coupon_id', $row);
        $this->assertArrayNotHasKey('coupon_cycles_remaining', $row);
        $this->assertArrayNotHasKey('recurring_amount_minor', $row);
    }
}
