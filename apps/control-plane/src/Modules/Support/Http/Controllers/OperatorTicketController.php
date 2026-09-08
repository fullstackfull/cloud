<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Application\Actions\ChangeTicketState;
use Lynomia\Modules\Support\Application\Actions\NotifyAboutTicket;
use Lynomia\Modules\Support\Application\Actions\ReplyToTicket;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Http\Requests\ReplyToTicketRequest;
use Lynomia\Modules\Support\Http\Resources\OperatorTicketResource;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The queue, and one ticket in it.
 *
 * Not scoped to a customer — that is the point of an operator surface — so
 * every route names its permission and the route test fails the build if one
 * does not. Reading is `ticket.view_any`, answering is `ticket.reply`, and
 * changing what a ticket *is* — its priority, who owns it, whether it is
 * resolved — is `ticket.manage`. Three permissions because they are three
 * different powers: a first-line agent should answer without being able to
 * silently downgrade an urgent ticket.
 *
 * **Every state change is audited inside its own transaction.** Reassigning,
 * re-prioritising, resolving and closing all change who is accountable for a
 * customer's problem, and a change nobody recorded is one nobody can explain
 * afterwards. Replies are not audited: the message *is* the record, it is
 * immutable, and a second copy of it in the audit log would be a second place
 * for a customer's words to live.
 */
final class OperatorTicketController
{
    public function __construct(
        private readonly ReplyToTicket $reply,
        private readonly ChangeTicketState $state,
        private readonly NotifyAboutTicket $notify,
        private readonly RecordActAtomically $audited,
    ) {}

    /**
     * The queue: worst first, longest untouched first within that.
     *
     * Defaults to the tickets waiting on the team rather than to everything.
     * A queue that opens on every ticket ever raised is a queue whose first
     * page is history.
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $assignee = $request->query('assigned_to');

        $tickets = SupportTicket::query()
            ->with(['customer', 'assignee', 'openedBy'])
            ->when(
                is_string($status) && $status !== '',
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', [
                    TicketStatus::Open->value,
                    TicketStatus::WaitingForSupport->value,
                ]),
            )
            ->when(is_string($assignee) && $assignee !== '', fn ($query) => $query->where('assigned_to_user_id', $assignee))
            ->inQueueOrder()
            ->limit(200)
            ->get();

        return response()->json([
            'data' => OperatorTicketResource::collection($tickets),
            'meta' => [
                'total' => $tickets->count(),
                'waiting_on_support' => SupportTicket::query()->whereIn('status', [
                    TicketStatus::Open->value,
                    TicketStatus::WaitingForSupport->value,
                ])->count(),
            ],
        ]);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    /**
     * A reply, or a note to colleagues.
     *
     * `internal_note` is the one flag that decides whether the customer ever
     * sees this and whether the ticket changes hands. Both consequences live
     * in ReplyToTicket, so an endpoint cannot get one right and the other
     * wrong.
     */
    public function reply(ReplyToTicketRequest $request, string $ticket): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $found = $this->ticket($ticket);
        $isNote = $request->boolean('internal_note');

        $message = $this->reply->execute(
            ticket: $found,
            author: $user,
            body: (string) $request->input('body'),
            kind: MessageAuthorKind::Operator,
            isInternalNote: $isNote,
            files: $request->attachments(),
        );

        $this->notify->repliedByOperator($found->refresh(), $message);

        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    public function assign(Request $request, string $ticket): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'string', 'ulid', 'exists:users,id'],
        ]);

        $found = $this->ticket($ticket);
        $to = $validated['user_id'] ?? null;

        $this->audited->execute(
            fn () => $this->state->assign($found, is_string($to) ? $to : null),
            fn (SupportTicket $after) => new AuditedAct(
                action: AuditAction::TicketAssigned,
                subject: $after,
                customerId: (string) $after->customer_id,
                context: ['from_user_id' => $found->assigned_to_user_id, 'to_user_id' => $after->assigned_to_user_id],
            ),
        );

        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    public function prioritise(Request $request, string $ticket): JsonResponse
    {
        $validated = $request->validate([
            // The whole enum here, unlike the customer surface: `urgent` is an
            // operator's judgement to make.
            'priority' => ['required', 'string', 'in:low,normal,high,urgent'],
        ]);

        $found = $this->ticket($ticket);
        $was = $found->priority;

        $this->audited->execute(
            fn () => $this->state->reprioritise($found, TicketPriority::from((string) $validated['priority'])),
            fn (SupportTicket $after) => new AuditedAct(
                action: AuditAction::TicketPrioritised,
                subject: $after,
                customerId: (string) $after->customer_id,
                context: ['from' => $was->value, 'to' => $after->priority->value],
            ),
        );

        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    public function resolve(Request $request, string $ticket): JsonResponse
    {
        $found = $this->ticket($ticket);

        $resolved = $this->audited->execute(
            fn () => $this->state->resolve($found),
            fn (SupportTicket $after) => new AuditedAct(
                action: AuditAction::TicketResolved,
                subject: $after,
                customerId: (string) $after->customer_id,
                context: ['reopened_count' => $after->reopened_count],
            ),
        );

        $this->notify->resolved($resolved);

        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    public function close(Request $request, string $ticket): JsonResponse
    {
        $found = $this->ticket($ticket);

        $closed = $this->audited->execute(
            fn () => $this->state->close($found),
            fn (SupportTicket $after) => new AuditedAct(
                action: AuditAction::TicketClosed,
                subject: $after,
                customerId: (string) $after->customer_id,
                context: ['was' => $found->status->value],
            ),
        );

        $this->notify->closed($closed);

        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    public function reopen(Request $request, string $ticket): JsonResponse
    {
        $found = $this->ticket($ticket);

        $this->audited->execute(
            fn () => $this->state->reopen($found),
            fn (SupportTicket $after) => new AuditedAct(
                action: AuditAction::TicketReopened,
                subject: $after,
                customerId: (string) $after->customer_id,
                context: ['reopened_count' => $after->reopened_count],
            ),
        );

        return (new OperatorTicketResource($this->ticketWithThread($ticket)))->response();
    }

    /**
     * An operator may read every attachment on a ticket, internal notes
     * included — those are their own colleagues' files.
     */
    public function download(Request $request, string $attachment): StreamedResponse
    {
        /** @var SupportAttachment $found */
        $found = SupportAttachment::query()->whereKey($attachment)->firstOrFail();

        return Storage::disk($found->disk)->download(
            $found->path,
            $found->original_name,
            [
                'Content-Type' => $found->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }

    private function ticket(string $id): SupportTicket
    {
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()->whereKey($id)->firstOrFail();

        return $ticket;
    }

    private function ticketWithThread(string $id): SupportTicket
    {
        /** @var SupportTicket $ticket */
        $ticket = SupportTicket::query()
            ->whereKey($id)
            ->with(['customer', 'assignee', 'openedBy', 'messages.author', 'messages.attachments'])
            ->firstOrFail();

        return $ticket;
    }
}
