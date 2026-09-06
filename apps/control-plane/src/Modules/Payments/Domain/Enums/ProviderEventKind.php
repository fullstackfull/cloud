<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

/**
 * The normalised meaning of a provider webhook, independent of the provider's
 * own vocabulary.
 *
 * Stripe calls it "payment_intent.succeeded", MyFatoorah calls it something
 * else, and both will rename their event types eventually. Everything past the
 * adapter switches on this enum, so adding a provider never touches the
 * ingestion or settlement code.
 *
 * Unknown is a first-class case rather than a null: an event we do not act on
 * is still recorded, so an operator can see what the provider actually sent.
 */
enum ProviderEventKind: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case RefundSucceeded = 'refund_succeeded';
    case Unknown = 'unknown';

    /**
     * Whether this kind changes platform state. Everything else is recorded
     * and acknowledged so the provider stops redelivering it.
     */
    public function isActionable(): bool
    {
        return $this !== self::Unknown;
    }
}
