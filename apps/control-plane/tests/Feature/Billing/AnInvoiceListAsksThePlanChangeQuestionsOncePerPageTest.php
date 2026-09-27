<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /invoices publishes `is_payable` and `plan_change_withdrawable`, which
 * for an open invoice of a subscription ask the plan-change questions
 * (PlanChangeDelivery::refusalForTheInvoice(), WithdrawAnUnpaidPlanChange::
 * withdrawable()). Asked row by row of every open subscription invoice, a
 * page of them cost queries in proportion to its length. Whether an invoice
 * bills a recorded plan change at all is read once for the page, in the
 * list's own query; only an open invoice that does is asked more - at most
 * one per subscription, since no change can be made while one is open.
 */
final class AnInvoiceListAsksThePlanChangeQuestionsOncePerPageTest extends BillingApiTestCase
{
    #[Test]
    public function a_longer_page_of_open_subscription_invoices_costs_no_more_queries(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->subscriptionFor($customer);

        // Warmed once, so a cache filled by the first request is not counted.
        $this->actingAs($user)->getJson('/api/v1/invoices')->assertOk();

        $few = $this->queriesForAPageOf(2, $customer, $user, $subscription);
        $many = $this->queriesForAPageOf(20, $customer, $user, $subscription);

        $this->assertSame($few, $many, sprintf('A page of 2 open invoices cost %d queries and a page of 20 cost %d.', $few, $many));
    }

    private function queriesForAPageOf(int $count, Customer $customer, User $user, Subscription $subscription): int
    {
        Invoice::query()->where('customer_id', $customer->getKey())->delete();
        Invoice::factory()->count($count)->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 1_500,
            'total_minor' => 1_500,
        ]);

        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/invoices?per_page=25');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('data.0.is_payable', true)->assertJsonPath('data.0.plan_change_withdrawable', false);

        return $queries;
    }
}
