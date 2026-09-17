<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Lynomia\Modules\Ipam\Application\Actions\WithdrawReverseDnsRecords;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The PTR of an address that changed hands, and the thing that finally takes
 * it back.
 *
 * ---------------------------------------------------------------------------
 * The intent nothing acted on
 * ---------------------------------------------------------------------------
 *
 * `IpAllocator::withdrawReverseDns()` marked a released address's record
 * `removing` and said so in its own comment: "Publishing and withdrawing PTRs
 * needs a DNS provider client that this module does not yet have, so this
 * leaves a row a reconciler (or an operator) can act on rather than a record
 * that silently stays live."
 *
 * There was no reconciler, and `ReverseDnsProvider` had no operation one could
 * have called: `clear_ptr` was declared by the provider category, required by
 * the VPS and dedicated products, and modelled by nothing. So every address
 * this platform ever released went on answering with the last holder's
 * hostname — their company name in a stranger's mail headers, and a
 * forward/reverse mismatch that bounces the new customer's mail. The
 * quarantine that holds an address back between customers exists to stop that
 * inheritance; the PTR walked through it.
 *
 * `RecycledAddressReverseDnsTest` already proves the platform does not SHOW
 * the old hostname to the next holder. This proves it stops being published.
 */
final class ReverseDnsWithdrawalTest extends IpamApiTestCase
{
    #[Test]
    public function releasing_an_address_takes_its_ptr_off_the_provider(): void
    {
        [$customer] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.80');

        // Published for real at the controlled provider, so "it is gone" is a
        // statement about the provider and not about a database column.
        $provider = $this->fakeDnsProvider();
        $provider->publish(
            IpAddressValue::fromString('203.0.113.80'),
            Hostname::fromString('mail.first-customer.example.com'),
        );

        ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.first-customer.example.com',
            'status' => ReverseDnsStatus::Active,
        ]);

        $this->assertSame('mail.first-customer.example.com', $provider->publishedFor('203.0.113.80'));

        $this->app->make(IpAllocator::class)
            ->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        // The intent, which is all that used to happen.
        $this->assertSame(
            ReverseDnsStatus::Removing,
            ReverseDnsRecord::query()->where('ip_address_id', $assignment->ip_address_id)->sole()->status,
        );

        $sweep = $this->app->make(WithdrawReverseDnsRecords::class)->execute();

        $this->assertSame(['considered' => 1, 'withdrawn' => 1, 'failed' => 0], $sweep);

        // Gone at the provider, and gone from the table: the row is unique per
        // address, so one left behind is the previous holder's hostname in the
        // relation the next holder's assignment loads.
        $this->assertNull($provider->publishedFor('203.0.113.80'));
        $this->assertSame(0, ReverseDnsRecord::query()->where('ip_address_id', $assignment->ip_address_id)->count());
    }

    #[Test]
    public function a_provider_that_refuses_keeps_the_record_for_the_next_run(): void
    {
        /*
         * A refusal and a timeout are handled the same way here, and that is
         * the opposite of the rule for publishing. Publishing twice can put a
         * record somewhere it was not wanted; asking twice for a record to be
         * gone converges on gone. So the row stays `removing` with the reason
         * recorded, and the next sweep asks again.
         */
        [$customer] = $this->accountWith();

        /*
         * The fault is chosen by the address, and it has to be: a withdrawal
         * names no hostname, so the only thing the caller picks is which
         * address. The controlled provider refuses this one, which is in the
         * documentation range every example address here comes from.
         */
        $assignment = $this->assignmentFor(
            $customer,
            address: FakeReverseDnsProvider::REFUSED_WITHDRAWAL_ADDRESS,
        );

        $provider = $this->fakeDnsProvider();
        $provider->publish(
            IpAddressValue::fromString(FakeReverseDnsProvider::REFUSED_WITHDRAWAL_ADDRESS),
            Hostname::fromString('mail.first-customer.example.com'),
        );

        $record = ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.first-customer.example.com',
            'status' => ReverseDnsStatus::Removing,
        ]);

        $sweep = $this->app->make(WithdrawReverseDnsRecords::class)->execute();

        $this->assertSame(['considered' => 1, 'withdrawn' => 0, 'failed' => 1], $sweep);

        $kept = $record->fresh();

        $this->assertSame(ReverseDnsStatus::Removing, $kept?->status);
        $this->assertNotNull($kept->last_error, 'the withdrawal failed and nothing says why');

        // And the credential the controlled zone client quotes back at itself
        // does not come to rest in a column support can read.
        $this->assertStringNotContainsString('fake-zone-token', (string) $kept->last_error);

        // And the record is still published, which is the point of keeping the
        // row: the sweep has to come back for it.
        $this->assertSame(
            'mail.first-customer.example.com',
            $provider->publishedFor(FakeReverseDnsProvider::REFUSED_WITHDRAWAL_ADDRESS),
        );
    }

    #[Test]
    public function a_sweep_with_nothing_to_withdraw_does_nothing(): void
    {
        // Runs every ten minutes for the life of the platform, so "nothing to
        // do" has to be free and silent rather than an error.
        $this->assertSame(
            ['considered' => 0, 'withdrawn' => 0, 'failed' => 0],
            $this->app->make(WithdrawReverseDnsRecords::class)->execute(),
        );
    }

    #[Test]
    public function withdrawing_an_address_that_never_had_a_record_is_success(): void
    {
        /*
         * The idempotence the contract promises, and the reason it is not an
         * error: a withdrawal is a statement about the end state — this
         * address answers for nobody — and an adapter that complained about
         * work already done would leave a finished row retried for ever.
         */
        [$customer] = $this->accountWith();

        $assignment = $this->assignmentFor($customer, address: '203.0.113.82');

        ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'never-published.example.com',
            'status' => ReverseDnsStatus::Removing,
        ]);

        $sweep = $this->app->make(WithdrawReverseDnsRecords::class)->execute();

        $this->assertSame(['considered' => 1, 'withdrawn' => 1, 'failed' => 0], $sweep);
        $this->assertSame(0, ReverseDnsRecord::query()->count());
    }
}
