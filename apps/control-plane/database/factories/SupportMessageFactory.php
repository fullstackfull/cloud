<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * @extends Factory<SupportMessage>
 */
class SupportMessageFactory extends Factory
{
    protected $model = SupportMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => SupportTicket::factory(),
            'author_kind' => MessageAuthorKind::Customer,
            'body' => 'It has been refusing connections since about nine this morning.',
            'is_internal_note' => false,
        ];
    }

    public function fromOperator(): static
    {
        return $this->state(fn (): array => ['author_kind' => MessageAuthorKind::Operator]);
    }

    public function internalNote(): static
    {
        return $this->state(fn (): array => [
            'author_kind' => MessageAuthorKind::Operator,
            'is_internal_note' => true,
            'body' => 'Node 3 was rebooted for firmware at 08:50. Almost certainly that.',
        ]);
    }
}
