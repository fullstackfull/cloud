<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\Enums;

/**
 * Every message the platform sends a customer about their account.
 *
 * An enum rather than free strings, so that the set is finite, translatable,
 * and filterable — and so that adding one is a decision made here rather than
 * a string typed at a call site and never translated.
 *
 * Each case knows its own category, which is what decides whether a customer
 * may switch it off. Putting that here rather than at the call site means a
 * new type cannot accidentally become silenceable by being sent from the wrong
 * place.
 *
 * ---------------------------------------------------------------------------
 * A case here is a promise, so every one of them is raised
 * ---------------------------------------------------------------------------
 *
 * F-46 found thirteen cases declared, given an English and an Arabic sentence,
 * and raised by nothing — a list of things the platform said it told customers
 * and did not. Five were wired where their moment happens; eight were deleted
 * with their copy, each for the reason written beside where it stood. A type
 * that no production code names in a producing position now fails
 * `EveryNotificationTypeHasAProducerTest`, so a new case arrives with a
 * producing reference to it or not at all. That test reads references, not
 * call paths: whether anything reaches the reference is not asked there, as
 * its own docblock says.
 */
enum NotificationType: string
{
    // ---------------------------------------------------------------- security
    /*
     * Personal, not the account's: raised by NotifyAboutAccountSecurity for
     * the person whose password, second factor or sign-in it was, emailed to
     * them rather than to the billing address, and shown in the inbox to them
     * alone.
     */
    case PasswordChanged = 'security.password_changed';
    case TwoFactorEnabled = 'security.two_factor_enabled';
    case TwoFactorDisabled = 'security.two_factor_disabled';
    case NewSignIn = 'security.new_sign_in';

    // ----------------------------------------------------------------- billing
    /*
     * Four billing types were deleted rather than wired (F-46):
     *
     *  - "order placed" — every order that owes money is issued an invoice by
     *    EvaluateOrderFinancialRequirement in the same moment, and InvoiceIssued
     *    says so; one that owes nothing is never paid for, so "we will start
     *    as soon as it is paid" would be false. Either way a second message.
     *  - "renewal failed" — a failed renewal payment is already told twice:
     *    PaymentFailed by NotifyOnBillingEvent, and GracePeriodStarted when
     *    dunning moves the subscription to past_due. A third is noise on the
     *    one channel a customer cannot switch off.
     *  - "renewal upcoming" and "suspension warning" — reminders need a
     *    schedule, and none exists: the renewal clock bills at the period end
     *    and InvoiceIssued announces that bill, and dunning has one warning,
     *    GracePeriodStarted, which already names the day service stops.
     *    Building reminder schedules is a billing policy nobody has decided.
     */
    case InvoiceIssued = 'billing.invoice_issued';
    case PaymentSucceeded = 'billing.payment_succeeded';
    case PaymentFailed = 'billing.payment_failed';
    case RefundIssued = 'billing.refund_issued';
    case RenewalSucceeded = 'billing.renewal_succeeded';
    case GracePeriodStarted = 'billing.grace_period_started';
    case CancellationScheduled = 'billing.cancellation_scheduled';
    /*
     * A paid plan change its settlement found could no longer be delivered,
     * returned: the payment to the wallet, the subscription to the plan it
     * came from (ReturnAPlanChangeNoLongerDeliverable). The customer paid for
     * a change and did not get it, and is told where the money went.
     */
    case PlanChangeReturned = 'billing.plan_change_returned';
    /*
     * A paid plan change returned because its subscription or its service
     * ended before it was delivered: the settlement heard after the end, or
     * the resize or package change that stopped (in review, failed, closed)
     * on a service that then ended (ReturnAnUpgradeTheEndPrevented). The
     * sibling above says the change "could no longer be made when your
     * payment arrived", which is not what happened here; this one says the
     * service ended before the change was completed, and where the money went.
     */
    case PlanChangeReturnedAtTheEnd = 'billing.plan_change_returned_at_the_end';
    /*
     * A paid plan change held for an operator on a live service - its resize
     * or package change stopped in review or failed - that the operator
     * returned (ReturnAHeldPaidChange, through
     * ReturnAPlanChangeNoLongerDeliverable::returnHeld()): the change is
     * cancelled and the payment is in the wallet. Two types, because what
     * the customer is billed next differs and each sentence must be true: the
     * subscription went back to its earlier plan, or it did not (it was
     * changed again since, or it has ended).
     */
    case HeldPlanChangeReturned = 'billing.held_plan_change_returned';
    case HeldPlanChangeReturnedPlanKept = 'billing.held_plan_change_returned_plan_kept';

