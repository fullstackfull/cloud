<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\DTOs;

use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * What StartInvoicePayment produced: a local record of the attempt and the
 * provider's instructions for the browser.
 *
 * The intent result is carried whole rather than folded into the transaction
 * because one of its fields must never be persisted: the client secret is a
 * bearer credential for this payment, and it exists to be handed to the
 * browser and then forgotten. Keeping it on a DTO that dies with the request
 * is what stops it reaching the ledger, a log line or a queue payload.
 *
 * @immutable
 */
final readonly class StartedPayment
{
    public function __construct(
        public string $provider,
        public Transaction $transaction,
        public PaymentAttempt $attempt,
        public PaymentIntentResult $intent,
    ) {}
}
