<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use PHPUnit\Framework\Attributes\Test;

/**
 * One ticket, from the customer opening it to the team closing it, with every
 * step starting from whatever the previous one actually left behind.
 *
 * The single-step tests around it prove each rule. This proves the sequence,
 * which is where a support system usually goes wrong: a status somebody set by
 * hand that no longer matches who is actually waiting.
 */
final class TheWholeLifeOfATicketTest extends SupportTestCase
{
    #[Test]
    public function a_customer_asks_a_question_and_the_team_answers_it(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $agent = $this->supportAgent();

        /* ---------------------------------------------------------------
         | 1. The customer opens it.
         */
        $opened = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'Cannot reach my server over SSH',
                'body' => 'It has been refusing connections since about nine this morning.',
                'category' => 'technical',
                'priority' => 'high',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', TicketStatus::Open->value)
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonCount(1, 'data.messages');

        $ticketId = (string) $opened->json('data.id');

        // The reference is what a customer reads over a phone call, and it is
        // not the id.
        $this->assertMatchesRegularExpression('/^LYN-\d{4}-[0-9A-F]{6}$/', (string) $opened->json('data.reference'));

        // They were told it arrived. Nobody else was.
        $this->assertDatabaseHas('notifications', [
            'customer_id' => $customer->getKey(),
            'type' => NotificationType::TicketOpened->value,
        ]);

        /* ---------------------------------------------------------------
         | 2. It is in the queue, waiting on the team.
         */
        $this->actingAs($agent)->getJson('/api/admin/support/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.reference', $opened->json('data.reference'))
            ->assertJsonPath('meta.waiting_on_support', 1);

        /* ---------------------------------------------------------------
         | 3. An operator writes a note to their colleagues. It changes
         |    nothing the customer can see, and does not stop the clock.
         */
        $this->actingAs($agent)->postJson("/api/admin/support/tickets/{$ticketId}/replies", [
            'body' => 'Node 3 was rebooted for firmware at 08:50.',
            'internal_note' => true,
        ])->assertOk();

        $this->assertSame(TicketStatus::Open, SupportTicket::query()->findOrFail($ticketId)->status);
        $this->assertNull(SupportTicket::query()->findOrFail($ticketId)->first_responded_at);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson("/api/v1/support/tickets/{$ticketId}")
            ->assertOk()
            // Still one message: the note is not theirs to read.
            ->assertJsonCount(1, 'data.messages');

        /* ---------------------------------------------------------------
         | 4. The operator answers. Now it is the customer's turn.
         */
        $this->actingAs($agent)->postJson("/api/admin/support/tickets/{$ticketId}/replies", [
            'body' => 'That node was rebooted for firmware. Your machine is back up — can you confirm?',
        ])->assertOk();

        $afterReply = SupportTicket::query()->findOrFail($ticketId);
        $this->assertSame(TicketStatus::WaitingForCustomer, $afterReply->status);
        $this->assertNotNull($afterReply->first_responded_at);

        $this->assertDatabaseHas('notifications', [
            'customer_id' => $customer->getKey(),
            'type' => NotificationType::TicketReplied->value,
        ]);

        /* ---------------------------------------------------------------
         | 5. The customer replies. Back to the team.
         */
        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/support/tickets/{$ticketId}/replies", ['body' => 'Still refused at 09:40.'])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::WaitingForSupport->value)
            // Three messages on their side; four in the thread, one of which
            // is the note.
            ->assertJsonCount(3, 'data.messages');

        $this->assertSame(4, SupportTicket::query()->findOrFail($ticketId)->messages()->count());

        /* ---------------------------------------------------------------
         | 6. Resolved — and the customer says it is not.
         */
        $this->actingAs($agent)->postJson("/api/admin/support/tickets/{$ticketId}/resolve")->assertOk();
        $this->assertSame(TicketStatus::Resolved, SupportTicket::query()->findOrFail($ticketId)->status);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/support/tickets/{$ticketId}/replies", ['body' => 'It is still down.'])
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::WaitingForSupport->value);

        $reopened = SupportTicket::query()->findOrFail($ticketId);
        $this->assertSame(1, $reopened->reopened_count);
        $this->assertNull($reopened->resolved_at);

        /* ---------------------------------------------------------------
         | 7. Solved for real, and the customer closes it.
         */
        $this->actingAs($agent)->postJson("/api/admin/support/tickets/{$ticketId}/replies", [
            'body' => 'Found it — the firewall rule was reapplied. Try now.',
        ])->assertOk();

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/support/tickets/{$ticketId}/close")
            ->assertOk()
            ->assertJsonPath('data.status', TicketStatus::Closed->value);

        /* ---------------------------------------------------------------
         | 8. A closed ticket is finished. Nothing more may be written on it.
         */
        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/support/tickets/{$ticketId}/replies", ['body' => 'One more thing…'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'support.ticket_closed');

        $this->actingAs($agent)->postJson("/api/admin/support/tickets/{$ticketId}/replies", [
            'body' => 'Anything else?',
        ])->assertStatus(409);

        // Every state change an operator made is in the trail.
        $this->assertDatabaseHas('audit_log', ['action' => 'support.ticket_resolved']);
        $this->assertGreaterThan(
            0,
            Notification::query()->where('customer_id', $customer->getKey())->count(),
        );
    }
}
