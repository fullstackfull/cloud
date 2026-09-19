<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/dedicated/{server}/reinstall.
 *
 * The most destructive thing on the customer surface, and the only one whose
 * effect nobody can undo at any price. Almost everything below is a refusal.
 */
final class DedicatedServerReinstallEndpointTest extends DedicatedApiTestCase
{
    private const string KEY = 'rebuild-2026-09-06-a';

    #[Test]
    public function a_confirmed_reinstall_is_recorded_and_handed_to_the_engine(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00001']);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00001'])
            // 202: nothing is erased while the caller waits. The install runs
            // for tens of minutes and belongs to the engine, which is the only
            // part of the platform that knows not to retry one that timed out.
            ->assertStatus(202)
            ->assertJsonPath('meta.server_id', $server->id)
            ->assertJsonPath('meta.replayed', false);

        $job = ProvisioningJob::query()->sole();

        $this->assertSame($job->id, $response->json('data.id'));
        $this->assertSame(ProvisioningJobKind::ReinstallDedicated, $job->kind);
        $this->assertSame($server->id, $job->payload['dedicated_server_id'] ?? null);
        $this->assertSame('SNWIPE00001', $job->payload['serial'] ?? null);
        $this->assertSame($customer->id, $job->customer_id);

        /*
         * One attempt, against the engine's default of three. A reinstall that
         * failed halfway has already erased the disks; a second automatic pass
         * erases whatever the first one managed to lay down.
         */
        $this->assertSame(1, $job->max_attempts);

        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function nothing_physical_happens_while_the_caller_waits(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00002']);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00002'])
            ->assertStatus(202);

        /*
         * No boot override is armed and no permission to erase the machine is
         * granted from an HTTP request. A PXE authorisation is a recorded
         * decision with a short expiry, written by the engine immediately
         * before the chassis is reset — not hours earlier by a controller that
         * has already returned a response.
         */
        $this->assertSame(0, PxeBootAuthorisation::query()->count());

        // And the machine has not been taken out of service by the request
        // that merely asked for it.
        $this->assertSame(DedicatedServerStatus::Active, $server->refresh()->status);
    }

    #[Test]
    public function a_confirmation_naming_the_wrong_machine_erases_nothing(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00003']);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNSOMEOTHER'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'dedicated.reinstall_confirmation_mismatch');

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_refusal_does_not_hand_back_the_serial_it_wanted(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00004']);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'wrong'])
            ->assertStatus(422);

        $body = $response->getContent();
        $this->assertIsString($body);

        // Echoing the expected value back would turn the confirmation into a
        // two-step form a client can fill in for itself, which is exactly the
        // automation the gate exists to prevent.
        $this->assertStringNotContainsString('SNWIPE00004', $body);
    }

    #[Test]
    public function a_missing_confirmation_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['confirm_serial']]]]);

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function a_missing_idempotency_key_is_refused_rather_than_invented(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00005']);

        // A key the server invents is unique per request, which makes every
        // retry a new reinstall — precisely the failure the key prevents.
        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00005'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected')
            ->assertJsonPath('error.details.header', 'Idempotency-Key');

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function a_key_sent_in_the_body_cannot_stand_in_for_the_header(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00006']);

        // Two places to put one key is two answers to "is this the same
        // request?", and the wrong answer erases a machine twice.
        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", [
                'confirm_serial' => 'SNWIPE00006',
                'idempotency_key' => self::KEY,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected');
    }

    #[Test]
    public function repeating_the_same_key_returns_the_first_job_rather_than_wiping_twice(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00007']);

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00007'])
            ->assertStatus(202);

        $second = $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00007'])
            ->assertStatus(202)
            ->assertJsonPath('meta.replayed', true);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ProvisioningJob::query()->count());

        // Dispatching for a job that already existed is how a job that is
        // mid-flight acquires a second worker.
        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function two_customers_choosing_the_same_key_do_not_share_a_job(): void
    {
        [$mine, $me] = $this->accountWith();
        [$theirs, $them] = $this->accountWith();

        $ours = $this->serverFor($mine, ['serial' => 'SNSHARED0001']);
        $hers = $this->serverFor($theirs, ['serial' => 'SNSHARED0002']);

        /*
         * "rebuild-1" is what everybody types.
         *
         * The engine's idempotency_key column is unique platform-wide, so a
         * key taken from the caller alone would mean the second customer's
         * reinstall silently resolved to the first customer's job: they would
         * be handed a job id for a machine they do not own, and their own
         * server would never be rebuilt. The machine's id is mixed in for
         * exactly this, and this is the test that says so.
         */
        $key = 'rebuild-1-shared';

        $first = $this->actingAs($me)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/dedicated/{$ours->id}/reinstall", ['confirm_serial' => 'SNSHARED0001'])
            ->assertStatus(202)
            ->assertJsonPath('meta.replayed', false);

        $second = $this->actingAs($them)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/dedicated/{$hers->id}/reinstall", ['confirm_serial' => 'SNSHARED0002'])
            ->assertStatus(202)
            // Not a replay of somebody else's request.
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonPath('meta.server_id', $hers->id);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, ProvisioningJob::query()->count());

        $mineJob = ProvisioningJob::query()->findOrFail((string) $first->json('data.id'));
        $theirsJob = ProvisioningJob::query()->findOrFail((string) $second->json('data.id'));

        $this->assertSame($mine->id, $mineJob->customer_id);
        $this->assertSame($theirs->id, $theirsJob->customer_id);
        $this->assertSame($hers->id, $theirsJob->payload['dedicated_server_id'] ?? null);
    }

    #[Test]
    public function a_second_reinstall_under_a_new_key_is_refused_while_the_first_is_running(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00008']);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00008'])
            ->assertStatus(202);

        // Refused, not queued behind it. A reinstall queued behind a reinstall
        // rebuilds a machine that is already being rebuilt.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-2026-09-06-b')
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00008'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dedicated.operation_refused')
            ->assertJsonPath('error.details.in_flight_kind', ProvisioningJobKind::ReinstallDedicated->value);

        $this->assertSame(1, ProvisioningJob::query()->count());
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->serverFor($theirs, ['serial' => 'SNTHEIRS0001']);

        // Even with the right serial: the machine is not in this caller's
        // relation, so it is never in hand to be confirmed against.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$hers->id}/reinstall", ['confirm_serial' => 'SNTHEIRS0001'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_machine_under_maintenance_is_refused(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, [
            'serial' => 'SNWIPE00009',
            'status' => DedicatedServerStatus::Maintenance,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00009'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dedicated.operation_refused')
            ->assertJsonPath('error.details.status', 'maintenance');

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function a_machine_that_is_still_being_installed_is_refused(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, [
            'serial' => 'SNWIPE00010',
            'status' => DedicatedServerStatus::Provisioning,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00010'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.status', 'provisioning');
    }

    #[Test]
    public function an_operating_system_that_is_no_longer_offered_cannot_be_installed_by_direct_reference(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00011']);

        $withdrawn = OsInstallProfile::factory()->create(['slug' => 'centos-7', 'is_active' => false]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", [
                'confirm_serial' => 'SNWIPE00011',
                'os_profile' => $withdrawn->slug,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['os_profile']]]]);

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function an_operating_system_that_is_offered_is_recorded_against_the_job(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00012']);

        $profile = OsInstallProfile::factory()->create(['slug' => 'ubuntu-2404-lts', 'is_active' => true]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", [
                'confirm_serial' => 'SNWIPE00012',
                'os_profile' => 'ubuntu-2404-lts',
            ])
            ->assertStatus(202);

        $job = ProvisioningJob::query()->sole();

        $this->assertSame($profile->id, $job->payload['os_install_profile_id'] ?? null);
        $this->assertSame('ubuntu-2404-lts', $job->payload['os_install_profile_slug'] ?? null);
    }

    #[Test]
    public function a_member_who_may_only_watch_the_account_cannot_erase_anything(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $watcher = $this->memberOf($customer, CustomerRole::Member);

        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00013']);

        $this->actingAs($watcher)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00013'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    #[Test]
    public function the_receipt_carries_none_of_the_engines_internals(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNWIPE00014']);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', self::KEY)
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNWIPE00014'])
            ->assertStatus(202);

        /** @var array<string, mixed> $document */
        $document = (array) $response->json('data');

        foreach ([
            'payload',
            'result',
            'provider',
            'last_error',
            'remote_job_id',
            'correlation_id',
            'attempts',
            'max_attempts',
            'timeout_seconds',
            'idempotency_key',
            'customer_id',
            'service_id',
            'order_id',
            'status',
            'failure_class',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $document, $field.' is in the customer document');
        }

        $body = $response->getContent();
        $this->assertIsString($body);

        // The engine's key is derived from the caller's, hashed and prefixed.
        // Publishing it would let one client discover another's.
        $this->assertStringNotContainsString('dedicated:', $body);
        $this->assertStringNotContainsString('redfish', $body);
    }
}
