<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Services;

use Lynomia\Modules\ProductReadiness\Domain\DTOs\Requirement;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;

/**
 * The requirement matrix: what each product needs, in source, reviewed like
 * code.
 *
 * Two kinds of row. A product's own requirements are the provider categories
 * its provisioning code calls, with the capabilities it calls. The shared
 * requirements are what selling ANYTHING needs — a payment provider that can
 * charge, refund, hear its webhook and say which currencies it takes, and an
 * email provider that can send — and they are appended to every product, so
 * a payment outage is a blocker on every line at once rather than a surprise
 * on the first invoice.
 *
 * The capabilities named here are the ones the product's code invokes, and
 * every capability a category declares is invoked by some product: the gate
 * runs in both directions. A product is not held back by a capability it
 * never uses (VPS does not need `gpu_passthrough`; GPU compute does), and a
 * category does not ask a question nobody's code needs answered.
 *
 * The scope addendum audited the rows against the code and found several
 * calls no row named — `resize` from a plan change, `console` from the
 * console gateway, `sso` and `usage` from the hosting screen, `firmware` and
 * `inventory` from discovery, `refund` from the refund path, `premium` and
 * `held_names` from domain pricing and reconciliation. They are named now.
 */
final readonly class ProductRequirements
{
    /**
     * @return list<Requirement>
     */
    public function shared(): array
    {
        return [
            new Requirement(ProviderCategory::Payment, ['charge', 'refund', 'webhook', 'currencies'], shared: true),
            new Requirement(ProviderCategory::Email, ['send'], shared: true),
        ];
    }

    /**
     * @return list<Requirement>
     */
    public function own(Product $product): array
    {
        return match ($product) {
            Product::Vps => [
                new Requirement(ProviderCategory::Compute, ['create', 'start', 'stop', 'reboot', 'resize', 'reinstall', 'suspend', 'unsuspend', 'console', 'destroy', 'templates', 'task_polling']),
                new Requirement(ProviderCategory::ReverseDns, ['set_ptr', 'clear_ptr']),
            ],
            Product::Dedicated => [
                new Requirement(ProviderCategory::Bmc, ['inventory', 'power_state', 'power_control', 'boot_override', 'firmware']),
                new Requirement(ProviderCategory::ReverseDns, ['set_ptr', 'clear_ptr']),
            ],
            Product::SharedHosting => [
                new Requirement(ProviderCategory::Hosting, ['create_account', 'suspend', 'unsuspend', 'terminate', 'sso', 'change_package', 'usage']),
            ],
            Product::WordPress => [
                // Staging, cloning and push-to-production are optional on the
                // installer: the product works without them and the screen
                // offers them only where the provider reports them. They are
                // consumed by the WordPress environment flow, and a provider
                // that lacks them is not a blocker on selling WordPress.
                new Requirement(ProviderCategory::WordPressInstaller, ['install', 'uninstall', 'version', 'ssl'], optional: ['staging', 'clone', 'push_to_production']),
            ],
            Product::Domains => [
                // Redemption is optional the same way: a registrar without it
                // sells names; the redemption screen says "unavailable" with
                // the reason instead of pretending.
                new Requirement(ProviderCategory::Registrar, ['search', 'availability', 'register', 'renew', 'transfer', 'nameservers', 'contacts', 'lock', 'auth_code', 'premium', 'held_names'], optional: ['redemption']),
            ],
            Product::Dns => [
                new Requirement(ProviderCategory::Dns, ['create_zone', 'delete_zone', 'records', 'reconcile']),
            ],
            Product::Backups => [
                new Requirement(ProviderCategory::Backup, ['create', 'restore', 'delete', 'verify', 'retention'], optional: ['file_browse', 'file_restore']),
            ],

            Product::Cdn => [
                new Requirement(ProviderCategory::Cdn, ['enable', 'disable', 'purge_all', 'purge_urls', 'cache_rules', 'development_mode', 'tls_status']),
                new Requirement(ProviderCategory::Dns, ['records']),
            ],
            Product::ObjectStorage => [
                new Requirement(ProviderCategory::ObjectStorage, ['create_bucket', 'delete_bucket', 'list_buckets', 'quota', 'usage', 'issue_access_key', 'revoke_access_key', 'endpoint', 'versioning', 'lifecycle']),
            ],
            Product::GpuCompute => [
                new Requirement(ProviderCategory::Compute, ['create', 'start', 'stop', 'reboot', 'reinstall', 'suspend', 'unsuspend', 'console', 'destroy', 'templates', 'task_polling', 'gpu_passthrough']),
                new Requirement(ProviderCategory::ReverseDns, ['set_ptr', 'clear_ptr']),
            ],
            Product::EmailHosting => [
                new Requirement(ProviderCategory::EmailHosting, ['create_mail_domain', 'delete_mail_domain', 'create_mailbox', 'delete_mailbox', 'reset_mailbox_password', 'change_quota', 'create_alias', 'delete_alias', 'create_forwarder', 'delete_forwarder', 'webmail', 'usage', 'suspend', 'unsuspend', 'terminate', 'dkim']),
                // MX, SPF, DKIM and DMARC are records this platform publishes;
                // the DNS requirement is the ability to publish them.
                new Requirement(ProviderCategory::Dns, ['records']),
            ],
            Product::ManagedKubernetes => [
                new Requirement(ProviderCategory::ClusterLifecycle, ['create_cluster', 'delete_cluster', 'node_pools', 'upgrade']),
                new Requirement(ProviderCategory::LoadBalancer, ['create', 'delete', 'members', 'health_checks']),
                new Requirement(ProviderCategory::Certificates, ['issue', 'renew', 'revoke']),
                new Requirement(ProviderCategory::Monitoring, ['scrape', 'alerting']),
            ],
        };
    }

    /**
     * Own requirements first, then the shared ones, so the blocker an operator
     * reads first is the one specific to the product they asked about.
     *
     * @return list<Requirement>
     */
    public function for(Product $product): array
    {
        return [...$this->own($product), ...$this->shared()];
    }
}
