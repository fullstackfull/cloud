<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Activity\Domain\Enums\ActorType;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;
use Lynomia\Modules\Shared\Domain\Enums\RetryAdvice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The account-wide history the audit found missing.
 *
 * AR-13: `GET /services/{id}/events` existed and no screen called it, and
 * there was nothing at all above one service — so a team of three could not
 * see who rebooted what, and a customer could not find out why a build failed
 * once the notification had been read.
 *
 * What is asserted here is the feed's contract, and the four things about it
 * that could go wrong quietly: that it unions the sources rather than showing
 * one of them, that the order is total, that another tenant's history is
 * unreachable, and that not one provider sentence, node name or internal id
 * comes with it.
 */
final class AnAccountKnowsWhatHappenedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create(['name' => 'Ahmed Al-Sabah']);

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

    #[Test]
    public function a_reboot_says_what_happened_to_which_machine_and_who_asked(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-01');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'requested_by_user_id' => $user->id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/activity');

        $response->assertOk();

        $row = $response->json('data.0');

        self::assertSame('activity.vps.restarted', $row['message_code']);
        self::assertSame(CustomerOperationState::Succeeded->value, $row['state']);
        self::assertTrue($row['is_terminal']);

        // The audit's own question, answered: who rebooted it.
        self::assertSame(ActorType::CustomerUser->value, $row['actor']['type']);
        self::assertSame('Ahmed Al-Sabah', $row['actor']['display_name']);

        /*
         * And the row leads to the machine, not to the service. A link built
         * from a service id would open a page for a resource that does not
         * exist, which is the trap Wave 3 found before shipping.
         */
        self::assertSame('vps', $row['resource']['kind']);
        self::assertSame((string) $machine->getKey(), $row['resource']['id']);
        self::assertSame('web-kw-01', $row['resource']['identity']);
    }

    #[Test]
    public function marking_every_notification_read_erases_nothing_from_the_history(): void
    {
        /*
         * Notifications are not the activity database. They carry a `read_at`;
         * history does not, because a customer who clears their inbox has not
         * undone anything that happened to their account.
         *
         * Asserted rather than argued: the same page, before and after the
         * inbox is emptied, has to be the same page.
         */
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-09');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'requested_by_user_id' => $user->id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        Notification::factory()->count(3)->create([
            'customer_id' => $customer->id,
            'read_at' => null,
        ]);

        $before = $this->actingAs($user)->getJson('/api/v1/activity');
        $before->assertOk();

        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertOk();

        // The inbox really is empty now, so the second read is a real test.
        $this->actingAs($user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $after = $this->actingAs($user)->getJson('/api/v1/activity');
        $after->assertOk();

        self::assertSame($before->json('data'), $after->json('data'));
        self::assertSame('activity.vps.restarted', $after->json('data.0.message_code'));
    }

    #[Test]
    public function work_the_platform_started_itself_is_not_attributed_to_a_person(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-02');

        // No requester: the build that follows a paid order.
        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'requested_by_user_id' => null,
            'kind' => ProvisioningJobKind::CreateVps,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        $row = $this->actingAs($user)->getJson('/api/v1/activity')->json('data.0');

        self::assertSame(ActorType::System->value, $row['actor']['type']);
        self::assertNull($row['actor']['display_name']);
    }

    #[Test]
    public function the_feed_unions_its_sources_in_time_order(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-03');

        $domain = Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'example.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
        ]);

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
            'created_at' => now()->subHours(2),
        ]);

        DB::table('domain_operations')->insert([
            'id' => (string) Str::ulid(),
            'domain_id' => $domain->id,
            'customer_id' => $customer->id,
            'name' => 'example.test',
            'kind' => 'renew',
            'state' => 'completed',
            'term_years' => 1,
            'currency' => 'KWD',
            'price_minor' => 3500,
            'cost_minor' => 3000,
            'idempotency_key' => 'test:renew:1',
            'provider' => 'fake',
            'attempts' => 1,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $data = $this->actingAs($user)->getJson('/api/v1/activity')->json('data');

        self::assertCount(2, $data);

        // Newest first, across two different tables.
        self::assertSame('activity.domain.renewed', $data[0]['message_code']);
        self::assertSame('example.test', $data[0]['resource']['identity']);
        self::assertSame('activity.vps.restarted', $data[1]['message_code']);
    }

    #[Test]
    public function an_indeterminate_registrar_operation_is_never_reported_as_failed(): void
    {
        [$customer, $user] = $this->account();

        $domain = Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'unsure.test',
            'tld' => 'test',
            'state' => DomainState::Indeterminate,
            'provider' => 'fake',
        ]);

        DB::table('domain_operations')->insert([
            'id' => (string) Str::ulid(),
            'domain_id' => $domain->id,
            'customer_id' => $customer->id,
            'name' => 'unsure.test',
            'kind' => 'register',
            'state' => 'indeterminate',
            'term_years' => 1,
            'currency' => 'KWD',
            'price_minor' => 3500,
            'cost_minor' => 3000,
            'idempotency_key' => 'test:register:1',
            'provider' => 'fake',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->actingAs($user)->getJson('/api/v1/activity')->json('data.0');

        self::assertSame(CustomerOperationState::Indeterminate->value, $row['state']);
        self::assertTrue($row['is_terminal']);
        self::assertTrue($row['needs_attention']);

        /*
         * The whole point. An unknown registrar result must not offer a retry:
         * the registration may already have happened, and asking again is how
         * a customer buys the same name twice.
         */
        self::assertSame(RetryAdvice::SupportRequired->value, $row['retry_advice']);
    }

    #[Test]
    public function another_accounts_history_is_not_readable(): void
    {
        [, $mine] = $this->account();
        [$theirs, $theirUser] = $this->account();

        $theirMachine = $this->machineFor($theirs, 'not-mine-01');

        ProvisioningJob::factory()->create([
            'customer_id' => $theirs->id,
            'service_id' => $theirMachine->service_id,
            'requested_by_user_id' => $theirUser->id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        $response = $this->actingAs($mine)->getJson('/api/v1/activity');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');

        // Not the hostname, not the other account's user, nothing.
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('not-mine-01', $body);
        self::assertStringNotContainsString((string) $theirUser->name, $body);
    }

    #[Test]
    public function nothing_operational_travels_with_a_row(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-04');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::NeedsReview,
            'provider' => 'proxmox',
            'remote_job_id' => 'UPID:pve-node-03:0000ABCD',
            'last_error' => 'clone failed on datastore ceph-01: 500 timeout',
            'failure_class' => 'permanent',
            'payload' => ['power_action' => 'restart', 'node' => 'pve-node-03'],
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/activity');

        $body = (string) $response->getContent();

        foreach ([
            'proxmox',
            'UPID',
            'pve-node-03',
            'ceph-01',
            'clone failed',
            'datastore',
        ] as $operational) {
            self::assertStringNotContainsString(
                $operational,
                $body,
                sprintf('The activity feed published "%s".', $operational),
            );
        }

        // And the row still says the useful thing: somebody has to look at it.
        $row = $response->json('data.0');
        self::assertSame(CustomerOperationState::NeedsReview->value, $row['state']);
        self::assertSame(RetryAdvice::SupportRequired->value, $row['retry_advice']);

        // The internal actor id is not published either — only a name would be.
        self::assertArrayNotHasKey('actor_user_id', $row);
    }

    #[Test]
    public function a_page_is_bounded_and_its_cursor_walks_the_whole_history(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-05');

        for ($i = 0; $i < 7; $i++) {
            ProvisioningJob::factory()->create([
                'customer_id' => $customer->id,
                'service_id' => $machine->service_id,
                'kind' => ProvisioningJobKind::Restart,
                'status' => ProvisioningJobStatus::Succeeded,
                'idempotency_key' => 'restart:'.$i,
                'created_at' => now()->subMinutes(7 - $i),
            ]);
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->actingAs($user)->getJson(
                '/api/v1/activity?per_page=3'.($cursor === null ? '' : '&cursor='.urlencode($cursor)),
            );

            $response->assertOk();

            foreach ($response->json('data') as $row) {
                $seen[] = $row['id'];
            }

            $cursor = $response->json('meta.next_cursor');
            $pages++;

            self::assertLessThan(6, $pages, 'The cursor did not terminate.');
        } while ($cursor !== null);

        // Every row exactly once: no duplicate across a page boundary, and
        // nothing skipped.
        self::assertCount(7, $seen);
        self::assertCount(7, array_unique($seen));
    }

    #[Test]
    public function an_unreadable_cursor_returns_the_newest_page_rather_than_an_error(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-06');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/activity?cursor=not-a-cursor')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * Cursors that decode, and still cannot be read as a position in the
     * feed. Each is the base64url of what the server would find inside it.
     * `{between}` is a well-formed timestamp between the two events the test
     * writes, so a cursor whose id alone is unreadable, read anyway, would
     * answer one row rather than both.
     *
     * @return iterable<string, array{string}>
     */
    public static function cursorsTheServerCannotRead(): iterable
    {
        yield 'an id that is not UTF-8' => ["{between}|\xff\xfe"];
        yield 'a timestamp that is not UTF-8' => ["\xff|provisioning_job:01jx"];
        yield 'a NUL in the id' => ["{between}|provisioning_job:a\x00b"];
        yield 'a line break in the id' => ["{between}|provisioning_job:a\nb"];
        yield 'a timestamp in the year 3170843' => ['@99999999999999|provisioning_job:01jx'];
        yield 'a five-digit year' => ['+10000-01-01T00:00:00+00:00|provisioning_job:01jx'];
        yield 'the year zero' => ['0000-01-01T00:00:00+00:00|provisioning_job:01jx'];
        yield 'a day that does not exist' => ['2026-02-31T00:00:00+00:00|provisioning_job:01jx'];
        yield 'a relative date' => ['last monday|provisioning_job:01jx'];
        yield 'an id with no source' => ['{between}|01jx'];
        yield 'an id with a space' => ['{between}|provisioning_job:01 jx'];
    }

    /**
     * The published `cursor` parameter says a cursor the server cannot read
     * "is answered with the newest page rather than refused", and so does
     * `CustomerActivity::page()`. An id that was not UTF-8 answered 500
     * (PostgreSQL refused the bytes), as did the year zero, and a NUL in the
     * id or a timestamp in the year 3170843 answered an empty page.
     */
    #[Test]
    #[DataProvider('cursorsTheServerCannotRead')]
    public function a_cursor_that_decodes_to_something_unreadable_is_answered_with_the_newest_page(string $inside): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-16');

        for ($i = 0; $i < 2; $i++) {
            ProvisioningJob::factory()->create([
                'customer_id' => $customer->id,
                'service_id' => $machine->service_id,
                'kind' => ProvisioningJobKind::Restart,
                'status' => ProvisioningJobStatus::Succeeded,
                'idempotency_key' => 'unreadable:'.$i,
                'created_at' => now()->subMinutes(2 - $i),
            ]);
        }

        $newest = $this->actingAs($user)->getJson('/api/v1/activity')->assertOk()->json('data.*.id');
        self::assertCount(2, $newest);

        $inside = str_replace('{between}', now()->subSeconds(90)->toIso8601String(), $inside);
        $cursor = rtrim(strtr(base64_encode($inside), '+/', '-_'), '=');

        $this->actingAs($user)
            ->getJson('/api/v1/activity?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonPath('data.*.id', $newest)
            ->assertJsonPath('meta.next_cursor', null);
    }

    /**
     * The other side of the one above: a cursor the server can read is a
     * position, and one older than everything is an empty page, not the
     * newest one. Without this, reading every cursor as absent would pass.
     */
    #[Test]
    public function a_readable_cursor_older_than_everything_is_an_empty_page(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-17');

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        $cursor = rtrim(strtr(base64_encode('2000-01-01T00:00:00+00:00|provisioning_job:01jx'), '+/', '-_'), '=');

        $this->actingAs($user)
            ->getJson('/api/v1/activity?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_category_filter_is_answered_in_sql_rather_than_by_trimming_a_page(): void
    {
        [$customer, $user] = $this->account();
        $machine = $this->machineFor($customer, 'web-kw-07');

        $domain = Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'filtered.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
        ]);

        ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Succeeded,
        ]);

        DB::table('domain_operations')->insert([
            'id' => (string) Str::ulid(),
            'domain_id' => $domain->id,
            'customer_id' => $customer->id,
            'name' => 'filtered.test',
            'kind' => 'renew',
            'state' => 'completed',
            'term_years' => 1,
            'currency' => 'KWD',
            'price_minor' => 3500,
            'cost_minor' => 3000,
            'idempotency_key' => 'test:renew:2',
            'provider' => 'fake',
            'attempts' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cloud = $this->actingAs($user)->getJson('/api/v1/activity?category=cloud');
        $cloud->assertOk()->assertJsonCount(1, 'data');
        self::assertSame('activity.vps.restarted', $cloud->json('data.0.message_code'));

        $domains = $this->actingAs($user)->getJson('/api/v1/activity?category=domains');
        $domains->assertOk()->assertJsonCount(1, 'data');
        self::assertSame('activity.domain.renewed', $domains->json('data.0.message_code'));

        // A value the enum does not know is refused, rather than quietly
        // meaning "everything".
        $this->actingAs($user)
            ->getJson('/api/v1/activity?category=not-a-category')
            ->assertStatus(422);
    }
}
