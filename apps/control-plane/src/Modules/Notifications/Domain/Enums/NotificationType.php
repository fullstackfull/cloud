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
 */
enum NotificationType: string
{
    // ---------------------------------------------------------------- security
    case PasswordChanged = 'security.password_changed';
    case TwoFactorEnabled = 'security.two_factor_enabled';
    case TwoFactorDisabled = 'security.two_factor_disabled';
    case NewSignIn = 'security.new_sign_in';

    // ----------------------------------------------------------------- billing
    case OrderPlaced = 'billing.order_placed';
    case InvoiceIssued = 'billing.invoice_issued';
    case PaymentSucceeded = 'billing.payment_succeeded';
    case PaymentFailed = 'billing.payment_failed';
    case RefundIssued = 'billing.refund_issued';
    case RenewalUpcoming = 'billing.renewal_upcoming';
    case RenewalSucceeded = 'billing.renewal_succeeded';
    case RenewalFailed = 'billing.renewal_failed';
    case GracePeriodStarted = 'billing.grace_period_started';
    case SuspensionWarning = 'billing.suspension_warning';
    case CancellationScheduled = 'billing.cancellation_scheduled';

    // ----------------------------------------------------------------- service
    case ServiceProvisioning = 'service.provisioning';
    case ServiceReady = 'service.ready';
    case ServiceProvisioningFailed = 'service.provisioning_failed';
    case ServiceNeedsReview = 'service.needs_review';
    case ServiceSuspended = 'service.suspended';
    case ServiceRestored = 'service.restored';
    case ServiceReactivationFailed = 'service.reactivation_failed';
    case ServiceTerminated = 'service.terminated';
    case PlanChangeCompleted = 'service.plan_change_completed';
    case PlanChangeFailed = 'service.plan_change_failed';
    case ReinstallStarted = 'service.reinstall_started';
    case ReinstallCompleted = 'service.reinstall_completed';
    case ReinstallFailed = 'service.reinstall_failed';
    case BackupCompleted = 'service.backup_completed';
    case BackupFailed = 'service.backup_failed';
    case RestoreCompleted = 'service.restore_completed';
    case RestoreFailed = 'service.restore_failed';

    // ------------------------------------------------------------- operational
    case IncidentAffectingService = 'operational.incident';
    case MaintenanceScheduled = 'operational.maintenance_scheduled';

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::PasswordChanged,
            self::TwoFactorEnabled,
            self::TwoFactorDisabled,
            self::NewSignIn => NotificationCategory::Security,

            self::OrderPlaced,
            self::InvoiceIssued,
            self::PaymentSucceeded,
            self::PaymentFailed,
            self::RefundIssued,
            self::RenewalUpcoming,
            self::RenewalSucceeded,
            self::RenewalFailed,
            self::GracePeriodStarted,
            self::SuspensionWarning,
            self::CancellationScheduled => NotificationCategory::Billing,

            self::IncidentAffectingService,
            self::MaintenanceScheduled => NotificationCategory::Operational,

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
            self::RenewalUpcoming, self::RenewalFailed,
            self::GracePeriodStarted, self::SuspensionWarning, self::CancellationScheduled,
            self::ServiceReady, self::ServiceProvisioningFailed,
            self::ServiceSuspended, self::ServiceRestored, self::ServiceReactivationFailed,
            self::ServiceTerminated,
            self::ReinstallCompleted, self::ReinstallFailed,
            self::RestoreCompleted, self::RestoreFailed,
            self::PlanChangeCompleted, self::PlanChangeFailed,
            self::IncidentAffectingService, self::MaintenanceScheduled,
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
            self::RenewalFailed,
            self::ServiceProvisioningFailed,
            self::ServiceNeedsReview,
            self::ServiceReactivationFailed,
            self::PlanChangeFailed,
            self::ReinstallFailed,
            self::BackupFailed,
            self::RestoreFailed,
            self::IncidentAffectingService => true,
            default => false,
        };
    }
}
