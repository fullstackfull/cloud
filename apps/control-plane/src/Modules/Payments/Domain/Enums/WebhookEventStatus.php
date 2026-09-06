<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

enum WebhookEventStatus: string
{
    /** Signature verified and stored, not yet acted on. */
    case Received = 'received';
    case Processed = 'processed';
    /** Handled and deliberately not acted on — an event type we do not use. */
    case Ignored = 'ignored';
    /** Handling threw; last_error explains why and the event can be retried. */
    case Failed = 'failed';

    /**
     * Whether the event has reached an outcome that must not be recomputed.
     *
     * This is the read side of the replay defence: a redelivery of an event in
     * one of these states returns the recorded outcome instead of capturing a
     * second time.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Processed, self::Ignored => true,
            self::Received, self::Failed => false,
        };
    }
}
