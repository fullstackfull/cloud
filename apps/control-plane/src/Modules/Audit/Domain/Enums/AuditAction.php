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
    case BackupDeletionRequested = 'backup.deletion_requested';

    /**
     * Somebody called a deletion off while there was still time.
     *
     * The other half of the pair above, and worth as much: the grace period
     * exists so a mis-click can be undone, and an archive that survives
     * because a person changed their mind is a decision somebody made. Without
     * this row the trail says a deletion was asked for and stops, and the next
     * question — why is this backup still here — has no answer in it.
     */
    case BackupDeletionCancelled = 'backup.deletion_cancelled';

    /**
     * Credit was spent on an invoice.
     *
     * The transaction row records the money. This records the act: who was
     * signed in when a balance the customer could have had refunded went to
     * an invoice instead. It is the one payment path with no external
     * processor behind it, so there is no gateway's own record to fall back
     * on when somebody disputes it months later.
     */
    case WalletCreditSpent = 'wallet.credit_spent';
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

    /*
     * DNS. Zone-level acts are audited and record-level acts are audited, and
     * that is not duplication: giving a domain up takes every name under it
     * with it, and an operator asked "when did mail stop working" needs to see
     * which of the two happened.
     */
    case DnsZoneCreated = 'dns.zone.created';
    case DnsZoneDeleted = 'dns.zone.deleted';
    case DnsRecordCreated = 'dns.record.created';
    case DnsRecordUpdated = 'dns.record.updated';
    case DnsRecordDeleted = 'dns.record.deleted';

    /*
     * Domains.
     *
     * A registration is money spent on something that cannot be given back:
     * the registry fee is gone the moment it succeeds, and a name registered
     * to the wrong registrant is a support case measured in weeks. So the
     * order is recorded with the act that creates it, in the same
     * transaction, rather than logged afterwards by whoever remembers.
     */
    case DomainRegistrationOrdered = 'domain.registration.ordered';
    case DomainRenewalOrdered = 'domain.renewal.ordered';
    case DomainTransferOrdered = 'domain.transfer.ordered';
    case DomainNameserversChanged = 'domain.nameservers.changed';
    case DomainContactsChanged = 'domain.contacts.changed';
    case DomainLocked = 'domain.locked';

    /*
     * Unlocking and issuing an authorisation code are the two steps by which a
     * name leaves this platform, and together they are what a stolen account
     * does first. Recorded separately from the lock so that "somebody unlocked
     * this and took the code within the minute" is one query rather than an
     * eyeball exercise.
     */
    case DomainUnlocked = 'domain.unlocked';
    case DomainAuthorisationCodeIssued = 'domain.authorisation_code.issued';

    /*
     * WordPress.
     *
     * Ordering a site commits the account to hosting and, where the name is
     * being registered with it, to a registry fee. Recorded with the act that
     * creates it rather than logged afterwards by whoever remembers.
     */
    case WordPressSiteOrdered = 'wordpress.site.ordered';

    // ---------------------------------------------------------------------
    // Infrastructure: the machines, and what an operator may do to them
    // ---------------------------------------------------------------------
    //
    // Safety changes are recorded apart from every other server edit, because
    // they are the acts that decide what else is possible and the ones an
    // investigation reads first.
    //
    // Each word here is added in the same commit as the thing that writes it.
    // Declaring the whole vocabulary up front and filling it in later is how
    // an enum comes to describe capabilities the platform does not have, which
    // is the defect EveryAuditActionIsRecordedSomewhereTest exists to catch.
    case ServerRegistered = 'infrastructure.server.registered';
    case ServerSafetyChanged = 'infrastructure.server.safety_changed';
    case ServerReimageCleared = 'infrastructure.server.reimage_cleared';
    case ServerReimageClearanceRevoked = 'infrastructure.server.reimage_clearance_revoked';

    case ConnectionTested = 'providers.connection.tested';
    case ProviderRegistered = 'providers.provider.registered';
    case ProviderEnabled = 'providers.provider.enabled';
    case ProviderDisabled = 'providers.provider.disabled';
    case CredentialRecorded = 'providers.credential.recorded';
    case CredentialAttached = 'providers.credential.attached';
    case CredentialDetached = 'providers.credential.detached';
    case CredentialRotated = 'providers.credential.rotated';
    case CredentialRevoked = 'providers.credential.revoked';

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
