<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;

/**
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'actor_type' => 'system',
            'actor_label' => null,
            'action' => AuditAction::DriftAcknowledged,
            'subject_type' => null,
            'subject_id' => null,
            'customer_id' => null,
            'context' => null,
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ];
    }
}
