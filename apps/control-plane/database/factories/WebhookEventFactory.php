<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;

/**
 * @extends Factory<WebhookEvent>
 */
class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_fake_'.Str::lower((string) Str::ulid());

        return [
            'provider' => FakePaymentProvider::NAME,
            'provider_event_id' => $eventId,
            'event_type' => 'payment.succeeded',
            'status' => WebhookEventStatus::Received,
            'attempts' => 0,
            'payload' => ['id' => $eventId, 'type' => 'payment.succeeded'],
            'signature_verified_by' => 'fake.v1',
            'provider_created_at' => now(),
        ];
    }

    public function processed(): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::Processed,
            'attempts' => 1,
            'processed_at' => now(),
        ]);
    }

    public function failed(string $error = 'the provider timed out'): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::Failed,
            'attempts' => 1,
            'last_error' => $error,
        ]);
    }
}
