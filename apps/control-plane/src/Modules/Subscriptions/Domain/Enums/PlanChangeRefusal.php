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
}
