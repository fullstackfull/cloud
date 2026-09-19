<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Enums;

/**
 * Whether the customer may ask for this again.
 *
 * Published beside every operation state so the screen never has to work it
 * out. The portal draws a retry control from `SafeToRetry` and from nothing
 * else; the other three each mean "no", for three different reasons a customer
 * deserves to be told apart.
 */
enum RetryAdvice: string
{
    /**
     * The platform knows the action did not take effect. Asking again is safe.
     */
    case SafeToRetry = 'safe_to_retry';

    /**
     * It is still in progress. Nothing is wrong; the answer is not in yet.
     */
    case Wait = 'wait';

    /**
     * A person has to look before anything else is attempted — either because
     * the platform stopped and flagged it, or because the result is unknown
     * and a second attempt could double it.
     */
    case SupportRequired = 'support_required';

    /**
     * There is nothing to repeat: it succeeded, or it is not the kind of thing
     * that can be asked for twice.
     */
    case NotRetryable = 'not_retryable';
}
