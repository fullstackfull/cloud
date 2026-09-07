<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Http\Requests\ListSubscriptionsRequest;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/subscriptions and GET /api/v1/subscriptions/{subscription}.
 *
 * Cancellation has its own file; this one is about what a customer is shown
 * and, more importantly, what they are not.
 */
final class SubscriptionEndpointsTest extends BillingApiTestCase
{
    #[Test]
    public function a_customer_lists_their_own_subscriptions(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $subscription = $this->subscriptionFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $subscription->id)
            ->assertJsonPath('data.0.status', SubscriptionStatus::Active->value)
            ->assertJsonPath('data.0.billing_period', BillingPeriod::Monthly->value)
            ->assertJsonPath('data.0.recurring_amount.minor_units', 9000)
            ->assertJsonPath('data.0.recurring_amount.currency', 'KWD')
            // Three minor digits, rendered by Money rather than by dividing.
            ->assertJsonPath('data.0.recurring_amount.amount', '9.000');
    }

    #[Test]
    public function the_list_shows_nothing_belonging_to_another_account(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $this->subscriptionFor($theirs);

        $this->actingAs($mine)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function the_list_can_be_filtered_by_status(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $this->subscriptionFor($customer);
        $suspended = Subscription::factory()->suspended()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/subscriptions?status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suspended->id);
    }

    #[Test]
    public function one_subscription_keeps_its_three_clocks_apart(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $subscription = Subscription::factory()
            ->startingOn($start)
            ->create([
                'customer_id' => $customer->id,
                // Invoicing deliberately leads the period end by three days,
                // so the invoice reaches the customer before service continues.
                'next_invoice_at' => $start->addMonthNoOverflow()->subDays(3),
            ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}")
            ->assertOk();

        // Three separate fields, because collapsing them into one "renews on"
        // is how a client tells a customer the wrong date.
        $this->assertSame('2026-01-01T00:00:00+00:00', $response->json('data.current_period_start'));
        $this->assertSame('2026-02-01T00:00:00+00:00', $response->json('data.current_period_end'));
        $this->assertSame('2026-01-29T00:00:00+00:00', $response->json('data.next_invoice_at'));

        $response
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.cancel_at', null)
            ->assertJsonPath('data.is_scheduled_to_cancel', false)
            ->assertJsonPath('data.service_is_running', true);
    }

    #[Test]
    public function a_past_due_subscription_still_reports_its_service_as_running(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // Past due keeps running: the customer is inside the grace period, and
        // cutting service the moment a card declines loses customers to an
        // expired card rather than to non-payment.
        $subscription = Subscription::factory()
            ->pastDue(failedPayments: 2, graceEndsAt: CarbonImmutable::parse('2026-09-20T00:00:00Z'))
            ->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}")
            ->assertOk()
            ->assertJsonPath('data.status', SubscriptionStatus::PastDue->value)
            ->assertJsonPath('data.service_is_running', true)
            // The deadline the customer is working against is theirs to see.
            ->assertJsonPath('data.grace_period_ends_at', '2026-09-20T00:00:00+00:00');
    }

    #[Test]
    public function another_accounts_subscription_is_not_found_rather_than_forbidden(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $notMine = $this->subscriptionFor($theirs);

        // 404, not 403. A 403 confirms the row exists, which on ULIDs is an
        // enumeration oracle over every subscription on the platform.
        $this->actingAs($mine)
            ->getJson("/api/v1/subscriptions/{$notMine->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function another_accounts_subscription_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        [, $mine] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $real = $this->actingAs($mine)
            ->getJson('/api/v1/subscriptions/'.$this->subscriptionFor($theirs)->id);

        $invented = $this->actingAs($mine)
            ->getJson('/api/v1/subscriptions/01JZZZZZZZZZZZZZZZZZZZZZZZ');

        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function an_account_the_caller_also_owns_is_still_out_of_scope_for_this_request(): void
    {
        [$acting, $other, $user] = $this->twoAccountsOneLogin();

        $mine = $this->subscriptionFor($acting);
        $theirs = $this->subscriptionFor($other);

        // Membership of a second account is not a licence to read it through
        // the first. Without this the suite would still pass against a query
        // scoped to "every account this login belongs to".
        $list = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->getJson('/api/v1/subscriptions')
            ->assertOk();

        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->assertStringNotContainsString($theirs->id, (string) $list->getContent());

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $acting->id)
            ->getJson("/api/v1/subscriptions/{$theirs->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_member_without_billing_permission_may_not_read_subscriptions(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $member = $this->memberOf($customer, CustomerRole::Member);
        $subscription = $this->subscriptionFor($customer);

        $this->actingAs($member)
            ->getJson('/api/v1/subscriptions')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->actingAs($member)
            ->getJson("/api/v1/subscriptions/{$subscription->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function a_page_size_larger_than_the_ceiling_is_clamped_not_obeyed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        Subscription::factory()->count(3)->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/subscriptions?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', ListSubscriptionsRequest::MAX_PER_PAGE)
            ->assertJsonPath('meta.max_per_page', ListSubscriptionsRequest::MAX_PER_PAGE);
    }

    #[Test]
    public function paging_walks_the_collection_without_repeating_a_row(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        Subscription::factory()->count(5)->create(['customer_id' => $customer->id]);

        $first = $this->actingAs($user)->getJson('/api/v1/subscriptions?per_page=2')->assertOk();
        $second = $this->actingAs($user)->getJson('/api/v1/subscriptions?per_page=2&page=2')->assertOk();

        $first->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);

        $ids = array_merge(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        );

        $this->assertCount(4, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    #[Test]
    public function a_status_filter_that_is_not_a_status_is_a_422_naming_the_field(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/subscriptions?status=nearly_cancelled')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status']]]]);
    }

    #[Test]
    public function nothing_internal_to_the_platform_appears_on_a_subscription(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $subscription = Subscription::factory()
            ->pastDue(failedPayments: 3)
            ->create([
                'customer_id' => $customer->id,
                'coupon_cycles_remaining' => 6,
            ]);

        $show = $this->actingAs($user)->getJson("/api/v1/subscriptions/{$subscription->id}")->assertOk();
        $index = $this->actingAs($user)->getJson('/api/v1/subscriptions')->assertOk();

        foreach ([$show->json('data'), $index->json('data.0')] as $row) {
            // The account the caller is already acting for.
            $this->assertArrayNotHasKey('customer_id', $row);
            // Dunning machinery: how many attempts the platform has made, and
            // on which internal clock. `status` is the customer-visible answer.
            $this->assertArrayNotHasKey('failed_payment_count', $row);
            $this->assertArrayNotHasKey('suspended_at', $row);
            // Campaign internals. The remaining-cycles counter in particular
            // reads as a promise about future pricing that nothing makes.
            $this->assertArrayNotHasKey('coupon_id', $row);
            $this->assertArrayNotHasKey('coupon_cycles_remaining', $row);
            // Money is an object, never a raw minor-units column.
            $this->assertArrayNotHasKey('recurring_amount_minor', $row);
        }

        $body = $show->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($customer->id, $body);
    }
}
