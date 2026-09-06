<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * @extends Factory<ProvisioningAttempt>
 */
class ProvisioningAttemptFactory extends Factory
{
    protected $model = ProvisioningAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provisioning_job_id' => ProvisioningJob::factory(),
            'attempt_number' => 1,
            'status' => ProvisioningJobStatus::Running,
            'created_at' => now(),
        ];
    }

    public function failed(string $code, string $message): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningJobStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'duration_ms' => 120,
        ]);
    }
}
