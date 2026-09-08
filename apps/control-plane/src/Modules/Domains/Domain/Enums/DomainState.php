<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Enums;

/**
 * Whether this platform holds a name, and on what terms.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * There is no `expiring`. It is the one state customers ask for by name and
 * the one that cannot be stored honestly: "expiring" means nothing more than
 * `expires_at` being close, so a stored copy of it is a second copy of a date
 * that is wrong for as long as the sweep that maintains it is late. A domain
 * would sit in `active` for a week after it should have read `expiring`, and
 * the only symptom would be a customer not being warned.
 *
 * So the date is the truth and "expiring" is a question asked of it —
 * {@see Domain::isExpiringWithin()} — computed at read time, correct at every
 * moment, and translated on the screen like any other state.
 *
 * ---------------------------------------------------------------------------
 * Why the rest are here
 * ---------------------------------------------------------------------------
 *
 * Every state below is one a registry actually distinguishes, or one the
 * platform needs because it cannot see what the registry did. `Grace` and
 * `Redemption` are registry states with different prices attached.
 * `Indeterminate` and `NeedsReview` are the platform admitting what it does
 * not know, which is the same vocabulary the compute, DNS and hosting modules
 * use for the same reason.
 */
enum DomainState: string
{
    /**
     * Paid for, not yet held.
     *
     * The registrar has been asked, or is about to be. A customer looking at
     * this is looking at a name that is not theirs yet and may never be — the
     * availability they saw was a fact about the past.
     */
    case RegistrationPending = 'registration_pending';

    /** A transfer-in has been started and the losing registrar has not released it. */
    case TransferPending = 'transfer_pending';

    /** Held, paid for, and inside its term. */
    case Active = 'active';

    /**
     * Past its expiry date and not yet in whatever the registry calls grace.
     *
     * Kept distinct from Grace because the two have different prices and
     * different deadlines, and because a registry that has no grace period
     * goes from here straight to deleted.
     */
    case Expired = 'expired';

    /** Expired, and the registry still allows a renewal at the ordinary price. */
    case Grace = 'grace';

    /** Recoverable only by paying a registry penalty, which is its own operation. */
    case Redemption = 'redemption';

    /** Gone from the registry. Anybody may register it now, including us. */
    case Deleted = 'deleted';

    /** Moved to another registrar. Its DNS zone here, if any, is untouched. */
    case TransferredAway = 'transferred_away';

    /**
     * The acquisition was refused and the platform never held it.
     *
     * Distinct from `Deleted`, which is a name the platform held and lost.
     * The customer's money is the difference: a failed registration is
     * refundable, an expired one is not.
     */
    case Failed = 'failed';

    /**
     * Nobody knows whether the platform holds this name.
     *
     * The registrar was asked and did not answer. It may have registered the
     * domain. Nothing retries on its own from here, because a retried
     * registration is a second year somebody pays for, and reconciliation is
     * what resolves it.
     */
    case Indeterminate = 'indeterminate';

    /** A person has to decide. The reason is on the row. */
    case NeedsReview = 'needs_review';

    /**
     * Whether the platform believes it holds this name right now.
     *
     * Grace and redemption are held: the name is still the customer's to
     * recover, and it still appears on their list. Indeterminate is not,
     * which is deliberate — a screen that showed an unanswered registration
     * as a domain the customer owns would be making the platform's uncertainty
     * into the customer's confidence.
     */
    /**
     * Whether this platform is the name's holder while it is in this state.
     *
     * Broader than {@see isHeld()}: a registration still pending and a name
     * sitting in `indeterminate` are both names no second account may buy,
     * even though neither is a name the customer can use yet.
     *
     * This predicate has a copy in the database — the partial unique index
     * `domains_one_live_holder`, whose WHERE clause is the negation of it. The
     * two are kept honest by a test rather than by hope, because the failure
     * mode of them disagreeing is either a name sold twice or a name nobody
     * can ever buy again.
     */
    public function holdsTheName(): bool
    {
        return match ($this) {
            self::Deleted, self::TransferredAway, self::Failed => false,
            default => true,
        };
    }

