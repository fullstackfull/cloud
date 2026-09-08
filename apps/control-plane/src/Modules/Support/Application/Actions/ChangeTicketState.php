<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Domain\Exceptions\TicketRefusedException;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * The state changes that are decisions rather than consequences of a message.
 *
 * Resolving, closing, reopening, assigning and re-prioritising all live here
 * so that the rules about each are in one place rather than spread across two
 * controllers that would drift.
 */
final readonly class ChangeTicketState
{
    public function resolve(SupportTicket $ticket): SupportTicket
    {
        return $this->update($ticket, static fn (SupportTicket $locked): array => [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Closing is the end. Nothing may be written on a closed ticket, which is
     * why both sides can do it and neither can undo it: a customer who wants
     * to say something else opens a new ticket, and the reference of the old
     * one carries the history.
     */
    public function close(SupportTicket $ticket): SupportTicket
    {
        return $this->update($ticket, static fn (SupportTicket $locked): array => [
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
            // Kept if it was resolved first. "Resolved then closed" and "closed
            // without ever being solved" are different outcomes.
            'resolved_at' => $locked->resolved_at,
        ]);
    }

    /**
     * Reopening a resolved ticket without saying anything.
     *
     * Separate from a reply because the two are different acts: an operator
     * reopening a ticket they resolved by mistake has nothing to say to the
     * customer, and forcing a message would put a meaningless one in the
     * thread.
     */
    public function reopen(SupportTicket $ticket): SupportTicket
    {
        return $this->update($ticket, static function (SupportTicket $locked): array {
            if ($locked->status->isLive()) {
                throw TicketRefusedException::becauseItIsAlreadyOpen((string) $locked->getKey());
            }

            return [
                'status' => TicketStatus::WaitingForSupport,
                'resolved_at' => null,
                'closed_at' => null,
                'reopened_count' => $locked->reopened_count + 1,
            ];
        });
    }

    public function assign(SupportTicket $ticket, ?string $userId): SupportTicket
    {
        return $this->update($ticket, static fn (SupportTicket $locked): array => [
            'assigned_to_user_id' => $userId,
        ]);
    }

    public function reprioritise(SupportTicket $ticket, TicketPriority $priority): SupportTicket
    {
        return $this->update($ticket, static fn (SupportTicket $locked): array => [
            'priority' => $priority,
        ]);
    }

    /**
     * @param  callable(SupportTicket): array<string, mixed>  $changes
     */
    private function update(SupportTicket $ticket, callable $changes): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $changes): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill($changes($locked))->save();

            return $locked;
        });
    }
}
