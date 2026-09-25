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
 *
 * Two email categories on purpose. `email` is the relay this platform sends
 * its own notifications through; `email_hosting` is the mail platform a
 * customer's mailboxes live on. One vendor could serve both, and the
 * platform still asks each question separately, because "can we send a
 * password reset" and "can a customer receive mail at their domain" fail for
 * different reasons and are fixed by different people.
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

    // Seats prepared by the scope addendum. No driver is catalogued for any
    // of them; the category exists so a product can name what it will need.
    case Cdn = 'cdn';
    case ObjectStorage = 'object_storage';
    case EmailHosting = 'email_hosting';
    case LoadBalancer = 'load_balancer';
    case Certificates = 'certificates';
    case ClusterLifecycle = 'cluster_lifecycle';

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
            self::Compute, self::Backup, self::Hosting, self::WordPressInstaller, self::Bmc, self::Monitoring,
            self::ObjectStorage, self::EmailHosting, self::LoadBalancer, self::ClusterLifecycle => true,
            self::Dns, self::ReverseDns, self::Registrar, self::Payment, self::Email, self::Cdn, self::Certificates => false,
        };
    }

    /**
     * Which capabilities an instance of this category is asked about.
     *
     * Nothing is inferred from a vendor's name — this is the question set, and
     * the answers come from a connection test against the real thing.
     *
     * Every capability here is consumed: named by a product requirement in
     * ProductRequirements, which is what EveryDeclaredCapabilityHasAConsumerTest
     * checks. A capability nobody consumes is a question whose answer nobody
     * reads, and a discovered "supported" that nobody reads is a claim the
     * platform makes about itself for no reason. That is why `email` lost
     * `tls` and `sender_identity` and `monitoring` lost `logs` in the scope
     * addendum: no code asked.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return match ($this) {
            /*
             * `inventory_sync` is the scheduled read of nodes and storage
             * pools. It is a question of its own because a cluster can answer
             * everything else and still show the platform no pool — and the
             * scheduler places only on pools the sync recorded, so such a
             * cluster places nothing. VPS requires it; GPU compute inherits it
             * through its dependency on VPS.
             */
            self::Compute => ['create', 'start', 'stop', 'reboot', 'resize', 'reinstall', 'suspend', 'unsuspend', 'console', 'destroy', 'templates', 'task_polling', 'inventory_sync', 'gpu_passthrough'],
            /*
             * `verify` and `verification_verdict` are two different questions
             * and only the second one is the product's promise.
             *
             * `verify` is "can this platform ask the provider to read an
             * archive back now?". Proxmox Backup Server cannot be asked: a
             * verification runs on the backup server on its own schedule and
             * the hypervisor API exposes no endpoint that starts one, so
             * ProxmoxBackupProvider::supportsVerification() answers false and
             * startVerification() refuses.
             *
             * `verification_verdict` is "can this platform find out whether an
             * archive was read back, and what the answer was?". That it can:
             * listBackups() carries a three-valued verdict per archive — read
             * back cleanly, failed, not yet checked — and reconciliation
             * adopts it. What a customer is owed is the verdict, not the
             * button, and a provider that verifies on its own schedule and
             * reports the result honours that in full.
             */
            self::Backup => ['create', 'restore', 'delete', 'verify', 'verification_verdict', 'retention', 'file_browse', 'file_restore'],
            self::Hosting => ['create_account', 'suspend', 'unsuspend', 'terminate', 'sso', 'change_package', 'usage'],
            /*
             * Five, and `uninstall` and `ssl` are deliberately not among them.
             *
             * Both used to be declared here and required of the WordPress
             * product, and no contract in this repository modelled either — so
             * a product the readiness engine calls software-complete asked for
             * capabilities nothing could ever answer. Gap 8 found the
             * requirement was the wrong half: removal is
             * `HostingProvider::terminate`, and an account's SSL state is
             * observed by the platform's own site probe and reported through
             * `AccountUsage`. Neither is the installer's to answer, so neither
             * is asked of it — `EveryDeclaredCapabilityHasAConsumerTest`
             * insists a capability with no consumer leaves the category rather
             * than sitting here unanswered.
             */
            self::WordPressInstaller => ['install', 'version', 'staging', 'clone', 'push_to_production'],
            self::Dns => ['create_zone', 'delete_zone', 'records', 'reconcile'],
            self::ReverseDns => ['set_ptr', 'clear_ptr'],
            self::Registrar => ['search', 'availability', 'register', 'renew', 'transfer', 'nameservers', 'contacts', 'lock', 'auth_code', 'redemption', 'premium', 'held_names'],
            self::Payment => ['charge', 'refund', 'webhook', 'currencies'],
            self::Email => ['send'],
            self::Bmc => ['inventory', 'power_state', 'power_control', 'boot_override', 'firmware'],
            self::Monitoring => ['scrape', 'alerting'],
            self::Cdn => ['enable', 'disable', 'purge_all', 'purge_urls', 'cache_rules', 'development_mode', 'tls_status'],
            self::ObjectStorage => ['create_bucket', 'delete_bucket', 'list_buckets', 'quota', 'usage', 'issue_access_key', 'revoke_access_key', 'endpoint', 'versioning', 'lifecycle'],
            self::EmailHosting => ['create_mail_domain', 'delete_mail_domain', 'create_mailbox', 'delete_mailbox', 'reset_mailbox_password', 'change_quota', 'create_alias', 'delete_alias', 'create_forwarder', 'delete_forwarder', 'webmail', 'usage', 'suspend', 'unsuspend', 'terminate', 'dkim'],
            self::LoadBalancer => ['create', 'delete', 'members', 'health_checks'],
            self::Certificates => ['issue', 'renew', 'revoke'],
            self::ClusterLifecycle => ['create_cluster', 'delete_cluster', 'node_pools', 'upgrade'],
        };
    }
}
