<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * @extends Factory<ProvisioningJob>
 */
class ProvisioningJobFactory extends Factory
{
    protected $model = ProvisioningJob::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Unique by construction: the column's unique index is the whole
            // point of the model, so a factory that collided would fail tests
            // for a reason that has nothing to do with what they assert.
            'idempotency_key' => 'job:'.Str::lower((string) Str::ulid()),
            'kind' => ProvisioningJobKind::CreateVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'attempts' => 0,
            'max_attempts' => 3,
            'timeout_seconds' => 900,
            'payload' => ['hostname' => 'vps-'.Str::lower(Str::random(6))],
        ];
    }

    public function kind(ProvisioningJobKind $kind): static
    {
        return $this->state(fn (): array => [
            'kind' => $kind,
            'timeout_seconds' => $kind->defaultTimeoutSeconds(),
        ]);
    }

    public function status(ProvisioningJobStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * A job a worker has already claimed, optionally one that started long
     * enough ago to be stale.
     */
    public function running(?int $startedSecondsAgo = null): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningJobStatus::Running,
            'attempts' => 1,
            'started_at' => now()->subSeconds($startedSecondsAgo ?? 5),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attempts' => $attributes['max_attempts'] ?? 3,
        ]);
    }

    public function failedWith(FailureClass $failureClass): static
    {
        return $this->state(fn (): array => [
            'status' => $failureClass->requiresReview()
                ? ProvisioningJobStatus::NeedsReview
                : ProvisioningJobStatus::Failed,
            'failure_class' => $failureClass,
            'finished_at' => now(),
        ]);
    }
}
