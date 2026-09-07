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
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $subscription->id,
            ])
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
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $subscription->id,
            ])
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
    public function repeating_an_immediate_cancellation_is_refused_without_restamping_the_ending(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        $first = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $subscription->id,
            ])
            ->assertOk();

        // Refused, not converged. The state machine would return an
        // already-cancelled subscription unchanged, but the endpoint says
        // plainly that there is nothing left to cancel rather than answering a
        // second 200 for an operation that did nothing. The cost is that a
        // client retrying after a dropped response sees a 409 for a request
        // that in fact succeeded; the ending it recorded is not restamped.
        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $subscription->id,
            ])
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
            ->postJson("/api/v1/subscriptions/{$notMine->id}/cancel", [
                'immediately' => true,
                // Named in the confirmation as well as the path, so the request
                // is well formed and the 404 is decided by the scoped lookup
                // rather than by validation refusing it first.
                'confirm_subscription_id' => $notMine->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $notMine->refresh();
        $this->assertSame(SubscriptionStatus::Active, $notMine->status);
        $this->assertTrue($notMine->auto_renew);
        $this->assertNull($notMine->cancel_at);
    }

    #[Test]
    public function a_subscription_on_another_account_the_caller_also_owns_cannot_be_cancelled_here(): void
    {
        [$acting, $other, $user] = $this->twoAccountsOneLogin();

        $theirs = $this->subscriptionFor($other);

        // The caller owns both accounts and could cancel this subscription by
        // acting for the account that holds it. What they may not do is reach
        // it through a request that named the *other* account — a request whose
        // audit trail, permissions and billing context all say something else.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->postJson("/api/v1/subscriptions/{$theirs->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $theirs->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $theirs->refresh();
        $this->assertSame(SubscriptionStatus::Active, $theirs->status);
        $this->assertTrue($theirs->auto_renew);
        $this->assertNull($theirs->cancel_at);
        $this->assertNull($theirs->ended_at);
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
    public function an_immediate_cancellation_without_a_confirmation_is_refused_and_changes_nothing(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        // `immediately: true` on its own is not a confirmation. It is a field a
        // generated client sets in its constructor, a convenience wrapper
        // defaults and a retry loop resends — and what it asks for here cannot
        // be undone: the state machine has no edge back out of cancelled, the
        // service stops now, and the paid remainder is not returned.
        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", ['immediately' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['confirm_subscription_id']]]]);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->auto_renew);
        $this->assertNull($subscription->cancel_at);
        $this->assertNull($subscription->ended_at);
    }

    #[Test]
    public function a_confirmation_that_names_a_different_subscription_is_refused(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $target = $this->subscriptionFor($customer);
        // The caller's own second subscription, so this is about proof of
        // intent and not about tenancy: naming the wrong one of your own plans
        // must not end the one in the path.
        $other = $this->subscriptionFor($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$target->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $other->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'subscription.immediate_cancellation_not_confirmed');

        $target->refresh();
        $other->refresh();
        $this->assertSame(SubscriptionStatus::Active, $target->status);
        $this->assertSame(SubscriptionStatus::Active, $other->status);
        $this->assertNull($target->ended_at);
        $this->assertNull($other->ended_at);
    }

    #[Test]
    public function the_confirmation_error_does_not_hand_back_the_id_it_wanted(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => 'not-this-one',
            ])
            ->assertStatus(422);

        // Echoing the correct value would turn the confirmation into a two-step
        // handshake any client can perform on its own, which is exactly the
        // safety the field exists to provide.
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($subscription->id, $body);
    }

    #[Test]
    public function the_scheduled_cancellation_needs_no_confirmation(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        // Demanding it here too would train every client to send the field
        // always, which is how a confirmation becomes a constant. The scheduled
        // form is reversible, so it is asked for plainly.
        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionStatus::Active->value);
    }

    #[Test]
    public function a_subscription_that_has_ended_is_not_reported_as_scheduled_to_cancel(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->travelTo(CarbonImmutable::parse('2026-09-06T12:00:00Z'));

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-09-01T00:00:00Z'))
            ->create(['customer_id' => $customer->id]);

        // Scheduled first, so cancel_at is standing at the period end...
        $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.is_scheduled_to_cancel', true)
            ->assertJsonPath('data.cancel_at', '2026-10-01T00:00:00+00:00');

        // ...and then overtaken by an immediate cancellation, which ends the
        // subscription today and leaves cancel_at where it was. The row must
        // not still claim it is winding down towards a date a month away: a
        // client branching on that would tell the customer their service runs
        // until October on the day it actually stopped.
        $ended = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $subscription->id,
            ])
            ->assertOk();

        $ended
            ->assertJsonPath('data.status', SubscriptionStatus::Cancelled->value)
            ->assertJsonPath('data.ended_at', '2026-09-06T12:00:00+00:00')
            ->assertJsonPath('data.is_scheduled_to_cancel', false)
            ->assertJsonPath('data.service_is_running', false);

        // And the same answer when the row is read back, not only in the
        // response the cancellation happened to build.
        $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.is_scheduled_to_cancel', false);
    }

    #[Test]
    public function the_cancelled_subscription_comes_back_without_anything_internal_on_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = Subscription::factory()
            ->pastDue(failedPayments: 4)
            ->create(['customer_id' => $customer->id, 'coupon_cycles_remaining' => 2]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'immediately' => true,
                'confirm_subscription_id' => $subscription->id,
            ])
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
