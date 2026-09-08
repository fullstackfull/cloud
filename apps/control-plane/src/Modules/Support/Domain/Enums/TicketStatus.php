<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Domain\Enums;

/**
 * Where a ticket has got to.
 *
 * `Open` and `WaitingForSupport` look alike and are not. Open is a ticket
 * nobody has answered yet — the queue's oldest and most urgent problem, and
 * the one a first-response target is measured against. WaitingForSupport is a
 * conversation already under way in which the customer has said something
 * else. Collapsing them would hide the tickets nobody has touched inside the
 * ones somebody is already handling.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case WaitingForSupport = 'waiting_for_support';
    case WaitingForCustomer = 'waiting_for_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** Whether the ticket is still somebody's to answer. */
    public function isLive(): bool
    {
        return match ($this) {
            self::Open, self::WaitingForSupport, self::WaitingForCustomer => true,
            self::Resolved, self::Closed => false,
        };
    }

    /** Whether it is the support team's turn. */
    public function isWaitingOnSupport(): bool
    {
        return $this === self::Open || $this === self::WaitingForSupport;
    }

    /**
     * Whether a reply may still be written.
     *
     * A resolved ticket accepts one: "that did not fix it" is the most useful
     * message a customer ever sends, and making them open a second ticket
     * loses the history that explains the first. A closed one does not —
     * closing is what an account does when it is finished with a
     * conversation, and a thread that can always be revived is a thread that
     * is never finished.
     */
    public function acceptsReplies(): bool
    {
        return $this !== self::Closed;
    }
}