    /*
     * The account's country or currency, decided. Applied says from when
     * new invoices carry the new currency and tax and that old ones do not
     * change; rejected carries the operator's note; needs-review says the
     * change was approved and then held because something on the account
     * changed, and asks nothing of the customer but patience.
     */
    case CountryCurrencyChangeApplied = 'billing.country_currency_change_applied';
    case CountryCurrencyChangeRejected = 'billing.country_currency_change_rejected';
    case CountryCurrencyChangeNeedsReview = 'billing.country_currency_change_needs_review';

    // ----------------------------------------------------------------- service
    /*
     * There is no "setting up your service" and no "reinstall started" (F-46,
     * deleted). NotifyOnProvisioningOutcome's rule is that a customer hears
     * about the work they cannot watch, and the start of a build or a
     * reinstall is the moment right after they paid or pressed the button.
     * What they cannot watch is the outcome, and every outcome has its own
     * message below.
     */
    case ServiceReady = 'service.ready';
    case ServiceProvisioningFailed = 'service.provisioning_failed';
    case ServiceNeedsReview = 'service.needs_review';
    case ServiceSuspended = 'service.suspended';
    case ServiceRestored = 'service.restored';
    case ServiceReactivationFailed = 'service.reactivation_failed';
    /**
     * The service stopped serving because the subscription ended.
     *
     * Distinct from `ServiceTerminated`, which is the data being destroyed, and
     * from `ServiceSuspended`, which is what happens to somebody who has not
     * paid. Three different sentences for three different days, and a customer
     * who chose to leave should not be sent the one written for a debtor.
     */
    case ServiceEnded = 'service.ended';

    case ServiceTerminated = 'service.terminated';

    /**
     * The one message on this list whose whole value is that it arrives early.
     *
     * A retention window is a month, and a month is long enough to forget a
     * cancellation made in a hurry. "Your data is destroyed on Friday" is a
     * sentence somebody can act on; "your data has been destroyed" is one they
     * can only regret.
     */
    case DataRetentionEnding = 'service.data_retention_ending';
    case PlanChangeCompleted = 'service.plan_change_completed';
    /*
     * Two failures, because they differ in the money. A change that owed
     * nothing (a downgrade, a move between equal prices) is queued when it is
     * made, and nothing was charged for it: PlanChangeFailed says so, and -
     * because the subscription moved, with its price and any credit, when
     * the change was made - says the subscription is on the new plan while
     * the change to the service did not complete (it used to say the change
     * was "left as it was", which the re-audit after round six found false,
     * and then that the service was "still running as it was", false of a
     * machine a destroy removed while the resize ran - B-2, the verification
     * of round eight A). An
     * upgrade is queued only once its proration invoice is paid, so its
     * failure has been charged, and the payment is held for an operator to
     * complete the change or return it, or returned to the wallet without one
     * if the service ends first (ReturnAnUpgradeTheEndPrevented, which tells
     * the customer with PlanChangeReturnedAtTheEnd):
     * PlanChangeFailedAfterPayment. The one
     * message used to tell both that nothing had been charged.
     * PlanChangeFailedAfterPayment says nothing of the state the service is
     * in: it said the service was "still running as it was", which was false
     * of a resize the hypervisor made and could not confirm - then failed
     * (A8-1, the re-audit after round seven; such a resize now stops in
     * review).
     */
    case PlanChangeFailed = 'service.plan_change_failed';
    case PlanChangeFailedAfterPayment = 'service.plan_change_failed_after_payment';
    /*
     * A paid plan change whose provider half stopped in review - a resize
     * the node could no longer hold, retried to the end, a resize whose
     * outcome could not be confirmed, or a package change that timed out.
     * The build's "needs our attention" says setting the service up did not
     * finish, which is not what happened: the customer is told the change is
     * waiting for the team and what they paid for it is held
     * (NotifyOnProvisioningOutcome) - until the change is applied, or
     * returned: by an operator while the service lives, and without one when
     * the service ends first (PlanChangeReturnedAtTheEnd). Not that the service runs as it was: a
     * resize that could not be confirmed may have been made, so the message
     * says only that the service may not match the new plan yet.
     */
    case PlanChangeNeedsReview = 'service.plan_change_needs_review';
    case ReinstallCompleted = 'service.reinstall_completed';
    case ReinstallFailed = 'service.reinstall_failed';
    case BackupCompleted = 'service.backup_completed';
    case BackupFailed = 'service.backup_failed';
    case RestoreCompleted = 'service.restore_completed';
    case RestoreFailed = 'service.restore_failed';

