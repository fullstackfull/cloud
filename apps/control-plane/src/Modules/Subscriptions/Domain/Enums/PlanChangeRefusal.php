<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Enums;

/**
 * Why a plan cannot be moved to.
 *
 * Named rather than free text because the portal has to say each of these in
 * two languages, and because a customer told only "incompatible" will open a
 * ticket asking which part.
 */
enum PlanChangeRefusal: string
{
    /** A VPS plan and a hosting plan are not alternatives to one another. */
    case DifferentProduct = 'different_product';

    /** The plan is not sold in this subscription's currency. */
    case DifferentCurrency = 'different_currency';

    /** Monthly to yearly is a renewal decision, not a plan change. */
    case DifferentBillingPeriod = 'different_billing_period';

    /** The subscription already sits on this plan. */
    case SamePlan = 'same_plan';

    /** Withdrawn from sale, or never offered. */
    case NotAvailable = 'not_available';

    /**
     * The target sells a smaller disk than the customer currently has.
     *
     * Refused rather than warned about. Shrinking a disk truncates a
     * filesystem, and the platform will not destroy data to make a downgrade
     * arithmetically possible.
     */
    case WouldShrinkDisk = 'would_shrink_disk';

    /** The subscription is suspended, cancelled or otherwise not running. */
    case SubscriptionNotRunning = 'subscription_not_running';

    /** Something is already being done to the service this subscription pays for. */
    case ServiceBusy = 'service_busy';

    /**
     * The service is not in a state where it could receive the change.
     *
     * Suspended, being reactivated, still building, or already terminated.
     * The money moves the moment the customer confirms a plan change and the
     * machine catches up afterwards — so allowing one here would charge a
     * customer for an upgrade the platform has deliberately locked their
     * machine against, and the resize would fail at the hypervisor for
     * exactly the reason the suspension exists.
     */
    case ServiceNotActive = 'service_not_active';

    /**
     * The price named does not belong to the plan named.
     *
     * Both arrive from the client, and they were checked separately: a
     * request naming an expensive plan and a cheap plan's price moved the
     * subscription onto the expensive plan at the cheap price, for ever.
     * Nothing about a legitimate client produces this pair — the plan options
     * endpoint returns each plan with its own prices — so it is refused
     * rather than reconciled.
     */
    case PriceNotForPlan = 'price_not_for_plan';

    /**
     * An invoice for this subscription is still open.
     *
     * Most often the one the last upgrade left behind. A plan move is priced
     * from the plan the subscription is on, and while that plan's money is
     * unpaid a second move would be priced from money that never arrived: the
     * re-audit flapped small -> large -> small three times, paid nothing, and
     * was left with 162.000 KWD of wallet credit. The customer settles what
     * they owe on the subscription, or has it voided, and the change is
     * theirs to make again.
     */
    case InvoiceOutstanding = 'invoice_outstanding';

    /**
     * Every unit the plan has is already held (`stock_limit`).
     *
     * The same rule a checkout obeys, under the same lock: a plan change was
     * a second way onto a finite plan that never asked.
     */
    case OutOfStock = 'out_of_stock';

    /** The account already holds as many of this plan as it may (`per_customer_limit`). */
    case PerCustomerLimit = 'per_customer_limit';

    /**
     * How many units the subscription holds cannot be derived from what it
     * bills - a price the catalogue has since moved off, which the recurring
     * amount no longer divides by. A plan change keeps the unit count and the
     * request cannot name one, so the change waits for an operator to correct
     * the subscription rather than guessing at one unit and under-billing.
     */
    case UnitCountUnknown = 'unit_count_unknown';
}
