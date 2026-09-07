<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as BaseEventServiceProvider;
use Lynomia\Modules\Billing\Application\Listeners\IssueInvoiceOnOrderPlaced;
use Lynomia\Modules\Billing\Application\Listeners\RecordRefundAgainstTheInvoice;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Orders\Application\Listeners\FulfilOrderOnInvoicePaid;
use Lynomia\Modules\Orders\Domain\Events\OrderPlaced;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;

/**
 * The commerce chain, wired explicitly rather than discovered.
 *
 *     OrderPlaced     → issue the invoice
 *     PaymentCaptured → settle the invoice
 *     InvoicePaid     → mark the order paid, redeem the coupon, start subscriptions
 *     RefundIssued    → record the refund against the invoice it came off
 *
 * Listed here rather than auto-discovered on purpose. This mapping is the
 * platform's fulfilment policy: what happens when money arrives is the single
 * most consequential decision in the system, and it should be readable in one
 * file rather than inferred by scanning every class for a type-hint.
 *
 * Each step lives in the module that owns the decision. Payments knows money
 * arrived and nothing about what it buys; billing knows how to settle an
 * invoice and nothing about provisioning; orders know how to fulfil.
 */
final class EventServiceProvider extends BaseEventServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        OrderPlaced::class => [
            IssueInvoiceOnOrderPlaced::class,
        ],
        PaymentCaptured::class => [
            SettleInvoiceOnPaymentCaptured::class,
        ],
        InvoicePaid::class => [
            FulfilOrderOnInvoicePaid::class,
        ],
        RefundIssued::class => [
            RecordRefundAgainstTheInvoice::class,
        ],
    ];

    /**
     * Discovery is off. A listener that fires because of where its file sits is
     * a listener nobody reviewed.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
