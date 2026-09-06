<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\DTOs;

use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;

/**
 * What ingesting one webhook did.
 *
 * `duplicate` is the field callers care about most: it is how the endpoint
 * distinguishes "we just captured this payment" from "we captured it the first
 * time this event arrived and are telling the provider so again". Both return
 * 200 to the provider — a 4xx on a duplicate would make it retry forever — so
 * the difference has to be visible somewhere other than the status code.
 *
 * @immutable
 */
final readonly class WebhookIngestionResult
{
    private function __construct(
        public WebhookEvent $event,
        public bool $duplicate,
        public ?Transaction $transaction = null,
        public ?Refund $refund = null,
    ) {}

    public static function processed(WebhookEvent $event, ?Transaction $transaction = null, ?Refund $refund = null): self
    {
        return new self($event, duplicate: false, transaction: $transaction, refund: $refund);
    }

    /**
     * A redelivery of an event that already reached an outcome. Nothing was
     * recomputed; the recorded outcome is returned as it stands.
     */
    public static function alreadyProcessed(WebhookEvent $event, ?Transaction $transaction = null, ?Refund $refund = null): self
    {
        return new self($event, duplicate: true, transaction: $transaction, refund: $refund);
    }

    public function wasActedOn(): bool
    {
        return ! $this->duplicate && ($this->transaction !== null || $this->refund !== null);
    }
}
