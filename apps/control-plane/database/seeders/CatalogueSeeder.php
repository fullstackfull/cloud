<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\AnnouncesProgress;
use Illuminate\Database\Seeder;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Catalog\Infrastructure\Models\TaxRule;
use RuntimeException;

/**
 * A sellable catalogue for local development.
 *
 * This is sample data, not a price list. Every amount here is an integer in
 * the currency's minor unit — KWD has three of them, so 9.000 KWD is 9000 —
 * and the two currencies are priced independently rather than converted, which
 * is the same rule the billing engine enforces at runtime.
 *
 * It refuses to run in production for the ordinary commercial reason: what a
 * platform charges is a decision its operator makes, and a seeder that invents
 * prices is a seeder that will eventually invoice someone for them.
 */
final class CatalogueSeeder extends Seeder
{
    use AnnouncesProgress;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'CatalogueSeeder must never run in production: prices are an operator decision, not a fixture.'
            );
        }

        $this->vps();
        $this->dedicated();
        $this->sharedHosting();
        $this->taxRules();

        $this->announce(sprintf(
            'Catalogue seeded: %d products, %d plans, %d prices.',
            Product::count(),
            Plan::count(),
            PlanPrice::count(),
        ));
    }

    private function vps(): void
    {
        $product = $this->product(
            kind: ProductKind::Vps,
            slug: 'cloud-vps',
            sortOrder: 10,
            name: ['en' => 'Cloud VPS', 'ar' => 'خادم افتراضي سحابي'],
            description: [
                'en' => 'Virtual machines on redundant hypervisors, provisioned in minutes.',
                'ar' => 'أجهزة افتراضية على مضيفات متعددة، تُجهَّز خلال دقائق.',
            ],
        );

        // vCPU, memory, disk, transfer and address counts — the shape
        // VmResources::fromArray() reads, plus what the IPAM allocator needs.
        $tiers = [
            ['cx-1', ['en' => 'CX-1', 'ar' => 'CX-1'], 1, 2048, 20, 1, 3000, 12000],
            ['cx-2', ['en' => 'CX-2', 'ar' => 'CX-2'], 2, 4096, 40, 2, 5500, 22000],
            ['cx-4', ['en' => 'CX-4', 'ar' => 'CX-4'], 4, 8192, 80, 4, 10500, 42000],
            ['cx-8', ['en' => 'CX-8', 'ar' => 'CX-8'], 8, 16384, 160, 8, 20000, 80000],
        ];

        foreach ($tiers as $i => [$slug, $name, $vcpu, $memoryMib, $diskGib, $bandwidthTib, $kwdMonthly, $usdMonthly]) {
            $plan = $this->plan(
                product: $product,
                slug: $slug,
                sortOrder: ($i + 1) * 10,
                name: $name,
                resources: [
                    'vcpu' => $vcpu,
                    'memory_mib' => $memoryMib,
                    'disk_gib' => $diskGib,
                    'bandwidth_tib' => $bandwidthTib,
                    'ipv4_count' => 1,
                    'ipv6_count' => 1,
                ],
                placementConstraints: ['storage_class' => 'nvme'],
            );

            // Monthly and yearly, the latter at ten months for twelve, which is
            // the discount expressed as a price rather than as a coupon: a
            // yearly subscription is a different price row, not a modifier.
            $this->price($plan, 'KWD', BillingPeriod::Monthly, $kwdMonthly);
            $this->price($plan, 'KWD', BillingPeriod::Yearly, $kwdMonthly * 10);
            $this->price($plan, 'USD', BillingPeriod::Monthly, $usdMonthly);
            $this->price($plan, 'USD', BillingPeriod::Yearly, $usdMonthly * 10);
        }
    }

    private function dedicated(): void
    {
        $product = $this->product(
            kind: ProductKind::Dedicated,
            slug: 'dedicated-servers',
            sortOrder: 20,
            name: ['en' => 'Dedicated Servers', 'ar' => 'خوادم مخصّصة'],
            description: [
                'en' => 'Single-tenant hardware with out-of-band management.',
                'ar' => 'عتاد مخصّص لمستأجر واحد مع إدارة خارج النطاق.',
            ],
        );

        /*
         * hardware_profile is the join to the inventory: ReserveDedicatedServer
         * matches free machines on exactly this string, so a plan whose profile
         * names nothing in dedicated_servers is a plan that can be bought and
         * never fulfilled. The profiles below match InfrastructureSeeder.
         */
        $tiers = [
            ['ded-standard-1', ['en' => 'DS Standard', 'ar' => 'DS قياسي'], 'HPE ProLiant DL360 Gen10', 32, 131072, 2, 95000, 380000],
            ['ded-storage-1', ['en' => 'DS Storage', 'ar' => 'DS تخزين'], 'Dell PowerEdge R740xd', 24, 262144, 4, 145000, 580000],
        ];

        foreach ($tiers as $i => [$profile, $name, $chassis, $cores, $memoryMib, $uplinkGbps, $kwdMonthly, $usdMonthly]) {
            $plan = $this->plan(
                product: $product,
                slug: $profile,
                sortOrder: ($i + 1) * 10,
                name: $name,
                resources: [
                    'hardware_profile' => $profile,
                    'chassis' => $chassis,
                    'cpu_cores' => $cores,
                    'memory_mib' => $memoryMib,
                    'uplink_gbps' => $uplinkGbps,
                    'ipv4_count' => 1,
                    'ipv6_count' => 1,
                ],
            );

            // Dedicated hardware carries a setup fee: racking and imaging are
            // real one-off costs, and modelling them as a first month discount
            // would misreport recurring revenue.
            $this->price($plan, 'KWD', BillingPeriod::Monthly, $kwdMonthly, setupMinor: 25000);
            $this->price($plan, 'USD', BillingPeriod::Monthly, $usdMonthly, setupMinor: 10000);
        }
    }

    private function sharedHosting(): void
    {
        $product = $this->product(
            kind: ProductKind::SharedHosting,
            slug: 'shared-hosting',
            sortOrder: 30,
            name: ['en' => 'Shared Hosting', 'ar' => 'استضافة مشتركة'],
            description: [
                'en' => 'cPanel and DirectAdmin accounts on managed nodes.',
                'ar' => 'حسابات cPanel و DirectAdmin على خوادم مُدارة.',
            ],
        );

        $tiers = [
            ['starter', ['en' => 'Starter', 'ar' => 'المبتدئ'], 10240, 512000, 1, 10, 1500, 6000],
            ['business', ['en' => 'Business', 'ar' => 'الأعمال'], 51200, 2048000, 10, 50, 4000, 16000],
            ['agency', ['en' => 'Agency', 'ar' => 'الوكالات'], 204800, 8192000, 50, 200, 9000, 36000],
        ];

        foreach ($tiers as $i => [$slug, $name, $diskMib, $bandwidthMib, $addonDomains, $databases, $kwdMonthly, $usdMonthly]) {
            $plan = $this->plan(
                product: $product,
                slug: 'hosting-'.$slug,
                sortOrder: ($i + 1) * 10,
                name: $name,
                resources: [
                    'disk_quota_mib' => $diskMib,
                    'bandwidth_quota_mib' => $bandwidthMib,
                    'max_addon_domains' => $addonDomains,
                    'max_databases' => $databases,
                    'max_email_accounts' => $databases * 5,
                ],
            );

            $this->price($plan, 'KWD', BillingPeriod::Monthly, $kwdMonthly);
            $this->price($plan, 'KWD', BillingPeriod::Yearly, $kwdMonthly * 10);
            $this->price($plan, 'USD', BillingPeriod::Monthly, $usdMonthly);
            $this->price($plan, 'USD', BillingPeriod::Yearly, $usdMonthly * 10);
        }
    }

    private function taxRules(): void
    {
        // Kuwait has no VAT at the time of writing; the row exists at zero so
        // that the tax engine is exercised rather than bypassed, and so that
        // introducing a rate later is an update rather than a new code path.
        TaxRule::updateOrCreate(
            ['country' => 'KW', 'state' => null],
            [
                'name' => 'Kuwait — no VAT',
                'rate' => '0.000000',
                'is_inclusive' => false,
                'is_active' => true,
                'effective_from' => now()->subYear(),
            ],
        );

        TaxRule::updateOrCreate(
            ['country' => 'SA', 'state' => null],
            [
                'name' => 'Saudi Arabia VAT',
                'rate' => '0.150000',
                'is_inclusive' => false,
                'is_active' => true,
                'effective_from' => now()->subYear(),
            ],
        );

        TaxRule::updateOrCreate(
            ['country' => 'AE', 'state' => null],
            [
                'name' => 'United Arab Emirates VAT',
                'rate' => '0.050000',
                'is_inclusive' => false,
                'is_active' => true,
                'effective_from' => now()->subYear(),
            ],
        );
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, string>|null  $description
     */
    private function product(
        ProductKind $kind,
        string $slug,
        int $sortOrder,
        array $name,
        ?array $description = null,
    ): Product {
        return Product::updateOrCreate(
            ['slug' => $slug],
            [
                'kind' => $kind,
                'is_active' => true,
                'is_public' => true,
                'sort_order' => $sortOrder,
                'name' => $name,
                'description' => $description,
            ],
        );
    }

    /**
     * @param  array<string, string>  $name
     * @param  array<string, mixed>  $resources
     * @param  array<string, mixed>|null  $placementConstraints
     */
    private function plan(
        Product $product,
        string $slug,
        int $sortOrder,
        array $name,
        array $resources,
        ?array $placementConstraints = null,
    ): Plan {
        return Plan::updateOrCreate(
            ['slug' => $slug],
            [
                'product_id' => $product->getKey(),
                'is_active' => true,
                'is_public' => true,
                'sort_order' => $sortOrder,
                'name' => $name,
                'resources' => $resources,
                'placement_constraints' => $placementConstraints,
            ],
        );
    }

    private function price(
        Plan $plan,
        string $currency,
        BillingPeriod $period,
        int $recurringMinor,
        int $setupMinor = 0,
    ): PlanPrice {
        return PlanPrice::updateOrCreate(
            ['plan_id' => $plan->getKey(), 'currency' => $currency, 'billing_period' => $period],
            [
                'recurring_amount_minor' => $recurringMinor,
                'setup_amount_minor' => $setupMinor,
                'is_active' => true,
            ],
        );
    }
}
