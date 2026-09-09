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
 * charge and hear its webhook, and an email provider that can send — and they
 * are appended to every product, so a payment outage is a blocker on every
 * line at once rather than a surprise on the first invoice.
 *
 * The capabilities named here are the ones the product's code invokes. They
 * are deliberately narrower than the category's whole question set: a product
 * is not held back by a capability it never uses.
 */
final readonly class ProductRequirements
{
    /**
     * @return list<Requirement>
     */
    public function shared(): array
    {
        return [
            new Requirement(ProviderCategory::Payment, ['charge', 'webhook'], shared: true),
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
                new Requirement(ProviderCategory::Compute, ['create', 'start', 'stop', 'reboot', 'reinstall', 'suspend', 'unsuspend', 'destroy', 'task_polling']),
                new Requirement(ProviderCategory::ReverseDns, ['set_ptr', 'clear_ptr']),
            ],
            Product::Dedicated => [
                new Requirement(ProviderCategory::Bmc, ['power_state', 'power_control', 'boot_override']),
                new Requirement(ProviderCategory::ReverseDns, ['set_ptr', 'clear_ptr']),
            ],
            Product::SharedHosting => [
                new Requirement(ProviderCategory::Hosting, ['create_account', 'suspend', 'unsuspend', 'terminate', 'change_package']),
            ],
            Product::WordPress => [
                new Requirement(ProviderCategory::WordPressInstaller, ['install', 'uninstall', 'ssl']),
            ],
            Product::Domains => [
                new Requirement(ProviderCategory::Registrar, ['search', 'availability', 'register', 'renew', 'transfer', 'nameservers', 'contacts', 'lock', 'auth_code']),
            ],
            Product::Dns => [
                new Requirement(ProviderCategory::Dns, ['create_zone', 'delete_zone', 'records', 'reconcile']),
            ],
            Product::Backups => [
                new Requirement(ProviderCategory::Backup, ['create', 'restore', 'delete', 'retention']),
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
