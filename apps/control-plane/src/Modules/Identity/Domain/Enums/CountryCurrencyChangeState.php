<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

/**
 * Where a request to change an account's country or currency has got to.
 *
 * The lifecycle the addendum names, adapted to how this platform works:
 * the analysis is run inside the same request that records the ask, so
 * there is no stored `analysing` — a row is `blocked` or
 * `awaiting_approval` the moment it exists, and is re-analysed on every
 * step that matters. `scheduled` is a real state: an operator may approve
 * for a later moment, and the sweep applies it then, after checking the
 * facts again. `needs_review` is the sweep finding a blocker that was not
 * there at approval; nothing is applied and a person decides.
 *
 * Nothing here converts anything. An applied change sets the account's
 * country and currency from that moment; every invoice, payment, order
 * and subscription already written keeps the currency and the tax it was
 * written with.
 */
enum CountryCurrencyChangeState: string
{
    case Requested = 'requested';
    case Blocked = 'blocked';
    case AwaitingApproval = 'awaiting_approval';
    case Scheduled = 'scheduled';
    case Applied = 'applied';
    case NeedsReview = 'needs_review';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /** Still the customer's to withdraw, and still open. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Requested, self::Blocked, self::AwaitingApproval, self::Scheduled, self::NeedsReview => true,
            default => false,
        };
    }

    /** An operator may decide it. */
    public function isDecidable(): bool
    {
        return match ($this) {
            self::AwaitingApproval, self::NeedsReview, self::Blocked => true,
            default => false,
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::NeedsReview;
    }
}
