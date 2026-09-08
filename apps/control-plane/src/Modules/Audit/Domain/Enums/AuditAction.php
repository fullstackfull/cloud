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

    /**
     * A service ended and its machine destroyed.
     *
     * The most irreversible act the platform performs on a customer's data,
     * and the one whose "who authorised this, and did they skip the retention
     * window" question has to stay answerable years later.
     */
    case ServiceTerminated = 'service.terminated';

    /**
     * A physical machine was declared empty and put back on the shelf.
     *
     * An assertion about the world in its purest form: nothing the platform
     * can call proves that a disk was erased, so this row is somebody's word
     * for it — and it is the row that matters if the next customer finds
     * anything on the machine.
     */
    case DedicatedServerReturnedToStock = 'dedicated_server.returned_to_stock';
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
     * An operator's verdict on a rebuild the platform could not settle for
     * itself.
     *
     * Both are assertions about the world, and they are the two most
     * consequential ones the platform accepts: `confirmed` says a machine
     * whose outcome was unknown came back, and `abandoned` says it did not.
     * Neither is inferred from anything — a person looked at a hypervisor or a
     * console and said so.
     */
    case ReinstallConfirmed = 'reinstall.confirmed';
    case ReinstallAbandoned = 'reinstall.abandoned';

    /**
     * Somebody turned an optional message off, or back on.
     *
     * Recorded because the next dispute is "you never told me my server was
     * suspended", and the answer is either "we did, here it is" or "you asked
     * us not to, on this date". Without the row the platform cannot tell those
     * apart either.
     */
    case NotificationPreferenceChanged = 'notification.preference_changed';
    case TicketAssigned = 'support.ticket_assigned';
    case TicketPrioritised = 'support.ticket_prioritised';
    case TicketResolved = 'support.ticket_resolved';
    case TicketClosed = 'support.ticket_closed';
    case TicketReopened = 'support.ticket_reopened';
    case MemberInvited = 'membership.invited';
    case MemberInvitationRevoked = 'membership.invitation_revoked';
    case MemberJoined = 'membership.joined';
    case MemberRoleChanged = 'membership.role_changed';
    case MemberRemoved = 'membership.removed';
    case OwnershipTransferred = 'membership.ownership_transferred';

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
            self::OrphanAdopted, self::DriftResolved, self::DriftAcknowledged,
            self::ReinstallConfirmed, self::ReinstallAbandoned,
            self::DedicatedServerReturnedToStock => true,
            default => false,
        };
    }
}
