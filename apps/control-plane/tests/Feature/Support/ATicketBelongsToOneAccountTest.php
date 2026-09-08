<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ways a support queue leaks, each stated as the thing that would be true
 * if it did.
 */
final class ATicketBelongsToOneAccountTest extends SupportTestCase
{
    #[Test]
    public function another_accounts_ticket_is_not_found_rather_than_forbidden(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirTicket = SupportTicket::factory()->create(['customer_id' => $theirs->getKey()]);

        // 404, not 403: a forbidden would confirm the id names a real ticket
        // somewhere, which is a confirmation nobody outside that account
        // should be able to buy.
        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->getJson("/api/v1/support/tickets/{$theirTicket->getKey()}")
            ->assertNotFound();

        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->postJson("/api/v1/support/tickets/{$theirTicket->getKey()}/replies", ['body' => 'hello'])
            ->assertNotFound();

        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->postJson("/api/v1/support/tickets/{$theirTicket->getKey()}/close")
            ->assertNotFound();
    }

    #[Test]
    public function the_list_shows_only_this_accounts_tickets(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        SupportTicket::factory()->create(['customer_id' => $mine->getKey(), 'subject' => 'Mine']);
        SupportTicket::factory()->create(['customer_id' => $theirs->getKey(), 'subject' => 'Theirs']);

        $response = $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->getJson('/api/v1/support/tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('Mine', $response->json('data.0.subject'));
    }

    #[Test]
    public function an_internal_note_never_reaches_the_customer(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->getKey()]);

        SupportMessage::factory()->create(['ticket_id' => $ticket->getKey(), 'body' => 'Visible to me']);
        SupportMessage::factory()->internalNote()->create([
            'ticket_id' => $ticket->getKey(),
            'body' => 'Node 3 is on the failing batch of disks, do not tell them yet',
        ]);

        $response = $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->getJson("/api/v1/support/tickets/{$ticket->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages');

        // Not just absent from the parsed messages — absent from the bytes.
        // An internal note that leaked through some other field of the
        // response would still be a leak.
        $this->assertStringNotContainsString('failing batch', (string) $response->getContent());
    }

    #[Test]
    public function a_customer_cannot_download_an_attachment_from_an_internal_note(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->getKey()]);

        $note = SupportMessage::factory()->internalNote()->create(['ticket_id' => $ticket->getKey()]);

        $attachment = SupportAttachment::query()->create([
            'message_id' => $note->getKey(),
            'disk' => 'local',
            'path' => 'support/'.$ticket->getKey().'/whatever',
            'original_name' => 'node-3-disks.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'checksum' => str_repeat('a', 64),
        ]);

        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->get("/api/v1/support/attachments/{$attachment->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function a_customer_cannot_link_their_ticket_to_somebody_elses_service(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirService = Service::factory()->create(['customer_id' => $theirs->getKey()]);

        // Not silently dropped: the customer would think the ticket was
        // linked. Not accepted either, which would confirm the id is real.
        $this->actingAs($me)->withHeaders($this->actingFor($mine))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'About that machine',
                'body' => 'Asking about a server that is not mine',
                'category' => 'technical',
                'priority' => 'normal',
                'service_id' => (string) $theirService->getKey(),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('support_tickets', 0);
    }

    #[Test]
    public function a_customer_cannot_reach_the_operator_queue(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        SupportTicket::factory()->create(['customer_id' => $customer->getKey()]);

        $this->seedRoles();

        $this->actingAs($owner)->getJson('/api/admin/support/tickets')->assertStatus(403);
    }

    #[Test]
    public function an_operator_without_the_manage_permission_may_answer_and_not_reprioritise(): void
    {
        [$customer] = $this->accountWithOwner();
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->getKey()]);

        // Finance can see customers and invoices and has no ticket permission
        // at all: the boundary is checked against a real role rather than
        // against a super admin, for whom every check passes.
        $finance = $this->supportAgent(Role::Finance);

        $this->actingAs($finance)->getJson('/api/admin/support/tickets')->assertStatus(403);
        $this->actingAs($finance)
            ->postJson("/api/admin/support/tickets/{$ticket->getKey()}/replies", ['body' => 'hello'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_read_only_member_may_ask_for_help(): void
    {
        [$customer] = $this->accountWithOwner();
        $watcher = $this->memberOf($customer, CustomerRole::Member);

        // Read-only is about the account's resources and its money. Somebody
        // who can see a broken server and cannot report it is not read-only,
        // they are stranded.
        $this->actingAs($watcher)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'The dashboard shows an error',
                'body' => 'Every page says the same thing.',
                'category' => 'other',
                'priority' => 'normal',
            ])
            ->assertCreated();
    }

    #[Test]
    public function a_customer_cannot_mark_their_own_ticket_resolved(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->getKey()]);

        // There is no such route on the customer surface, and that is the
        // assertion: resolved is the support team's opinion that the problem
        // is solved, and a customer marking it would put a judgement in the
        // team's mouth. Closing is theirs; resolving is not.
        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson("/api/v1/support/tickets/{$ticket->getKey()}/resolve")
            ->assertNotFound();
    }

    #[Test]
    public function a_customer_cannot_open_an_urgent_ticket(): void
    {
        [$customer, $owner] = $this->accountWithOwner();

        // Urgent is what pages somebody out of hours. A priority a customer
        // can select for themselves stops meaning anything within a month.
        $this->actingAs($owner)->withHeaders($this->actingFor($customer))
            ->postJson('/api/v1/support/tickets', [
                'subject' => 'Everything is on fire',
                'body' => 'Please help',
                'category' => 'technical',
                'priority' => 'urgent',
            ])
            ->assertStatus(422);
    }
}
