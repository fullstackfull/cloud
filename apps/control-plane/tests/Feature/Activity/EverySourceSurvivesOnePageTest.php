<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Lynomia\Modules\Activity\Application\Queries\ActivityIdentities;
use Lynomia\Modules\Backups\Domain\Enums\BackupMode;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every source on one page, hydrated for real.
 *
 * This file exists because of a defect it would have caught. The feed resolves
 * the name a customer knows each resource by from that family's own table, and
 * the orders entry in that map named a `reference` column. Orders carry a
 * `number`. So the whole account feed answered 500 — not a degraded row, the
 * entire page — for any account that had ever placed an order.
 *
 * Nothing in the suite found it. Every fixture built an account out of one or
 * two source families, and hydration only touches a family that is actually on
 * the page. The browser suite found it, on an account the money journeys had
 * been shopping on, three specs deep into a full run.
 *
 * So: one account that has done one of everything, and one page that has to
 * come back whole. Plus a gate over the map itself, because a column name
 * typed from memory is exactly the kind of mistake that survives review.
 */
final class EverySourceSurvivesOnePageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_name_column_the_feed_reads_exists_in_the_schema(): void
    {
        $missing = [];

        foreach (ActivityIdentities::nameColumns() as $kind => [$table, $column]) {
            if (! Schema::hasTable($table)) {
                $missing[] = sprintf('%s: table "%s" does not exist', $kind, $table);

                continue;
            }

            if (! Schema::hasColumn($table, $column)) {
                $missing[] = sprintf('%s: "%s" has no column "%s"', $kind, $table, $column);
            }
        }

        /*
         * The cheapest possible gate on the most expensive possible mistake.
         * A misnamed column here is not a wrong label on one row; it is a
         * `QueryException` inside the hydration pass, which takes the whole
         * page down for everybody whose account contains that family.
         */
        $this->assertSame([], $missing, implode("\n", $missing));
    }

    #[Test]
    public function an_account_that_has_done_one_of_everything_can_read_its_own_page(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        $cluster = ComputeCluster::factory()->create();
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->id]);
        $machine = VirtualMachine::factory()
            ->onNode($node)
            ->forService($service)
            ->create(['hostname' => 'every-source-01']);

        // A provisioning job: the cloud branch.
        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'requested_by_user_id' => $user->id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
            'idempotency_key' => 'every-source:restart',
            'created_at' => now()->subMinutes(6),
        ]);

        // A backup: the backups branch, with its own state vocabulary.
        Backup::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'virtual_machine_id' => $machine->id,
            'state' => BackupState::Verified,
            'trigger' => BackupTrigger::Manual,
            'mode' => BackupMode::Snapshot,
            'created_at' => now()->subMinutes(5),
        ]);

        /*
         * An order and a transition on it: the branch that was broken. The
         * transition's resource is the order, and the order's name is the one
         * the hydration pass had to look up.
         */
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => 'ORD-EVERY-0001',
            'currency' => 'KWD',
            'status' => OrderStatus::Active,
        ]);

        $order->transitions()->create([
            'from_status' => OrderStatus::Paid,
            'to_status' => OrderStatus::Active,
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'created_at' => now()->subMinutes(4),
        ]);

        // An invoice: two events from one row, issued and paid.
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'number' => 'INV-EVERY-0001',
            'currency' => 'KWD',
            'status' => InvoiceStatus::Paid,
            'subtotal_minor' => 5000,
            'total_minor' => 5000,
            'amount_paid_minor' => 5000,
            'issued_at' => now()->subMinutes(3),
            'paid_at' => now()->subMinutes(2),
        ]);

        /*
         * A card that was declined before the one that worked. Both attempts
         * exist on the invoice; only the declined one is an event, because the
         * successful payment is already in the feed as the invoice being paid.
         */
        PaymentAttempt::factory()->failed()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'created_at' => now()->subMinutes(3),
        ]);

        PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 2,
            'status' => PaymentAttemptStatus::Succeeded,
            'created_at' => now()->subMinutes(2),
        ]);

        // A support request: the branch with no name column of its own.
        SupportTicket::factory()->create([
            'customer_id' => $customer->id,
            'opened_by_user_id' => $user->id,
            'reference' => 'LYN-EVERY-0001',
            'subject' => 'A question about all of this',
            'category' => TicketCategory::Technical,
            'priority' => TicketPriority::Normal,
            'status' => TicketStatus::WaitingForCustomer,
            'created_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/activity?per_page=50');

        $response->assertOk();

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json('data');

        /*
         * Seven events from six families: a restart, a backup, an order
         * transition, an invoice issued, that invoice paid, a declined card,
         * and a ticket.
         */
        self::assertGreaterThanOrEqual(7, count($rows));

        $categories = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['category'],
            $rows,
        )));

        sort($categories);

        self::assertSame(['backups', 'billing', 'cloud', 'support'], $categories);

        // Every row is a complete row: the shape the client's types promise.
        foreach ($rows as $row) {
            foreach (['id', 'occurred_at', 'category', 'message_code', 'state', 'retry_advice'] as $field) {
                self::assertArrayHasKey($field, $row);
                self::assertNotNull($row[$field], sprintf('%s was null on a %s row', $field, $row['category']));
            }
        }

        // And the order transition names its order by the number on the
        // document rather than by the id in the database.
        $orderRow = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with((string) $row['id'], 'order_transition:'),
        ));

        self::assertCount(1, $orderRow);
        self::assertSame('ORD-EVERY-0001', $orderRow[0]['resource']['identity']);

        /*
         * Exactly one payment row: the declined attempt. The successful one is
         * the invoice being paid, and two rows for one act of paying would
         * make the history read as two payments.
         */
        $paymentRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with((string) $row['id'], 'payment_failed:'),
        ));

        self::assertCount(1, $paymentRows);
        self::assertSame('failed', $paymentRows[0]['state']);
        self::assertSame('INV-EVERY-0001', $paymentRows[0]['reference']);

        // Nothing the gateway said about why, on a row that exists to say a
        // card did not work.
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('card_declined', $body);
        self::assertStringNotContainsString('issuer declined', $body);
    }

    #[Test]
    public function the_dashboard_survives_the_same_account(): void
    {
        /*
         * The attention list and the recent-activity rows read the same
         * sources, so the same missing column took the dashboard down too —
         * which is the first page of the portal.
         */
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => 'ORD-EVERY-0002',
            'currency' => 'KWD',
            'status' => OrderStatus::Active,
        ]);

        $order->transitions()->create([
            'from_status' => OrderStatus::Paid,
            'to_status' => OrderStatus::Active,
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me/overview')
            ->assertOk()
            ->assertJsonPath('data.recent.activity.0.category', 'billing');
    }
}
