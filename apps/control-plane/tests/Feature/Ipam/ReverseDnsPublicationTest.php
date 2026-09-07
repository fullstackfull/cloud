<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Lynomia\Modules\Ipam\Application\Jobs\PublishReverseDnsRecord;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * What happens after the endpoint has answered: the worker, and the provider.
 *
 * The provider here is the fake. The real driver for this platform is
 * Cloudflare — `DNS_PROVIDER` in .env, read as `billing.providers.dns` — whose
 * API token is not present in this environment, and no Cloudflare adapter is in
 * this build at all. That is stated in the factory rather than papered over:
 * a deployment configured for a driver this code cannot speak raises instead of
 * quietly falling back to a fake that reports every record as published.
 *
 * The job is driven directly rather than through the queue. These tests are
 * about what the platform does with each of the provider's two answers, and a
 * queue in the middle would only decide when.
 */
final class ReverseDnsPublicationTest extends IpamApiTestCase
{
    private function publish(ReverseDnsRecord $record): void
    {
        (new PublishReverseDnsRecord((string) $record->getKey()))->handle(
            $this->app->make(ReverseDnsProviderFactory::class),
            $this->app->make(SecretRedactor::class),
        );
    }

    private function recordFor(string $hostname, string $address = '203.0.113.60'): ReverseDnsRecord
    {
        [$customer] = $this->accountWith();
        $assignment = $this->assignmentFor($customer, address: $address);

        return ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => $hostname,
            'status' => ReverseDnsStatus::Pending,
        ]);
    }

    #[Test]
    public function a_published_record_becomes_active_and_reaches_the_provider(): void
    {
        $record = $this->recordFor('mail.example.com', '203.0.113.60');

        $this->publish($record);

        $this->assertSame(ReverseDnsStatus::Active, $record->fresh()?->status);
        $this->assertNull($record->fresh()?->last_error);
        $this->assertSame('mail.example.com', $this->fakeDnsProvider()->publishedFor('203.0.113.60'));
    }

    #[Test]
    public function publishing_the_same_record_twice_leaves_one_record_at_the_provider(): void
    {
        $record = $this->recordFor('mail.example.com', '203.0.113.61');

        $this->publish($record);
        $this->publish($record);

        // The interface promises idempotence per address; a PTR appended rather
        // than replaced is a misconfiguration mail servers resolve at random.
        $this->assertSame(1, $this->fakeDnsProvider()->publishedCount());
        $this->assertSame('mail.example.com', $this->fakeDnsProvider()->publishedFor('203.0.113.61'));
    }

    #[Test]
    public function a_refusal_marks_the_record_failed_with_the_credential_stripped(): void
    {
        $record = $this->recordFor(FakeReverseDnsProvider::REFUSAL_MARKER.'.example.com', '203.0.113.62');

        $this->publish($record);

        $fresh = $record->fresh();
        $stored = (string) $fresh?->last_error;

        $this->assertSame(ReverseDnsStatus::Failed, $fresh?->status);
        $this->assertSame(0, $this->fakeDnsProvider()->publishedCount());

        // A zone client that fails quotes the request it sent, and that request
        // carried the token. last_error is read by everyone with support access.
        $this->assertStringNotContainsString('fake-zone-token', $stored);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $stored);
        // The part that explains the failure survives; only the credential goes.
        $this->assertStringContainsString('401', $stored);
    }

    #[Test]
    public function a_timeout_leaves_the_record_pending_and_is_not_retried(): void
    {
        $record = $this->recordFor(FakeReverseDnsProvider::TIMEOUT_MARKER.'.example.com', '203.0.113.63');

        $this->publish($record);

        $fresh = $record->fresh();

        /*
         * Pending, not failed. A timeout means the platform stopped waiting,
         * not that the provider stopped working: the record may be live right
         * now. "Failed" would be a claim the platform cannot support, and a
         * customer reading it would set the name again — the retry this job
         * refuses to perform on their behalf.
         */
        $this->assertSame(ReverseDnsStatus::Pending, $fresh?->status);
        $this->assertNotNull($fresh?->last_error);
        $this->assertStringContainsString('unknown', (string) $fresh?->last_error);

        // One attempt, by construction. A retry against a zone API is how one
        // address ends up with a record written twice from two attempts.
        $this->assertSame(1, (new ReflectionClass(PublishReverseDnsRecord::class))
            ->newInstanceWithoutConstructor()->tries);
    }

    #[Test]
    public function a_hostname_that_is_not_one_never_reaches_the_provider(): void
    {
        $record = $this->recordFor('mail.example.com', '203.0.113.64');

        /*
         * Written straight to the column, past the action and the form request,
         * as a repair script or an older migration might have. The job
         * validates again at the last point before the call, because "the row
         * was written by code that validates" stops being true the moment
         * anything else writes a row.
         */
        $record->forceFill(['hostname' => "evil.example.com\nx-injected: 1"])->save();

        $this->publish($record);

        $this->assertSame(0, $this->fakeDnsProvider()->publishedCount());
        $this->assertSame(ReverseDnsStatus::Failed, $record->fresh()?->status);
    }

    #[Test]
    public function a_record_that_has_gone_away_is_not_an_error(): void
    {
        $record = $this->recordFor('mail.example.com', '203.0.113.65');
        $id = (string) $record->getKey();
        $record->delete();

        // The address was released and its record went with it between the
        // request and the worker. There is nothing to publish and nothing to
        // report.
        (new PublishReverseDnsRecord($id))->handle(
            $this->app->make(ReverseDnsProviderFactory::class),
            $this->app->make(SecretRedactor::class),
        );

        $this->assertSame(0, $this->fakeDnsProvider()->publishedCount());
    }

    #[Test]
    public function a_driver_this_build_cannot_speak_is_refused_rather_than_faked(): void
    {
        config()->set('billing.providers.dns', 'cloudflare');

        // A silent fallback to the fake would publish nothing while reporting
        // every record as live — the one failure this module is arranged to
        // avoid. There is no Cloudflare adapter in this build; its credentials
        // are not available in this environment either.
        $this->expectExceptionMessage('No reverse-DNS adapter is registered for the driver "cloudflare"');

        (new ReverseDnsProviderFactory)->make();
    }

    #[Test]
    public function a_record_for_an_address_that_has_been_given_back_is_never_published(): void
    {
        [$customer] = $this->accountWith();
        $assignment = $this->assignmentFor($customer, address: '203.0.113.66');

        $record = ReverseDnsRecord::factory()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.first-customer.example.com',
            'status' => ReverseDnsStatus::Pending,
        ]);

        /*
         * The service is cancelled while the publish job is still on the queue —
         * a customer who set a name and then cancelled, or an operator who
         * reclaimed the address. The address is now in quarantine, on its way to
         * somebody else, and the name on this record is the *previous* holder's.
         * Publishing it now puts their identity on a stranger's address, which
         * is the one thing SetReverseDns refuses at the front door and which
         * nothing was checking here.
         */
        $this->app->make(IpAllocator::class)
            ->releaseAssignment($assignment, ReleaseReason::ServiceTerminated);

        $this->publish($record);

        $this->assertSame(
            0,
            $this->fakeDnsProvider()->publishedCount(),
            'A PTR naming the previous holder was published onto an address they no longer hold.',
        );
        $this->assertNotSame(ReverseDnsStatus::Active, $record->fresh()?->status);
    }

    #[Test]
    public function an_address_that_was_never_assigned_to_anybody_is_not_published_for(): void
    {
        // A record left behind on an address that is back in the pool. Nobody
        // holds it, so there is no customer whose name may go on it.
        $record = $this->recordFor('mail.example.com', '203.0.113.67');

        IpAssignment::query()->where('ip_address_id', $record->ip_address_id)->delete();

        $this->publish($record);

        $this->assertSame(0, $this->fakeDnsProvider()->publishedCount());
    }
}
