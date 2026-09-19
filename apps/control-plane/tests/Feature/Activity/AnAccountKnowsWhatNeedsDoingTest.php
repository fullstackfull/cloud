<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard the audit found empty.
 *
 * AR-6: the first page of the portal was two account cards and a
 * country-change form. The seeded account had two open invoices, a ticket
 * waiting for the customer, a subscription ending and an unread notification,
 * and the dashboard mentioned none of them; it also rendered `null` while the
 * user loaded and had no error state.
 *
 * BD-4 decided the shape — attention first — so what is asserted here is that
 * the server decides priority, that money in two currencies is never added up,
 * that a healthy account is not given things to worry about, and that every
 * attention item leads to the exact thing it is about.
 */
final class AnAccountKnowsWhatNeedsDoingTest extends TestCase
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

    private function machineFor(Customer $customer, string $hostname): VirtualMachine
    {
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        $cluster = ComputeCluster::factory()->create();
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->id]);

        return VirtualMachine::factory()
            ->onNode($node)
            ->forService($service)
            ->create(['hostname' => $hostname]);
    }

    private function invoiceFor(Customer $customer, string $number, string $currency, int $dueMinor, string $dueAt): Invoice
    {
        return Invoice::factory()->create([
            'customer_id' => $customer->id,
            'number' => $number,
            'currency' => $currency,
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => $dueMinor,
            'total_minor' => $dueMinor,
            /*
             * `amount_due_minor` is not assigned: it is a generated column,
             * derived by the database from the total less what was paid and
             * refunded. Wave 2 made it so precisely to stop a row existing in
             * which the amount owing disagrees with the arithmetic, and this
             * test would be worthless if it could set the answer directly.
             */
            'amount_paid_minor' => 0,
            'issued_at' => now()->subDays(3),
            'due_at' => $dueAt,
        ]);
    }

    #[Test]
    public function a_new_account_is_told_it_has_nothing_rather_than_shown_fake_numbers(): void
    {
        [, $user] = $this->account();

        $response = $this->actingAs($user)->getJson('/api/v1/me/overview');

        $response->assertOk();

        self::assertSame([], $response->json('data.attention'));
        self::assertSame(0, $response->json('data.services.total'));
        self::assertSame([], $response->json('data.billing.due'));
        self::assertSame([], $response->json('data.renewals'));
        self::assertSame(0, $response->json('data.unread_notifications'));
        self::assertSame([], $response->json('data.recent.activity'));
    }

    #[Test]
    public function an_overdue_invoice_outranks_a_name_expiring_next_month(): void
    {
        [$customer, $user] = $this->account();

        // A warning: expires inside the window, still renewable at the
        // ordinary price.
        Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'later.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'expires_at' => now()->addDays(20),
        ]);

        // A critical: money already late.
        $invoice = $this->invoiceFor($customer, 'INV-OVERDUE', 'KWD', 12500, (string) now()->subDays(4));

        $attention = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.attention');

        self::assertCount(2, $attention);

        // Severity decides, and the server decides severity.
        self::assertSame('attention.invoice.overdue', $attention[0]['kind']);
        self::assertSame('critical', $attention[0]['severity']);
        self::assertSame('attention.domain.expiring', $attention[1]['kind']);
        self::assertSame('warning', $attention[1]['severity']);

        // And it leads to the exact invoice, not to the invoices list.
        self::assertSame('invoice', $attention[0]['resource']['kind']);
        self::assertSame((string) $invoice->getKey(), $attention[0]['resource']['id']);
        self::assertSame('INV-OVERDUE', $attention[0]['reference']);
    }

    #[Test]
    public function money_owed_in_two_currencies_is_never_added_together(): void
    {
        [$customer, $user] = $this->account();

        $this->invoiceFor($customer, 'INV-KWD', 'KWD', 12500, (string) now()->addDays(3));
        $this->invoiceFor($customer, 'INV-USD', 'USD', 2500, (string) now()->addDays(3));

        $due = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.billing.due');

        self::assertCount(2, $due);

        // One amount per currency, each with its own currency on it.
        $byCurrency = [];

        foreach ($due as $entry) {
            $byCurrency[$entry['amount']['currency']] = $entry['amount']['minor_units'];
        }

        self::assertSame(['KWD' => 12500, 'USD' => 2500], $byCurrency);

        /*
         * And no total anywhere. 12.500 KWD + 25.00 USD = 37.50 is not a
         * number, and a dashboard that printed one would be believed.
         */
        $billing = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.billing');
        self::assertArrayNotHasKey('total', $billing);
    }

    #[Test]
    public function an_operation_waiting_on_review_links_the_machine_it_is_about(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'stuck-01');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::ReinstallVps,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $attention = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.attention');

        self::assertCount(1, $attention);
        self::assertSame('attention.operation.needsReview', $attention[0]['kind']);
        self::assertSame('critical', $attention[0]['severity']);

        // The machine, resolved from the service the job carries.
        self::assertSame('vps', $attention[0]['resource']['kind']);
        self::assertSame((string) $machine->getKey(), $attention[0]['resource']['id']);
        self::assertSame('stuck-01', $attention[0]['resource']['identity']);
    }

    #[Test]
    public function a_healthy_account_is_given_nothing_to_worry_about(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'healthy-01');

        // A finished reboot, a paid invoice and a name with a year left.
        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'number' => 'INV-PAID',
            'currency' => 'KWD',
            'status' => InvoiceStatus::Paid,
            'amount_paid_minor' => 9000,
            'subtotal_minor' => 9000,
            'total_minor' => 9000,
            'issued_at' => now()->subDays(10),
            'due_at' => now()->subDays(3),
            'paid_at' => now()->subDays(2),
        ]);

        Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'fine.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'expires_at' => now()->addMonths(10),
        ]);

        $data = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data');

        // Nothing needs attention, and the account is clearly not empty.
        self::assertSame([], $data['attention']);
        self::assertSame(1, $data['services']['total']);
        self::assertSame([], $data['billing']['due']);
        self::assertNotEmpty($data['recent']['activity']);
    }

    #[Test]
    public function a_lapsed_name_is_more_urgent_than_one_merely_expiring(): void
    {
        [$customer, $user] = $this->account();

        Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'lapsed.test',
            'tld' => 'test',
            'state' => DomainState::Redemption,
            'provider' => 'fake',
            'expires_at' => now()->subDays(5),
        ]);

        $attention = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.attention');

        self::assertSame('attention.domain.lapsed', $attention[0]['kind']);
        self::assertSame('critical', $attention[0]['severity']);
    }

    #[Test]
    public function the_dashboards_recent_activity_is_the_account_feeds_own_rows(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'shared-01');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'requested_by_user_id' => $user->id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        $overview = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.recent.activity');
        $feed = $this->actingAs($user)->getJson('/api/v1/activity')->json('data');

        // Same source, so the same row with the same words: the dashboard has
        // no history logic of its own.
        self::assertSame($feed[0]['id'], $overview[0]['id']);
        self::assertSame($feed[0]['message_code'], $overview[0]['message_code']);
        self::assertSame($feed[0]['state'], $overview[0]['state']);
    }

    #[Test]
    public function a_renewal_with_no_authoritative_price_carries_no_amount(): void
    {
        [$customer, $user] = $this->account();

        Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'renews.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'expires_at' => now()->addDays(30),
        ]);

        $renewals = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.renewals');

        self::assertCount(1, $renewals);
        self::assertSame('domain', $renewals[0]['kind']);
        self::assertSame('renews.test', $renewals[0]['resource']['identity']);

        /*
         * A domain's renewal price is the catalogue's at the moment of
         * renewal. Printing a number here would be quoting a guess, so the
         * field is null and the portal says the date without a price.
         */
        self::assertNull($renewals[0]['amount']);
    }

    #[Test]
    public function another_tenants_overview_is_unreachable(): void
    {
        [, $mine] = $this->account();
        [$theirs] = $this->account();

        $theirMachine = $this->machineFor($theirs, 'not-mine-01');
        $this->invoiceFor($theirs, 'INV-THEIRS', 'KWD', 99900, (string) now()->subDays(9));

        ProvisioningJob::factory()->create([
            'customer_id' => $theirs->id,
            'service_id' => $theirMachine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $response = $this->actingAs($mine)->getJson('/api/v1/me/overview');

        $response->assertOk();
        self::assertSame([], $response->json('data.attention'));
        self::assertSame(0, $response->json('data.services.total'));

        $body = (string) $response->getContent();
        self::assertStringNotContainsString('not-mine-01', $body);
        self::assertStringNotContainsString('INV-THEIRS', $body);
    }

    #[Test]
    public function support_waiting_for_the_customer_is_on_the_list(): void
    {
        [$customer, $user] = $this->account();

        DB::table('support_tickets')->insert([
            'id' => (string) Str::ulid(),
            'customer_id' => $customer->id,
            'opened_by_user_id' => $user->id,
            'reference' => 'TKT-1001',
            'subject' => 'Rebuild did not finish',
            'category' => 'technical',
            'status' => 'waiting_for_customer',
            'priority' => 'normal',
            'last_reply_at' => now()->subHours(6),
            'reopened_count' => 0,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHours(6),
        ]);

        $attention = $this->actingAs($user)->getJson('/api/v1/me/overview')->json('data.attention');

        self::assertCount(1, $attention);
        self::assertSame('attention.support.waitingForYou', $attention[0]['kind']);
        self::assertSame('TKT-1001', $attention[0]['reference']);
        self::assertSame('Rebuild did not finish', $attention[0]['resource']['identity']);
    }
}
