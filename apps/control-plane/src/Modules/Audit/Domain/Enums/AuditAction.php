<?php

declare(strict_types=1);

namespace Lynomia\Modules\Audit\Domain\Enums;

/**
 * The acts worth a permanent record.
 *
 * An enum rather than free text, so that the trail can be filtered and
 * alerted on, and so that adding a new irreversible operation is a decision
 * somebody makes here rather than a string somebody types at a call site.
 *
 * The list is deliberately short. Everything on it either moves money, takes
 * a service away from a customer, gives one back, or overwrites data that
 * cannot be recovered. Reads are not audited: an audit trail that records
 * every page view is one nobody can find anything in.
 */
enum AuditAction: string
{
    // Money.
    case InvoiceVoided = 'invoice.voided';
    case PaymentRefunded = 'payment.refunded';

    // Taking a service away, and giving it back.
    case CustomerSuspended = 'customer.suspended';
    case CustomerUnsuspended = 'customer.unsuspended';
    case HostingAccountUnsuspended = 'hosting_account.unsuspended';
    case HostingAccountTerminated = 'hosting_account.terminated';
    case SubscriptionCancelled = 'subscription.cancelled';

    // Data that cannot be recovered once it is gone.
    case BackupRestored = 'backup.restored';

    // Operator intervention in the provisioning and reconciliation engines,
    // where the operator is asserting something about the outside world that
    // the platform could not verify itself.
    case OrphanAdopted = 'provisioning.orphan_adopted';
    case ProvisioningRetried = 'provisioning.retried';
    case DriftAcknowledged = 'drift.acknowledged';
    case DriftResolved = 'drift.resolved';
    case ReconciliationRequested = 'infrastructure.reconciliation_requested';

    /*
     * Consoles. Both halves are recorded because they answer different
     * questions: issuing says who asked for root access to a machine, and
     * redeeming says whether anybody actually took it — and a permit issued
     * and never redeemed is a very different afternoon from one redeemed from
     * an address nobody recognises.
     */
    case ConsolePermitIssued = 'console.permit_issued';
    case ConsolePermitRedeemed = 'console.permit_redeemed';
    case ConsolePermitRefused = 'console.permit_refused';

    /**
     * A subscription moved onto another plan.
     *
     * Money and capacity both change here, and the customer chose it — so the
     * trail records who, when, and what it cost, which is what a billing
     * dispute is settled from.
     */
    case PlanChanged = 'subscription.plan_changed';

    /* Rebuilds, which destroy data on purpose. */
    case VpsReinstallRequested = 'vps.reinstall_requested';
    case DedicatedReinstallRequested = 'dedicated.reinstall_requested';

    /**
     * Whether the act was a person asserting something the platform could not
     * check for itself.
     *
     * These are the rows a reviewer reads first after an incident: they are
     * the points where the system's picture of the world was changed by
     * assertion rather than by observation.
     */
    public function isAnAssertionAboutTheWorld(): bool
    {
        return match ($this) {
            self::OrphanAdopted, self::DriftResolved, self::DriftAcknowledged => true,
            default => false,
        };
    }
}
