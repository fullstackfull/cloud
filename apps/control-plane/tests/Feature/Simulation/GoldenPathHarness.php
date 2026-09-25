<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Support\Facades\Artisan;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoiceForOrder;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Tests\Feature\Queue\WorkerHarness;

/**
 * What a golden path needs, and nothing a golden path should decide.
 *
 * ---------------------------------------------------------------------------
 * What this is
 * ---------------------------------------------------------------------------
 *
 * The fixtures — an estate, a catalogue, a customer — and three ways of
 * reaching the platform that a golden path uses over and over: place an order,
 * issue its invoice, and let the provider report the money through the path
 * the platform treats as authoritative.
 *
 * ---------------------------------------------------------------------------
 * What this deliberately is not
 * ---------------------------------------------------------------------------
 *
 * It orchestrates nothing. There is no method here that "provisions a VPS":
 * every business step in every golden test is a call into the product's own
 * action, its own controller or its own job, and the worker that runs them is
 * the one {@see WorkerHarness} starts. A helper that drove the chain itself
 * would be a second implementation of fulfilment, and a test of it would
 * prove that the second implementation works.
 *
 * The estate is built with factories rather than the reference topology on
 * purpose: Gap 4's estate is a *model* of a production installation, its
 * machines are `reference_only`, and provisioning onto it is exactly what its
 * safety class forbids. The reference estate is what preflight and readiness
 * are measured against; a golden path needs an estate it is allowed to build
 * on, which is what a development installation has.
 */
abstract class GoldenPathHarness extends WorkerHarness
{
    /**
     * A committed estate a VPS can actually be built on.
     *
     * Every row is the one whose absence the platform correctly refuses to
     * guess around: without storage of the requested class the scheduler
     * refuses to place, without an installable template the build has no
     * image, without a network with a bridge the machine cannot be attached,
     * and without seeded addresses there is nothing to allocate. Each of those
     * refusals is a real one, and each was reported by a real worker before
     * this fixture was complete.
     *
     * @return array{cluster: ComputeCluster, node: ComputeNode, pool: IpPool, template: VmTemplate}
     */
    protected function committedVpsEstate(): array
    {
        return $this->outsideTheTransaction(function (): array {
            $cluster = ComputeCluster::factory()->create(['status' => 'active']);

            $node = ComputeNode::factory()->withCapacity(64, 262_144, 4_000)->create([
                'cluster_id' => $cluster->getKey(),
            ]);

            ComputeStorage::factory()->available(4_000)->create([
                'cluster_id' => $cluster->getKey(),
                'node_id' => $node->getKey(),
            ]);

            $template = VmTemplate::factory()->create(['cluster_id' => $cluster->getKey()]);

            $pool = IpPool::factory()->create(['is_active' => true, 'ip_version' => 4]);

            $network = Network::factory()->create(['bridge' => 'vmbr1', 'vlan_id' => 1234]);

            $subnet = Subnet::factory()->forBlock('198.51.100.0/29', gateway: '198.51.100.1')->create([
                'ip_pool_id' => $pool->getKey(),
                'network_id' => $network->getKey(),
            ]);

            app(SeedSubnetAddresses::class)->execute($subnet);

            return ['cluster' => $cluster, 'node' => $node, 'pool' => $pool, 'template' => $template];
        });
    }

    /**
     * A published plan, priced in fils, placed where the caller says.
     *
     * @param  array<string, mixed>  $placement
     */
    protected function committedPlan(
        ProductKind $kind,
        int $monthlyMinor,
        array $placement = [],
        ?array $resources = null,
    ): Plan {
        return $this->outsideTheTransaction(function () use ($kind, $monthlyMinor, $placement, $resources): Plan {
            $product = Product::factory()->create(['kind' => $kind]);

            $plan = Plan::factory()->create([
                'product_id' => $product->getKey(),
                'placement_constraints' => $placement === [] ? null : $placement,
                ...($resources === null ? [] : ['resources' => $resources]),
            ]);

            PlanPrice::factory()->create([
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $monthlyMinor,
            ]);

            return $plan->fresh(['prices', 'product']);
        });
    }

    protected function committedCustomer(): Customer
    {
        return $this->outsideTheTransaction(
            static fn (): Customer => Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']),
        );
    }

    /**
     * An order and its invoice, through the platform's own actions.
     *
     * A hosting plan is bought for a domain, as checkout requires; the name is
     * derived from the idempotency key so each golden path's account has its
     * own, and one live account per name holds across the paths a run makes.
     *
     * @return array{0: Order, 1: Invoice}
     */
    protected function orderAndInvoice(Customer $customer, Plan $plan, ?string $idempotencyKey = null): array
    {
        $plan->loadMissing('product');

        $domain = $plan->product?->kind === ProductKind::SharedHosting
            ? trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($idempotencyKey ?? 'golden-'.$customer->getKey())), '-').'.example.test'
            : null;

        return $this->outsideTheTransaction(function () use ($customer, $plan, $idempotencyKey, $domain): array {
            $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
                lines: [new CheckoutLine((string) $plan->getKey(), 1, $domain)],
                billingPeriod: BillingPeriod::Monthly,
                idempotencyKey: $idempotencyKey,
            ));

            $invoice = app(IssueInvoiceForOrder::class)->execute($order, $customer);

            return [$order, $invoice];
        });
    }

    /**
     * The money, the way the platform is willing to believe it.
     *
     * A signed webhook delivered to the real endpoint. Nothing a customer's
     * browser says can reach settlement: this is the path that can, and it is
     * the one every golden path uses, so that "paid" in a test means what
     * "paid" means in production.
     */
    protected function payThroughTheProvider(Invoice $invoice, Customer $customer, string $reference): void
    {
        $this->outsideTheTransaction(function () use ($invoice, $customer, $reference): void {
            $signed = $this->paymentProvider()->emitWebhook(
                ProviderEventKind::PaymentSucceeded,
                $reference,
                Money::ofMinor((int) $invoice->total_minor, (string) $invoice->currency),
                metadata: [
                    'customer_id' => (string) $customer->getKey(),
                    'invoice_id' => (string) $invoice->getKey(),
                ],
            );

            $server = ['CONTENT_TYPE' => 'application/json'];

            foreach ($signed->headers as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }

            $this->call(
                'POST',
                route('webhooks.receive', ['provider' => 'fake']),
                server: $server,
                content: $signed->rawPayload,
            )->assertOk();
        });
    }

    protected function paymentProvider(): FakePaymentProvider
    {
        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProviderRegistry::class)->get('fake');

        return $provider;
    }

    /**
     * The unified preflight, in simulation, as JSON.
     *
     * Run in this process rather than another: it reads the estate and writes
     * nothing, so there is nothing for a second process to prove, and its
     * findings are what the caller asserts on.
     *
     * @return array<string, mixed>
     */
    protected function simulationPreflight(string $option, string $target): array
    {
        Artisan::call('infra:preflight', [
            '--mode' => 'simulation',
            '--'.$option => $target,
            '--json' => true,
        ]);

        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

        return $report;
    }

    /**
     * The statuses of one category of preflight check, keyed by check id.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, string>
     */
    protected function preflightStatuses(array $report, string $category): array
    {
        /** @var list<array<string, mixed>> $checks */
        $checks = $report['checks'] ?? [];

        $statuses = [];

        foreach ($checks as $check) {
            if (($check['category'] ?? null) !== $category) {
                continue;
            }

            $statuses[(string) $check['id']] = (string) $check['status'];
        }

        return $statuses;
    }
}
