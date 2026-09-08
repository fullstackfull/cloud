<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Support\Application\Actions\ChangeTicketState;
use Lynomia\Modules\Support\Application\Actions\NotifyAboutTicket;
use Lynomia\Modules\Support\Application\Actions\OpenTicket;
use Lynomia\Modules\Support\Application\Actions\ReplyToTicket;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Http\Requests\OpenTicketRequest;
use Lynomia\Modules\Support\Http\Requests\ReplyToTicketRequest;
use Lynomia\Modules\Support\Http\Resources\TicketResource;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer's side of support.
 *
 * **Scoped, not checked.** Every ticket is reached through a `where` on the
 * acting customer, so a ticket id from another account is a 404 rather than a
 * 403 — and there is no id here to enumerate into a confirmation that somebody
 * else's ticket exists.
 *
 * **Internal notes are excluded in the query.** `customerVisibleMessages()`
 * filters them in the relation, not in the resource, so a note cannot leak
 * through an endpoint that forgot to use the right serialiser.
 *
 * **Attachments are streamed, never linked.** Nothing about where a file lives
 * appears in a response; the download takes an attachment id, re-derives the
 * ticket, and re-checks the account.
 */
final class TicketController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly OpenTicket $open,
        private readonly ReplyToTicket $reply,
        private readonly ChangeTicketState $state,
        private readonly NotifyAboutTicket $notify,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'support.manage');

        $tickets = SupportTicket::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->with(['assignee', 'openedBy'])
            ->orderByRaw('coalesce(last_reply_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => TicketResource::collection($tickets),
            'meta' => ['total' => $tickets->count()],
        ]);
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'support.manage');

        $found = $this->scopedTicket($ticket, withMessages: true);

        return (new TicketResource($found))->response();
    }

    public function store(OpenTicketRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'support.manage');

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $ticket = $this->open->execute(
            customer: $this->actingCustomer->get(),
            author: $user,
            subject: (string) $request->input('subject'),
            body: (string) $request->input('body'),
            category: $request->category(),
            priority: $request->priority(),
            serviceId: $this->ownServiceId($request->input('service_id')),
            invoiceId: $this->ownInvoiceId($request->input('invoice_id')),
            files: $request->attachments(),
        );

        $this->notify->opened($ticket);

        return (new TicketResource($this->scopedTicket((string) $ticket->getKey(), withMessages: true)))
            ->response()
            ->setStatusCode(201);
    }

    public function reply(ReplyToTicketRequest $request, string $ticket): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'support.manage');

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $found = $this->scopedTicket($ticket);

        $this->reply->execute(
            ticket: $found,
            author: $user,
            body: (string) $request->input('body'),
            kind: MessageAuthorKind::Customer,
            files: $request->attachments(),
        );

        return (new TicketResource($this->scopedTicket($ticket, withMessages: true)))->response();
    }

    /**
     * Closing is the customer's to do; resolving is not.
     *
     * Resolved means the support team believes the problem is solved, and a
     * customer marking their own ticket resolved would put a judgement in the
     * team's mouth. Closing means "I am finished with this conversation",
     * which is entirely the customer's to say.
     */
    public function close(Request $request, string $ticket): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'support.manage');

        $closed = $this->state->close($this->scopedTicket($ticket));

        return (new TicketResource($this->scopedTicket((string) $closed->getKey(), withMessages: true)))->response();
    }

    /**
     * Streamed with the headers that stop a browser executing it.
     *
     * `attachment` rather than `inline`, and `nosniff`: the platform decided
     * the type from the bytes at upload, and these two together mean that even
     * if that were wrong, a browser will save the file rather than render it.
     */
    public function download(Request $request, string $attachment): StreamedResponse
    {
        $this->authoriseWithinAccount($request, 'support.manage');

        /** @var SupportAttachment $found */
        $found = SupportAttachment::query()
            ->whereKey($attachment)
            ->whereHas('message.ticket', fn ($query) => $query->where('customer_id', $this->actingCustomer->id()))
            // An internal note's attachment is not the customer's to read,
            // even when the ticket is theirs.
            ->whereHas('message', fn ($query) => $query->where('is_internal_note', false))
            ->firstOrFail();

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

    private function scopedTicket(string $id, bool $withMessages = false): SupportTicket
    {
        $query = SupportTicket::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($id)
            ->with(['assignee', 'openedBy']);

        if ($withMessages) {
            $query->with(['customerVisibleMessages.author', 'customerVisibleMessages.attachments']);
        }

        /** @var SupportTicket $ticket */
        $ticket = $query->firstOrFail();

        return $ticket;
    }

    /**
     * A service id the acting account actually owns, or null.
     *
     * Silently dropping an id that names somebody else's service would be
     * wrong — the customer would think their ticket was linked. It is a 404,
     * from the same scoped lookup everything else here uses.
     */
    private function ownServiceId(mixed $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        Service::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($id)
            ->firstOrFail();

        return $id;
    }

    private function ownInvoiceId(mixed $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        Invoice::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($id)
            ->firstOrFail();

        return $id;
    }
}
