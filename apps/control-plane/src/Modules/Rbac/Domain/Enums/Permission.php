<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Domain\Enums;

/**
 * Every platform permission, enumerated.
 *
 * Authorisation checks name a permission, never a role. Roles are editable
 * groupings that operators can reshape without a deploy; permissions are the
 * stable contract the code depends on.
 */
enum Permission: string
{
    // --- Customers and organizations -----------------------------------------
    case CustomerViewAny = 'customer.view_any';
    case CustomerView = 'customer.view';
    case CustomerCreate = 'customer.create';
    case CustomerUpdate = 'customer.update';
    case CustomerSuspend = 'customer.suspend';
    case CustomerImpersonate = 'customer.impersonate';

    // --- Catalogue and pricing ------------------------------------------------
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';
    case PricingManage = 'pricing.manage';
    case CouponManage = 'coupon.manage';

    // --- Commerce -------------------------------------------------------------
    case OrderViewAny = 'order.view_any';
    case OrderManage = 'order.manage';
    case InvoiceViewAny = 'invoice.view_any';
    case InvoiceManage = 'invoice.manage';
    case PaymentViewAny = 'payment.view_any';
    case PaymentRefund = 'payment.refund';
    case WalletAdjust = 'wallet.adjust';

    // --- Services -------------------------------------------------------------
    case ServiceViewAny = 'service.view_any';
    case ServiceManage = 'service.manage';
    case ServiceSuspend = 'service.suspend';
    case ServiceTerminate = 'service.terminate';

    // --- Infrastructure -------------------------------------------------------
    case InfrastructureView = 'infrastructure.view';
    case InfrastructureManage = 'infrastructure.manage';
    case NodeMaintenance = 'node.maintenance';
    case VmManage = 'vm.manage';
    case VmConsole = 'vm.console';
    case DedicatedManage = 'dedicated.manage';
    case DedicatedPowerControl = 'dedicated.power_control';
    case BmcAccess = 'bmc.access';
    case HostingNodeManage = 'hosting_node.manage';
    case HostingAccountManage = 'hosting_account.manage';

    // --- Networking -----------------------------------------------------------
    case IpamView = 'ipam.view';
    case IpamManage = 'ipam.manage';
    case NetworkManage = 'network.manage';
    case DnsManage = 'dns.manage';

    // --- Operations -----------------------------------------------------------
    case ProvisioningView = 'provisioning.view';
    case ProvisioningRetry = 'provisioning.retry';
    case DriftView = 'drift.view';
    case DriftResolve = 'drift.resolve';
    case BackupManage = 'backup.manage';
    case MonitoringView = 'monitoring.view';
    case IncidentManage = 'incident.manage';

    // --- Support --------------------------------------------------------------
    case TicketViewAny = 'ticket.view_any';
    case TicketReply = 'ticket.reply';
    case TicketManage = 'ticket.manage';

    // --- Security and platform ------------------------------------------------
    case AuditView = 'audit.view';
    case SecurityView = 'security.view';
    case SettingsManage = 'settings.manage';
    case IntegrationManage = 'integration.manage';
    case FeatureFlagManage = 'feature_flag.manage';
    case RoleManage = 'role.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Grouping used only for presentation in the admin role editor.
     */
    public function group(): string
    {
        return str_contains($this->value, '.')
            ? explode('.', $this->value)[0]
            : 'general';
    }
}
