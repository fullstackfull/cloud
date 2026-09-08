<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * Where one zone or one record has got to.
 *
 * The same vocabulary for both on purpose. A zone and a record are the same
 * kind of thing to this module — something the platform has asked a provider
 * to hold — and two enums saying `pending` in two spellings would be two
 * chances for a screen to translate one and not the other.
 *
 * The distinction worth reading twice is `Failed` against `Indeterminate`.
 * Failed means the provider answered and said no; the customer has something
 * to fix and the platform will not try again on its own. Indeterminate means
 * nobody knows: the platform stopped waiting, and the record may be live. A
 * platform that called the second one "failed" would invite the customer to
 * publish it again, which is exactly the duplicate the Timeout Rule exists to
 * prevent.
 */
enum DnsState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    case NeedsReview = 'needs_review';

    /**
     * Whether the platform believes this is being served right now.
     *
     * Indeterminate is deliberately not live. It is also deliberately not
     * *not* live — which is why nothing asks this question when the answer
     * matters for a destructive act; those ask {@see self::mayBeAtProvider()}.
     */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether something might exist at the provider under this state.
     *
     * Everything except a state that never reached the provider or has been
     * confirmed gone. Used before a delete: the answer decides whether the
     * platform must ask the provider or may simply drop the row.
     */
    public function mayBeAtProvider(): bool
    {
        return match ($this) {
            self::Active, self::Indeterminate, self::Deleting, self::NeedsReview => true,
            self::Pending, self::Failed, self::Deleted => false,
        };
    }

    /**
     * Whether a person has to do something about this.
     *
     * Indeterminate is included and `failed` is not, which looks backwards
     * until you ask who the person is. A refusal is the *customer's* to fix —
     * they typed something the provider would not take — while a row nobody
     * can account for is the platform's, and it is the platform that has to go
     * and look.
     */
    public function needsAttention(): bool
    {
        return $this === self::Indeterminate || $this === self::NeedsReview;
    }

    public function isBeingDeleted(): bool
    {
        return $this === self::Deleting || $this === self::Deleted;
    }

    /**
     * Whether a customer may still change this row.
     *
     * A row on its way out is not editable: an update to something being
     * deleted is a race whose winner decides whether the name resolves.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Active, self::Failed, self::Indeterminate, self::Pending, self::NeedsReview => true,
            self::Deleting, self::Deleted => false,
        };
    }

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->allowed(), strict: true);
    }

    /**
     * @return list<self>
     */
    private function allowed(): array
    {
        return match ($this) {
            // A publish either lands, is refused, or does not answer. It can
            // also be abandoned: a customer who deletes a record that has not
            // published yet gets a delete, not a wait.
            self::Pending => [self::Active, self::Failed, self::Indeterminate, self::Deleting, self::Deleted],

            // Re-publishing an existing row (an edited record) puts it back to
            // pending rather than editing in place, so that a screen never
            // shows a value as live before the provider has taken it.
            self::Active => [self::Pending, self::Deleting, self::Indeterminate, self::NeedsReview],

            // A refusal is an answer, so nothing here needs a provider call to
            // clear it: the row can be corrected and re-published, or dropped.
            self::Failed => [self::Pending, self::Deleted, self::Deleting],

            // Only a look at the provider settles this — reconciliation finds
            // it live, finds it absent, or gives up and asks for a person.
            self::Indeterminate => [self::Active, self::Deleting, self::Deleted, self::NeedsReview],

            self::Deleting => [self::Deleted, self::Indeterminate, self::NeedsReview],

            // Terminal. A name that was deleted and is wanted again is a new
            // record, because the old row's history is a different fact from
            // the new one's.
            self::Deleted => [],

            // A person has looked. They can put it back into service, remove
            // it, or confirm it is already gone.
            self::NeedsReview => [self::Active, self::Deleting, self::Deleted],
        };
    }
}
