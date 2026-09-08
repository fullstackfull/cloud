<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = NotificationType::ServiceReady;

        return [
            'customer_id' => Customer::factory(),
            'user_id' => null,
            'type' => $type,
            'category' => $type->category(),
            'subject_type' => null,
            'subject_id' => null,
            'data' => ['service' => 'vps-'.Str::lower(Str::random(6))],
            'link' => '/vps',
            'read_at' => null,
            // Random per row: the column is unique, and a factory that made
            // two rows collide would fail for a reason unrelated to the test.
            'idempotency_key' => 'factory:'.Str::ulid(),
        ];
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(fn (): array => ['type' => $type, 'category' => $type->category()]);
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['read_at' => now()]);
    }
}