    /*
     * The third answer a whole-machine restore can give, and the reason it has
     * to exist: a provider that stopped answering leaves a restore that may be
     * writing to the customer's disks right now. Reporting that as failed
     * invites them to start a second one over the top; reporting it as
     * completed is simply untrue. The file-level path has had this word since
     * it was written — see FileRestoreNeedsReview — and the whole-machine path
     * announced nothing at all.
     */
    case RestoreNeedsReview = 'service.restore_needs_review';

    /*
     * The backup's own third answer. Not "it failed" — nothing failed, and
     * saying so would send a customer to take another backup when the first
     * one may be sitting on a datastore taking up space. Not "it completed"
     * either. The platform lost track of the task and only a person looking at
     * the datastore can settle whether the archive is there.
     */
    case BackupNeedsReview = 'service.backup_needs_review';

    /*
     * The archive stored and cannot be read back.
     *
     * Not BackupFailed, which would be false: the backup ran, the task
     * reported OK, and what is wrong is the data it left behind. Raised only
     * on a verdict — a datastore that timed out or has not looked yet has said
     * nothing, and `verified` stays null for exactly that reason.
     */
    case BackupVerificationFailed = 'service.backup_verification_failed';

    /*
     * Files put back from a backup. Three outcomes, and the third is the
     * one that must not be dressed as either of the others: a restore the
     * provider never answered for may or may not have written the files.
     */
    case FileRestoreCompleted = 'service.file_restore_completed';
    case FileRestoreFailed = 'service.file_restore_failed';
    case FileRestoreNeedsReview = 'service.file_restore_needs_review';

    /*
     * Domains.
     *
     * Four moments, and the last two are the ones that matter most. A domain
     * cannot be repossessed and cannot be un-lost: a customer who is not told
     * their name is about to lapse loses it, and a customer whose registration
     * the platform could not confirm needs to hear that from the platform
     * rather than from a WHOIS lookup.
     */
    case DomainRegistered = 'service.domain_registered';
    case DomainRegistrationFailed = 'service.domain_registration_failed';
    case DomainExpiring = 'service.domain_expiring';
    case DomainNeedsReview = 'service.domain_needs_review';
    case DomainRedeemed = 'service.domain_redeemed';
    case DomainRedemptionFailed = 'service.domain_redemption_failed';

    /*
     * A push of a staging copy over production. Completed says the live
     * site is now the copy; failed says production is as it was; on hold
     * says the toolkit never answered and production may be half-written,
     * and asks the customer not to push again.
     */
    case WordPressPushCompleted = 'service.wordpress_push_completed';
    case WordPressPushFailed = 'service.wordpress_push_failed';
    case WordPressPushNeedsReview = 'service.wordpress_push_needs_review';

    case TicketOpened = 'service.ticket_opened';
    case TicketReplied = 'service.ticket_replied';
    case TicketResolved = 'service.ticket_resolved';
    case TicketClosed = 'service.ticket_closed';

