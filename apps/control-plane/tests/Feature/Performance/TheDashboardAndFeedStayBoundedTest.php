<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard and the feed must not query per row.
 *
 * §45 and §76. Both surfaces join many domains, and both are the obvious place
 * for an N+1 to hide: an activity page that resolved one hostname per row, or
 * a dashboard that read one service per attention item, would work perfectly
 * on the seeded account and fall over on a real one.
 *
 * What is asserted is **structural boundedness**, not wall-clock time. The
 * query count for a page is measured at one size and again at a much larger
 * one, and the difference must be nothing: adding forty machines and four
 * hundred events to an account may not add a single query to reading its first
 * page. A time-based assertion would pass or fail with CI's mood.
 *
 * The ceilings are deliberately generous. The point is not that the number is
 * small, it is that the number does not grow.
 */
final class TheDashboardAndFeedStayBoundedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
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

    /**
     * An account with `$machines` servers, each with `$eventsEach` events, plus
     * invoices and notifications.
     *
     * Every row is a real row through the real models, because a fixture built
     * by inserting straight into tables can miss exactly the relation whose
     * absence would make the query count look better than it is.
     */
    private function accountWith(Customer $customer, User $user, int $machines, int $eventsEach): void
    {
        $cluster = ComputeCluster::factory()->create();
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->id]);

        for ($m = 0; $m < $machines; $m++) {
            $service = Service::factory()->create([
                'customer_id' => $customer->id,
                'kind' => 'vps',
                'status' => ServiceStatus::Active,
            ]);

            VirtualMachine::factory()
                ->onNode($node)
                ->forService($service)
                ->create(['hostname' => sprintf('scale-%s-%03d', substr((string) $customer->id, -4), $m)]);

            for ($e = 0; $e < $eventsEach; $e++) {
                ProvisioningJob::factory()->create([
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                    'requested_by_user_id' => $e % 2 === 0 ? $user->id : null,
                    'kind' => ProvisioningJobKind::Restart,
                    'status' => ProvisioningJobStatus::Succeeded,
                    // Namespaced by the account: two accounts are seeded in
                    // one test, and an idempotency key is unique platform-wide.
                    'idempotency_key' => sprintf('scale:%s:%d:%d', $customer->id, $m, $e),
                    'created_at' => now()->subMinutes(($m * $eventsEach) + $e),
                ]);
            }

            Invoice::factory()->create([
                'customer_id' => $customer->id,
                'number' => sprintf('INV-%s-%03d', substr((string) $customer->id, -6), $m),
                'currency' => 'KWD',
                'status' => InvoiceStatus::Open,
                'subtotal_minor' => 1000,
                'total_minor' => 1000,
                'amount_paid_minor' => 0,
                'issued_at' => now()->subDays(2),
                'due_at' => now()->addDays(3),
            ]);

            Notification::factory()->create([
                'customer_id' => $customer->id,
                'read_at' => null,
            ]);
        }
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    private function countQueries(callable $act): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $act();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $result];
    }

    #[Test]
    public function the_activity_feed_costs_the_same_at_five_machines_as_at_forty_five(): void
    {
        [$small, $smallUser] = $this->account();
        $this->accountWith($small, $smallUser, machines: 5, eventsEach: 3);

        [$large, $largeUser] = $this->account();
        $this->accountWith($large, $largeUser, machines: 45, eventsEach: 10);

        [$smallQueries, $smallResponse] = $this->countQueries(
            fn () => $this->actingAs($smallUser)->getJson('/api/v1/activity?per_page=25'),
        );

        [$largeQueries, $largeResponse] = $this->countQueries(
            fn () => $this->actingAs($largeUser)->getJson('/api/v1/activity?per_page=25'),
        );

        $smallResponse->assertOk();
        $largeResponse->assertOk();

        // The large account really is larger: a full page of rows.
        self::assertCount(25, $largeResponse->json('data'));

        /*
         * The whole assertion. Nine times the machines and thirty times the
         * events, and not one extra query — because identities and actor names
         * are resolved for the page, never per row.
         */
        self::assertSame(
            $smallQueries,
            $largeQueries,
            sprintf(
                'Reading one page of activity cost %d queries on a small account and %d on a large one.',
                $smallQueries,
                $largeQueries,
            ),
        );

        // And the constant is a small one. Generous, because the point is the
        // growth, not the number.
        self::assertLessThan(30, $largeQueries);
    }

    #[Test]
    public function the_dashboard_costs_the_same_at_five_machines_as_at_forty_five(): void
    {
        [$small, $smallUser] = $this->account();
        $this->accountWith($small, $smallUser, machines: 5, eventsEach: 3);

        [$large, $largeUser] = $this->account();
        $this->accountWith($large, $largeUser, machines: 45, eventsEach: 10);

        [$smallQueries, $smallResponse] = $this->countQueries(
            fn () => $this->actingAs($smallUser)->getJson('/api/v1/me/overview'),
        );

        [$largeQueries, $largeResponse] = $this->countQueries(
            fn () => $this->actingAs($largeUser)->getJson('/api/v1/me/overview'),
        );

        $smallResponse->assertOk();
        $largeResponse->assertOk();

        self::assertSame(45, $largeResponse->json('data.services.total'));

        /*
         * The dashboard's attention list is capped, its counts are grouped and
         * its handles resolve in one pass — so forty-five services with an
         * invoice each cost what five did.
         */
        self::assertSame(
            $smallQueries,
            $largeQueries,
            sprintf(
                'Reading the dashboard cost %d queries on a small account and %d on a large one.',
                $smallQueries,
                $largeQueries,
            ),
        );

        self::assertLessThan(40, $largeQueries);
    }

    #[Test]
    public function the_unread_count_is_one_query_whatever_the_inbox_holds(): void
    {
        [$customer, $user] = $this->account();

        Notification::factory()->count(60)->create([
            'customer_id' => $customer->id,
            'read_at' => null,
        ]);

        [$queries, $response] = $this->countQueries(
            fn () => $this->actingAs($user)->getJson('/api/v1/notifications/unread-count'),
        );

        $response->assertOk();
        self::assertSame(60, $response->json('data.unread'));

        /*
         * The badge is drawn on every page. Two queries: the session's user and
         * the count. Downloading the inbox to count it is the thing this
         * endpoint exists to avoid.
         */
        self::assertLessThan(6, $queries);
    }

    #[Test]
    public function a_category_filter_reads_fewer_branches_than_the_whole_feed(): void
    {
        [$customer, $user] = $this->account();
        $this->accountWith($customer, $user, machines: 10, eventsEach: 5);

        [$allQueries] = $this->countQueries(
            fn () => $this->actingAs($user)->getJson('/api/v1/activity'),
        );

        [$filteredQueries, $filtered] = $this->countQueries(
            fn () => $this->actingAs($user)->getJson('/api/v1/activity?category=support'),
        );

        $filtered->assertOk();

        /*
         * Filtering chooses branches rather than filtering the union, so
         * asking about support reads one source instead of ten. Asserted as an
         * inequality because the exact counts depend on which resource
         * families a page happens to contain.
         */
        self::assertLessThan(
            $allQueries,
            $filteredQueries,
            'A category filter did not reduce the number of sources read.',
        );
    }
}
