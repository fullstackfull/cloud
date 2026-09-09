<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Enums;

/**
 * The things Lynomia sells or is preparing to sell, as the readiness engine
 * sees them.
 *
 * Not the catalogue's product kinds: the catalogue lists what has a price,
 * and this lists what has to WORK — which includes lines sold through another
 * product's price (a WordPress site is a hosting plan with an installer),
 * lines nobody has priced yet, and lines the platform is only preparing for.
 * A product missing from here cannot be declared ready to sell, which is the
 * safe default.
 *
 * The second half of the list is the scope addendum's: products whose
 * provider contract and requirement rows exist so that, when the real
 * server, licence or provider arrives, the missing piece is connected and
 * validated without redesigning the product. Each declares how much of its
 * software exists ({@see softwareState()}), and the engine refuses to let a
 * prepared product outrun that.
 */
enum Product: string
{
    case Vps = 'vps';
    case Dedicated = 'dedicated';
    case SharedHosting = 'shared_hosting';
    case WordPress = 'wordpress';
    case Domains = 'domains';
    case Dns = 'dns';
    case Backups = 'backups';

    // Prepared by the scope addendum. Nothing below is sellable on this build.
    case Cdn = 'cdn';
    case ObjectStorage = 'object_storage';
    case GpuCompute = 'gpu_compute';
    case EmailHosting = 'email_hosting';
    case ManagedKubernetes = 'managed_kubernetes';

    /**
     * What has to be sellable before this can be.
     *
     * A dependency caps the dependent: a WordPress site is installed into a
     * hosting account, so the WordPress line can never be readier than
     * shared hosting is. The engine propagates the dependency's blocker
     * rather than inventing one of its own.
     *
     * The prepared products lean on the products that would carry them: a
     * CDN fronts a name the platform must be able to point, a mailbox needs
     * MX and DKIM records published, a GPU machine is a VPS with a device
     * passed through, and a cluster is machines, names, archives and a bucket
     * for its state.
     *
     * @return list<self>
     */
    public function dependsOn(): array
    {
        return match ($this) {
            self::WordPress => [self::SharedHosting],
            self::Backups => [self::Vps],
            self::Cdn => [self::Dns],
            self::GpuCompute => [self::Vps],
            self::EmailHosting => [self::Dns],
            self::ManagedKubernetes => [self::Vps, self::Dns, self::Backups, self::ObjectStorage],
            self::Vps, self::Dedicated, self::SharedHosting, self::Domains, self::Dns, self::ObjectStorage => [],
        };
    }

    /**
     * How much of this product's software exists on this build.
     *
     * Declared here and checked by a test that walks every product: a
     * `complete` product must have an order action behind it, and a
     * `readiness_only` product must have no customer route at all.
     */
    public function softwareState(): ProductSoftwareState
    {
        return match ($this) {
            self::Vps, self::Dedicated, self::SharedHosting, self::WordPress, self::Domains, self::Dns, self::Backups => ProductSoftwareState::Complete,
            self::Cdn, self::ObjectStorage, self::GpuCompute, self::EmailHosting => ProductSoftwareState::Prepared,
            self::ManagedKubernetes => ProductSoftwareState::ReadinessOnly,
        };
    }

    /**
     * Physical capacity this product cannot exist without, beyond what a
     * provider answers about itself.
     *
     * A compute provider can report `gpu_passthrough` supported and still
     * have no GPU in any machine. The engine asks the estate, not the
     * provider, and reads an empty estate as blocked on hardware.
     */
    public function hardwareRequirement(): ?HardwareRequirement
    {
        return match ($this) {
            self::GpuCompute => HardwareRequirement::GpuDevice,
            default => null,
        };
    }

    /**
     * Every product, dependencies before dependents, so one pass assesses
     * each product after everything it leans on.
     *
     * @return list<self>
     */
    public static function inDependencyOrder(): array
    {
        $ordered = [];
        $visit = function (self $product) use (&$ordered, &$visit): void {
            if (in_array($product, $ordered, strict: true)) {
                return;
            }

            foreach ($product->dependsOn() as $dependency) {
                $visit($dependency);
            }

            $ordered[] = $product;
        };

        foreach (self::cases() as $product) {
            $visit($product);
        }

        return $ordered;
    }
}
