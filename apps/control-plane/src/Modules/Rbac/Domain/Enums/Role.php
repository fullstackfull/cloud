<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Domain\Enums;

/**
 * The roles the platform ships with.
 *
 * These are seeded defaults. Operators may edit the permission set of any role
 * except Super Admin and Customer (see permissionsAreEditable()); there is no
 * route that creates a role. Authorization decisions are made on permissions,
 * with one exception by role kind rather than by name: /api/admin admits only
 * a login holding a staff role (staffRoleNames()).
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
     * The names of every staff role: what a login must hold at least one of
     * to reach /api/admin at all (EnsureTheCallerIsStaff), whatever
     * permissions it holds.
     *
     * From the enum rather than the roles table. A row in that table that this
     * enum does not declare — none can be created through the application,
     * which has no route that creates a role — is not staff, and neither is
     * `customer`.
     *
     * @return list<string>
     */
    public static function staffRoleNames(): array
    {
        return array_values(array_map(
            static fn (self $role): string => $role->value,
            array_filter(self::cases(), static fn (self $role): bool => $role->isStaffRole()),
        ));
    }

    /**
     * Whether an operator may edit this role's permission list through the
     * role routes. Two may not, for different reasons:
     *
     *  - Super Admin: its authority comes from the Gate::before bypass, so
     *    its rows decide nothing and editing them would only look like a
     *    restriction.
     *  - Customer: every customer login holds it, including every one
     *    registered after the change. Widening it grants a permission to the
     *    whole customer base at once (OB-1: a delegate gave it its own
     *    permissions and every customer read /api/admin/operators); narrowing
     *    it takes `catalog.view` from every customer and breaks the
     *    storefront. Its list is fixed by defaultPermissions() and the seeder
     *    — a release, reviewed like code — and by nobody at runtime, super
     *    admin included.
     */
    public function permissionsAreEditable(): bool
    {
        return $this !== self::SuperAdmin && $this !== self::Customer;
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
                // Held with manage by the one role that holds manage, so no
                // installation's effective authority moved when it was split
                // out; a role composed later can hold one without the other.
                Permission::HostingAccountResetPassword,
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
