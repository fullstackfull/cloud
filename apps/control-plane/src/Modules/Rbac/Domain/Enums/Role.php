<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Domain\Enums;

/**
 * The roles the platform ships with.
 *
 * These are seeded defaults, not a closed set: operators may create additional
 * roles, and may edit the permission set of any role except Super Admin.
 * Nothing in the codebase branches on a role name.
 */
enum Role: string
{
    case SuperAdmin = 'super-admin';
    case InfrastructureAdmin = 'infrastructure-admin';
    case BillingAdmin = 'billing-admin';
    case Support = 'support';
    case NetworkEngineer = 'network-engineer';
    case Noc = 'noc';
    case Finance = 'finance';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::InfrastructureAdmin => 'Infrastructure Admin',
            self::BillingAdmin => 'Billing Admin',
            self::Support => 'Support',
            self::NetworkEngineer => 'Network Engineer',
            self::Noc => 'NOC',
            self::Finance => 'Finance',
            self::Customer => 'Customer',
        };
    }

    /**
     * Whether this role is granted through the platform guard (staff) rather
     * than being the baseline role every customer login holds.
     */
    public function isStaffRole(): bool
    {
        return $this !== self::Customer;
    }

    /**
     * Default permissions seeded for this role.
     *
     * Super Admin is deliberately absent: it is granted every permission via a
     * Gate::before rule rather than by enumerating them, so that a permission
     * added in a future release is not silently missing from it.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [],

            self::InfrastructureAdmin => [
                Permission::CustomerViewAny, Permission::CustomerView,
                Permission::ServiceViewAny, Permission::ServiceManage,
                Permission::ServiceSuspend, Permission::ServiceTerminate,
                Permission::InfrastructureView, Permission::InfrastructureManage,
                Permission::ProviderManage, Permission::CredentialManage,
                Permission::LicenceManage, Permission::DeploymentRun,
                Permission::SafetyChange,
                // Not AllowReimage. Clearing a machine for a wipe is the single
                // most destructive thing an operator can authorise, and it sits
                // with the super-admin until somebody deliberately grants it —
                // a permission held by default is one nobody notices being used.
                Permission::NodeMaintenance, Permission::VmManage, Permission::VmConsole,
                Permission::DedicatedManage, Permission::DedicatedPowerControl,
                Permission::BmcAccess,
                Permission::HostingNodeManage, Permission::HostingAccountManage,
                Permission::IpamView, Permission::IpamManage,
                Permission::NetworkManage, Permission::DnsManage,
                Permission::ProvisioningView, Permission::ProvisioningRetry,
                Permission::DriftView, Permission::DriftResolve,
                Permission::BackupManage, Permission::MonitoringView,
                Permission::IncidentManage,
            ],

            self::BillingAdmin => [
                Permission::CustomerViewAny, Permission::CustomerView, Permission::CustomerUpdate,
                Permission::CustomerSuspend,
                Permission::CatalogView, Permission::CatalogManage,
                Permission::PricingManage, Permission::CouponManage,
                Permission::OrderViewAny, Permission::OrderManage,
                Permission::InvoiceViewAny, Permission::InvoiceManage,
                Permission::PaymentViewAny, Permission::PaymentRefund,
                Permission::WalletAdjust,
                Permission::ServiceViewAny, Permission::ServiceSuspend,
            ],

            self::Finance => [
                Permission::CustomerViewAny, Permission::CustomerView,
                Permission::OrderViewAny,
                Permission::InvoiceViewAny,
                Permission::PaymentViewAny, Permission::PaymentRefund,
                Permission::CatalogView,
            ],

            self::Support => [
                Permission::CustomerViewAny, Permission::CustomerView,
                Permission::ServiceViewAny,
                Permission::OrderViewAny, Permission::InvoiceViewAny,
                Permission::TicketViewAny, Permission::TicketReply, Permission::TicketManage,
                Permission::ProvisioningView,
                Permission::MonitoringView,
            ],

            self::NetworkEngineer => [
                Permission::InfrastructureView,
                Permission::IpamView, Permission::IpamManage,
                Permission::NetworkManage, Permission::DnsManage,
                Permission::MonitoringView,
            ],

            self::Noc => [
                Permission::InfrastructureView,
                Permission::ServiceViewAny,
                Permission::MonitoringView,
                Permission::IncidentManage,
                Permission::ProvisioningView, Permission::ProvisioningRetry,
                Permission::DriftView,
                Permission::NodeMaintenance,
            ],

            // The baseline role every customer login holds. Customer-scoped
            // authority comes from CustomerRole within their own account, not
            // from platform permissions.
            self::Customer => [
                Permission::CatalogView,
            ],
        };
    }
}
