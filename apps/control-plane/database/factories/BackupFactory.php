<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Backups\Domain\Enums\BackupMode;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\BackupTrigger;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    protected $model = Backup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'service_id' => Service::factory(),
            'cluster_id' => ComputeCluster::factory(),
            'provider' => 'fake',
            // Requested and nothing more. A factory whose default is
            // "succeeded" makes it easy to write a test that never exercises
            // the only interesting part of this module, which is how a row
            // gets there.
            'state' => BackupState::Requested,
            'trigger' => BackupTrigger::Manual,
            'mode' => BackupMode::Snapshot,
            'node_name' => 'pve-01',
            'datastore' => 'pbs-test-01',
            'provider_task_id' => null,
            'poll_count' => 0,
        ];
    }

    public function running(string $taskId = 'UPID:fake:1'): self
    {
        return $this->state(fn (): array => [
            'state' => BackupState::Running,
            'provider_task_id' => $taskId,
            'started_at' => now(),
        ]);
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'state' => BackupState::Succeeded,
            'provider_task_id' => 'UPID:fake:done',
            'archive_id' => 'vzdump-qemu-'.random_int(100, 999).'-'.Str::lower(Str::random(6)).'.vma.zst',
            'size_bytes' => 1_073_741_824,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now(),
        ]);
    }

    public function needingReview(): self
    {
        return $this->state(fn (): array => [
            'state' => BackupState::NeedsReview,
            'failure_reason' => 'the provider stopped answering; whether a backup was started is unknown',
        ]);
    }
}
