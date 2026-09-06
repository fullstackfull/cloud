<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReverseDnsRecordTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_provider_failure_is_stored_with_its_credentials_stripped(): void
    {
        $record = ReverseDnsRecord::factory()->create();

        // A DNS client that fails routinely quotes the request it sent, and
        // that request carried the zone token. last_error is read by everyone
        // with support access.
        $record->recordFailure(
            'PATCH /zones/rdns failed: 401 {"error":"invalid"} (Authorization: Bearer sk_live_9f8a7b6c5d4e3f2a1b)',
        );

        $stored = (string) $record->fresh()?->last_error;

        $this->assertSame(ReverseDnsStatus::Failed, $record->fresh()?->status);
        $this->assertStringNotContainsString('sk_live_9f8a7b6c5d4e3f2a1b', $stored);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $stored);
        // The part that explains the failure survives; only the credential
        // goes.
        $this->assertStringContainsString('401', $stored);
    }

    #[Test]
    public function an_address_has_at_most_one_reverse_record(): void
    {
        $address = IpAddress::factory()->create();
        ReverseDnsRecord::factory()->for($address, 'ipAddress')->create();

        $caught = null;

        try {
            DB::transaction(fn (): ReverseDnsRecord => ReverseDnsRecord::factory()
                ->for($address, 'ipAddress')
                ->create(['hostname' => 'second.lynomia.test']));
        } catch (QueryException $e) {
            $caught = $e;
        }

        // Two PTRs for one address is a misconfiguration that mail servers
        // resolve inconsistently; the database refuses it outright.
        $this->assertNotNull($caught);
        $this->assertSame(1, ReverseDnsRecord::query()->where('ip_address_id', $address->id)->count());
    }
}
