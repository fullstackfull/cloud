<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as BaseEventServiceProvider;
use Lynomia\Modules\Billing\Application\Listeners\AnnounceSettlementOnInvoicePaid;
use Lynomia\Modules\Billing\Application\Listeners\EvaluateOrderFinancialRequirement;
use Lynomia\Modules\Billing\Application\Listeners\RecordRefundAgainstTheInvoice;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Domain\Events\OrderFinanciallySettled;
use Lynomia\Modules\Domains\Application\Listeners\RegisterDomainOnPayment;
use Lynomia\Modules\Monitoring\Application\Listeners\RecordScheduledRun;
use Lynomia\Modules\Notifications\Application\Listeners\NotifyOnBillingEvent;
use Lynomia\Modules\Notifications\Application\Listeners\NotifyOnProvisioningOutcome;
use Lynomia\Modules\Notifications\Application\Listeners\NotifyOnSubscriptionChange;
use Lynomia\Modules\Orders\Application\Listeners\FulfilOrderOnSettlement;
use Lynomia\Modules\Orders\Domain\Events\OrderPlaced;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\ProductReadiness\Application\Listeners\ReassessProductsWhenAProviderChanges;
use Lynomia\Modules\Providers\Domain\Events\ProviderReadinessChanged;
use Lynomia\Modules\Provisioning\Application\Listeners\AlertOnCriticalDrift;
use Lynomia\Modules\Provisioning\Domain\Events\DriftRecorded;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\SharedHosting\Application\Listeners\InstallWordPressOnceTheAccountExists;
use Lynomia\Modules\Subscriptions\Application\Listeners\EnforceServiceStateForSubscription;
use Lynomia\Modules\Subscriptions\Application\Listeners\ReviveSubscriptionOnRenewalPayment;
use Lynomia\Modules\Subscriptions\Application\Listeners\StartDunningOnFailedPayment;
use Lynomia\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;

/**
 * The commerce chain, wired explicitly rather than discovered.
 *
 *     OrderPlaced             → decide what is owed: issue an invoice, or, when
 *                               nothing is owed, settle the order outright
 *     PaymentCaptured         → settle the invoice
 *     InvoicePaid             → announce that the order owes nothing further
 *     OrderFinanciallySettled → mark the order paid, redeem the coupon, start
 *                               the subscription, create the service and ask
 *                               for it to be built
 *     RefundIssued            → record the refund against the invoice it came off
 *
 * The settlement event in the middle is what lets a zero-total order reach
 * fulfilment: it owes nothing, so it produces no invoice, and a chain that
 * hangs off InvoicePaid can never deliver it.
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
            EvaluateOrderFinancialRequirement::class,
        ],
        PaymentCaptured::class => [
            SettleInvoiceOnPaymentCaptured::class,
        ],
        PaymentFailed::class => [
            // Without this a card that expired cost the platform a customer's
            // subscription for ever: nothing moved them to past_due, and the
            // lifecycle sweep only looks at subscriptions that already are.
            StartDunningOnFailedPayment::class,
        ],
        InvoicePaid::class => [
            AnnounceSettlementOnInvoicePaid::class,
            // A renewal being paid is what ends dunning. Without this the
            // money arrived and the subscription stayed suspended.
            ReviveSubscriptionOnRenewalPayment::class,

            /*
             * A domain is registered only once its invoice is paid. It listens
             * here rather than to OrderFinanciallySettled because a domain is
             * invoiced directly rather than through the plan checkout: there
             * is no order row for it to settle.
             */
            RegisterDomainOnPayment::class,
        ],
        SubscriptionStatusChanged::class => [
            EnforceServiceStateForSubscription::class,
            NotifyOnSubscriptionChange::class,
        ],
        OrderFinanciallySettled::class => [
            FulfilOrderOnSettlement::class,
        ],
        RefundIssued::class => [
            RecordRefundAgainstTheInvoice::class,
        ],

        /*
         * Drift was recorded to a table that only an operator opening the
         * right page would see. The first sighting of a critical
         * disagreement now says so where the platform's alerting can find it.
         */
        /*
         * A hosting account that finished building is the moment a WordPress
         * order can proceed. Without this the whole feature is unreachable:
         * every piece exists and nothing joins them.
         */
        ProvisioningJobSucceeded::class => [
            InstallWordPressOnceTheAccountExists::class,
        ],

        DriftRecorded::class => [
            AlertOnCriticalDrift::class,
        ],

        ProviderReadinessChanged::class => [
            // A provider that stopped being ready takes every product that
            // needs it down with it, in the same transaction.
            ReassessProductsWhenAProviderChanges::class,
        ],

    ];

    /**
     * Subscribers, which map several events to their own methods.
     *
     * Scheduler liveness lives here rather than in $listen because it listens
     * to three of Laravel's own console events and needs a different method
     * for each: finished, failed, and skipped — where "skipped" deliberately
     * records nothing.
     *
     * The failure being watched for is not a command that errors. It is a
     * command that stops being invoked at all — a crashed scheduler, a cron
     * entry lost in a redeploy — and every one of those is silent.
     *
     * @var list<class-string>
     */
    protected $subscribe = [
        RecordScheduledRun::class,

        /*
         * The three provisioning outcome events were raised and nobody
         * listened, which is how a customer could order a server, have it
         * built, and never be told — or have it fail and find out by logging
         * in and looking at a status badge.
         */
        NotifyOnProvisioningOutcome::class,

        // Money. These are the notifications a customer cannot switch off.
        NotifyOnBillingEvent::class,
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