    /**
     * The stored values of every state in which this platform holds the name.
     *
     * For the one query that has to ask "is this name spoken for" without a
     * row in hand.
     *
     * @return list<string>
     */
    public static function thatHoldTheName(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(self::cases(), static fn (self $state): bool => $state->holdsTheName()),
        ));
    }

    public function isHeld(): bool
    {
        return match ($this) {
            self::Active, self::Expired, self::Grace, self::Redemption => true,
            default => false,
        };
    }

    /**
     * Whether the name might exist at the registrar under this state.
     *
     * Asked before anything is done that assumes it does not — including
     * registering it again. Indeterminate answers yes, which is the whole
     * point of the state.
     */
    public function mayExistAtRegistrar(): bool
    {
        return match ($this) {
            self::Deleted, self::Failed => false,
            default => true,
        };
    }

    /**
     * Whether a customer may change this domain's settings.
     *
     * Not while an acquisition is in flight and not once it is gone. Changing
     * nameservers on a domain whose registration has not resolved is a change
     * against a registrar record that may not exist.
     */
    public function isManageable(): bool
    {
        return match ($this) {
            self::Active, self::Expired, self::Grace => true,
            default => false,
        };
    }

    /** Whether a person has to look at this. */
    public function needsAttention(): bool
    {
        return $this === self::Indeterminate || $this === self::NeedsReview;
    }

    /**
     * Whether renewing is the operation that recovers this state.
     *
     * Redemption is excluded on purpose: recovering a redeemed domain is a
     * different operation at a different price, and offering "renew" there
     * would quote the customer a number the registry will not accept.
     */
    public function isRenewable(): bool
    {
        return match ($this) {
            self::Active, self::Expired, self::Grace => true,
            default => false,
        };
    }

    /**
     * Whether one state may legally follow another.
     *
     * Stated as a map rather than left to whoever writes the next action,
     * because the illegal transitions here are the expensive ones. `Deleted`
     * to `Active` would be a domain the platform believes it recovered without
     * anybody paying a redemption; `Failed` to `Active` would be a
     * registration that was refused and then quietly succeeded.
     *
     * Everything may reach `NeedsReview` and `Indeterminate`: the platform is
     * always allowed to admit it does not know.
     */
    public function canBecome(self $next): bool
    {
        if ($next === $this) {
            return true;
        }

        if ($next === self::NeedsReview || $next === self::Indeterminate) {
            return true;
        }

        return in_array($next, match ($this) {
            self::RegistrationPending => [self::Active, self::Failed],
            self::TransferPending => [self::Active, self::Failed],
            self::Active => [self::Expired, self::Grace, self::TransferredAway, self::Deleted],
            /*
             * Expired may go back to Active: that is what a renewal inside the
             * registry's window does, and it is the ordinary happy path for a
             * customer who paid late.
             */
            self::Expired => [self::Active, self::Grace, self::Redemption, self::Deleted],
            self::Grace => [self::Active, self::Redemption, self::Deleted],
            /*
             * Redemption reaches Active only through a redemption operation,
             * which is a different price. The state machine cannot enforce
             * which operation was used, but it can refuse the transitions that
             * have no operation behind them at all.
             */
            self::Redemption => [self::Active, self::Deleted],
            self::Indeterminate => [self::Active, self::Failed, self::Deleted, self::TransferredAway],
            self::NeedsReview => [self::Active, self::Failed, self::Deleted, self::TransferredAway, self::Expired, self::Grace, self::Redemption],
            // Terminal. A name the platform lost is acquired again as a new
            // registration, with a new row, because it is a new term.
            self::Deleted, self::TransferredAway, self::Failed => [],
        }, strict: true);
    }
}
