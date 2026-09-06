<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;

/**
 * The front door of the engine, where a double-clicked purchase either becomes
 * one server or two.
 */
final class CreateProvisioningJobTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    private CreateProvisioningJob $create;

    protected function setUp(): void
    {
        parent::setUp();

        $this->create = app(CreateProvisioningJob::class);
    }

    #[Test]
    public function a_job_is_created_once_for_a_repeated_idempotency_key(): void
    {
        $service = Service::factory()->create();

        $first = $this->create->execute($this->request($service, 'order-item:42:create_vps'));
        $second = $this->create->execute($this->request($service, 'order-item:42:create_vps'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProvisioningJob::query()->count());

        // The flag is what a caller uses to decide whether to dispatch a
        // worker; dispatching for a job that already exists is how a job that
        // is mid-flight acquires a second one.
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
    }

    #[Test]
    public function a_repeated_call_returns_the_existing_job_untouched(): void
    {
        $service = Service::factory()->create();

        $first = $this->create->execute($this->request($service, 'order-item:42:create_vps', ['hostname' => 'first']));

        // The same intent, described differently. Honouring the key rather
        // than the payload is the point: rewriting an in-flight job to match a
        // later request would change what a worker is already building.
        $second = $this->create->execute($this->request($service, 'order-item:42:create_vps', ['hostname' => 'second']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('first', $second->payload['hostname'] ?? null);
        $this->assertSame(ProvisioningJobStatus::Queued, $second->status);
    }

    #[Test]
    public function two_workers_racing_the_same_key_still_produce_one_job(): void
    {
        $service = Service::factory()->create();
        $interleaved = false;

        /*
         * A second caller runs to completion in the middle of the first one,
         * with the same key. Its insert meets the unique index and is dropped
         * silently rather than raising — which is the property that lets the
         * engine treat "someone got here first" as success instead of as an
         * error to handle. A check-then-insert would have to handle it, and
         * the handling is where the second server comes from.
         */
        DB::listen(function () use (&$interleaved, $service): void {
            if ($interleaved) {
                return;
            }

            $interleaved = true;

            $this->create->execute($this->request($service, 'order-item:42:create_vps'));
        });

        $job = $this->create->execute($this->request($service, 'order-item:42:create_vps'));

        $this->assertTrue($interleaved, 'The interleaved caller never ran.');
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(1, ProvisioningJob::query()->count());
        $this->assertSame('order-item:42:create_vps', $job->idempotency_key);
    }

    #[Test]
    public function distinct_intents_are_distinct_jobs(): void
    {
        $service = Service::factory()->create();

        $this->create->execute($this->request($service, 'service:'.$service->id.':suspend'));
        $this->create->execute($this->request($service, 'service:'.$service->id.':unsuspend'));

        $this->assertSame(2, ProvisioningJob::query()->count());
    }

    #[Test]
    public function a_job_takes_its_timeout_from_the_kind_it_performs(): void
    {
        $service = Service::factory()->create();

        $job = $this->create->execute(ProvisioningJobRequest::forService(
            service: $service,
            kind: ProvisioningJobKind::ProvisionDedicated,
            provider: 'fake',
        ));

        // A dedicated install and a power-on must not be held to one clock.
        $this->assertSame(5400, $job->timeout_seconds);
        $this->assertSame(ProvisioningJobKind::ProvisionDedicated, $job->kind);
    }

    #[Test]
    public function a_credential_in_the_payload_is_never_persisted(): void
    {
        $service = Service::factory()->create();

        $job = $this->create->execute($this->request($service, 'secrets', [
            'hostname' => 'vps-01',
            'root_password' => 'hunter2-correct-horse',
        ]));

        $stored = (string) DB::table('provisioning_jobs')->where('id', $job->id)->value('payload');

        $this->assertStringNotContainsString('hunter2-correct-horse', $stored);
        $this->assertSame('[redacted]', $job->fresh()?->payload['root_password'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function request(Service $service, string $key, array $payload = ['hostname' => 'vps-01']): ProvisioningJobRequest
    {
        return ProvisioningJobRequest::forService(
            service: $service,
            kind: ProvisioningJobKind::CreateVps,
            provider: 'fake',
            payload: $payload,
            idempotencyKey: $key,
        );
    }
}
