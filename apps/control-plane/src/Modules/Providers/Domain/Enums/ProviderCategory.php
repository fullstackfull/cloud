<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

/**
 * What a provider is for — never who it is.
 *
 * Category and vendor are separate axes and mixing them is how a codebase ends
 * up with `if ($provider === 'opensrs')` in a place that should only know it
 * needs a registrar. The category is what the platform depends on; the driver
 * is an implementation detail that can be swapped.
 */
enum ProviderCategory: string
{
    case Compute = 'compute';
    case Backup = 'backup';
    case Hosting = 'hosting';
    case WordPressInstaller = 'wordpress_installer';
    case Dns = 'dns';
    case ReverseDns = 'reverse_dns';
    case Registrar = 'registrar';
    case Payment = 'payment';
    case Email = 'email';
    case Bmc = 'bmc';
    case Monitoring = 'monitoring';

    /**
     * Does an instance of this category live on a machine we manage?
     *
     * Cloudflare and a payment gateway are somebody else's servers; a Proxmox
     * node and a cPanel installation are ours. The difference decides whether
     * a provider can be blocked on hardware at all.
     */
    public function needsServer(): bool
    {
        return match ($this) {
            self::Compute, self::Backup, self::Hosting, self::WordPressInstaller, self::Bmc, self::Monitoring => true,
            self::Dns, self::ReverseDns, self::Registrar, self::Payment, self::Email => false,
        };
    }

    /**
     * Which capabilities an instance of this category is asked about.
     *
     * Nothing is inferred from a vendor's name — this is the question set, and
     * the answers come from a connection test against the real thing.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Compute => ['create', 'start', 'stop', 'reboot', 'resize', 'reinstall', 'suspend', 'unsuspend', 'console', 'destroy', 'templates', 'task_polling'],
            self::Backup => ['create', 'restore', 'delete', 'verify', 'retention'],
            self::Hosting => ['create_account', 'suspend', 'unsuspend', 'terminate', 'sso', 'change_package', 'usage'],
            self::WordPressInstaller => ['install', 'uninstall', 'version', 'ssl'],
            self::Dns => ['create_zone', 'delete_zone', 'records', 'reconcile'],
            self::ReverseDns => ['set_ptr', 'clear_ptr'],
            self::Registrar => ['search', 'availability', 'register', 'renew', 'transfer', 'nameservers', 'contacts', 'lock', 'auth_code', 'redemption', 'premium', 'held_names'],
            self::Payment => ['charge', 'refund', 'webhook', 'currencies'],
            self::Email => ['send', 'tls', 'sender_identity'],
            self::Bmc => ['inventory', 'power_state', 'power_control', 'boot_override', 'firmware'],
            self::Monitoring => ['scrape', 'alerting', 'logs'],
        };
    }
}