    /*
     * There is no "incident affecting your service" and no "maintenance
     * scheduled" (F-46, deleted with the operational category they were the
     * only members of). The platform records no incident and schedules no
     * maintenance window — a node can be put into maintenance, which is now
     * and not "planned for a date" — so nothing could honestly raise either.
     * Announcing incidents to the customers they touch is a capability to
     * build, with its own operator screen, and not a string to keep.
     */

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::PasswordChanged,
            self::TwoFactorEnabled,
            self::TwoFactorDisabled,
            self::NewSignIn => NotificationCategory::Security,

            self::InvoiceIssued,
            self::PaymentSucceeded,
            self::PaymentFailed,
            self::RefundIssued,
            self::RenewalSucceeded,
            self::GracePeriodStarted,
            self::CancellationScheduled,
            self::PlanChangeReturned,
            self::PlanChangeReturnedAtTheEnd,
            self::HeldPlanChangeReturned,
            self::HeldPlanChangeReturnedPlanKept,
            self::CountryCurrencyChangeApplied,
            self::CountryCurrencyChangeRejected,
            self::CountryCurrencyChangeNeedsReview => NotificationCategory::Billing,

            default => NotificationCategory::Service,
        };
    }

    /**
     * The channels this type is delivered on before preferences are applied.
     *
     * Everything reaches the inbox. Email is reserved for the messages a
     * customer needs to act on or would want to know about away from the
     * portal — a platform that emails on every state transition of a
     * provisioning job teaches its customers to filter its mail, and the one
     * that matters goes to the same folder.
     *
     * @return list<NotificationChannel>
     */
    public function defaultChannels(): array
    {
        $emailed = [
            self::PasswordChanged, self::TwoFactorEnabled, self::TwoFactorDisabled, self::NewSignIn,
            self::InvoiceIssued, self::PaymentFailed, self::RefundIssued,
            self::GracePeriodStarted, self::CancellationScheduled, self::PlanChangeReturned, self::PlanChangeReturnedAtTheEnd,
            self::HeldPlanChangeReturned, self::HeldPlanChangeReturnedPlanKept,
            self::CountryCurrencyChangeApplied, self::CountryCurrencyChangeRejected,
            self::ServiceReady, self::ServiceProvisioningFailed,
            self::ServiceSuspended, self::ServiceRestored, self::ServiceReactivationFailed,
            self::ServiceEnded, self::ServiceTerminated, self::DataRetentionEnding,
            self::ReinstallCompleted, self::ReinstallFailed,
            /*
             * A restore nobody can settle is emailed beside the other two
             * outcomes of the same operation. The channel follows the family
             * rather than a fresh judgement about severity: a customer who is
             * told "do not start another one until we have looked" needs that
             * away from the portal, for the same reason the other two are.
             */
            self::RestoreCompleted, self::RestoreFailed, self::RestoreNeedsReview,
            self::PlanChangeCompleted, self::PlanChangeFailed, self::PlanChangeFailedAfterPayment, self::PlanChangeNeedsReview,
        ];

        return in_array($this, $emailed, strict: true)
            ? [NotificationChannel::InApp, NotificationChannel::Email]
            : [NotificationChannel::InApp];
    }

    /**
     * Whether this type reports something going wrong.
     *
     * Used by the inbox to decide emphasis, and by the operator screen to find
     * the customers who have been told bad news — which is who support hears
     * from next.
     */
    public function isFailure(): bool
    {
        return match ($this) {
            self::PaymentFailed,
            self::ServiceProvisioningFailed,
            self::ServiceNeedsReview,
            self::ServiceReactivationFailed,
            self::PlanChangeFailed,
            self::PlanChangeFailedAfterPayment,
            self::PlanChangeNeedsReview,
            self::ReinstallFailed,
            self::BackupFailed,
            self::BackupNeedsReview,
            self::BackupVerificationFailed,
            self::RestoreFailed,
            self::RestoreNeedsReview,
            self::FileRestoreFailed,
            self::FileRestoreNeedsReview,
            self::WordPressPushFailed,
            self::WordPressPushNeedsReview,
            self::DomainRedemptionFailed => true,
            default => false,
        };
    }
}
