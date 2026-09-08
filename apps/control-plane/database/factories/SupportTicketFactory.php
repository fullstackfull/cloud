<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'reference' => 'LYN-'.now()->format('ym').'-'.Str::upper(Str::random(6)),
            'subject' => 'Cannot reach my server over SSH',
            'category' => TicketCategory::Technical,
            // Open, not resolved: open is what a ticket is for the part of its
            // life anybody cares about, and a factory whose default is a dead
            // ticket makes every test say so explicitly.
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
            'last_reply_at' => now(),
            'last_reply_by' => MessageAuthorKind::Customer,
        ];
    }

    public function waitingForCustomer(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::WaitingForCustomer,
            'last_reply_by' => MessageAuthorKind::Operator,
            'first_responded_at' => now()->subHour(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now()->subHour(),
            'first_responded_at' => now()->subHours(2),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => TicketStatus::Closed,
            'closed_at' => now()->subHour(),
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (): array => ['priority' => TicketPriority::Urgent]);
    }
}
