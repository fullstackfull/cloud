<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The path the whole platform exists to serve, across every seam it really has.
 *
 * ---------------------------------------------------------------------------
 * What makes this different from the tests that were already here
 * ---------------------------------------------------------------------------
 *
 * `OrderToProvisionedServiceTest` proves a paid order produces a service and
 * *queues* a build — with `Queue::fake()`, which is the right tool for the
 * question it asks. `ARealWorkerConsumesTheQueue` proves a worker builds a
 * machine from a job somebody committed by hand. Neither of them joins the
 * two, and the join is where the interesting failures live: an event that is
 * queued inside a transaction, a listener that reads a model somebody
 * serialised, a service created by one process and built by another.
 *
 * So this is one chain and three processes:
 *
 *   this process   an order, an invoice, and a signed webhook from the gateway
 *   worker one     the settlement listener: order paid, subscription started,
 *                  service created, build requested
 *   worker two     the build: a machine at the hypervisor, an address, active
 *
 * Nothing between them is faked, and nothing is called directly that
 * production would reach through a queue.
 */
#[Group('golden-path')]
final class TheVpsGoldenPathTest extends GoldenPathHarness
{
    /** The queue the settlement listener pins itself to. */
    private const string PAYMENTS_QUEUE = 'payments';

    #[Test]
    public function the_preconditions_this_path_needs_are_the_ones_preflight_checks(): void
    {
        /*
         * §76, and the reason the gate is worth having: the estate below is
         * what a build needs, and preflight is what says so. If a golden path
         * could run on an estate preflight calls unfit, then one of the two is
         * lying and it matters which.
         */
        $this->committedVpsEstate();

        $report = $this->simulationPreflight('product', 'vps');

        $mapping = $this->preflightStatuses($report, 'mapping');

        /*
         * Five checks, not six: `mapping.network` is emitted only when there
         * is no address pool at all, which is the report's own way of naming
         * an absence rather than reporting the presence of something. An
         * estate with a pool has nothing for it to say, and a test that
         * expected it would be asserting the shape of a failure it had not
         * caused.
         */
        $this->assertSame([
            'mapping.cluster' => 'pass',
            'mapping.nodes' => 'pass',
            'mapping.storage' => 'pass',
            'mapping.capacity' => 'pass',
            'mapping.template' => 'pass',
        ], $mapping);

        $this->assertSame('SIMULATION', $report['mode_label']);

        /*
         * And the other half, which is just as important: the product is still
         * not ready to sell. Simulation proves the software can carry the work
         * out; it says nothing about a provider anybody has contracted with,
         * and the readiness engine goes on saying so.
         */
        $readiness = $this->preflightStatuses($report, 'readiness');

        $this->assertNotSame([], $readiness);
        $this->assertNotContains('pass', $readiness);
    }

    #[Test]
    public function a_customer_pays_and_two_workers_deliver_a_machine_with_an_address(): void
    {
        $estate = $this->committedVpsEstate();

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
        ]);

        $customer = $this->committedCustomer();

        // ---- the order and its invoice ------------------------------------
        [$order, $invoice] = $this->orderAndInvoice($customer, $plan, 'golden-vps-1');

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(9_000, $invoice->total_minor);
        $this->assertSame('KWD', $invoice->currency);

        // ---- the money, from the provider rather than from the browser ----
        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_vps_1');

        /*
         * Nothing has settled yet, and that is the point of these two
         * assertions rather than an oversight. The endpoint records the
         * provider's event and queues the settlement; a test that found the
         * invoice already paid here would be a test whose listeners ran
         * inline, which is exactly the arrangement this file exists to stop
         * relying on.
         */
        $this->assertSame(InvoiceStatus::Open, $invoice->fresh()?->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()?->status);
        $this->assertSame(1, $this->queued(self::PAYMENTS_QUEUE));

        // ---- worker one: settlement, then fulfilment ----------------------
        /*
         * One worker run, two hops. Settlement and fulfilment are separate
         * listeners on the same queue, and the second is pushed while the
         * first is running — which `--stop-when-empty` drains, exactly as a
         * long-running worker would.
         */
        $this->work(self::PAYMENTS_QUEUE);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        $this->assertSame(0, $invoice->fresh()?->amount_due_minor);

        /*
         * Paid, and the build asked for: the order follows what it bought
         * (F-19). It used to stop at `paid` here and never move again, so a
         * machine delivered and a machine nobody could build read the same.
         */
        $this->assertNotNull($order->fresh()?->paid_at);
        $this->assertSame(OrderStatus::QueuedForProvisioning, $order->fresh()?->status);

        $subscription = Subscription::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame(9_000, $subscription->recurring_amount_minor);

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();
        $this->assertSame(ServiceStatus::Provisioning, $service->status);

        $job = ProvisioningJob::query()->where('service_id', $service->getKey())->sole();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame('order-item:'.$order->items()->sole()->getKey(), $job->idempotency_key);

        // ---- worker two: the build ----------------------------------------
        $this->work('provisioning');

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);

        // Delivered, and the order says so — in the worker that delivered it.
        $this->assertSame(OrderStatus::Active, $order->fresh()?->status);
        $this->assertNotNull($order->fresh()?->completed_at);

        // ---- the machine, the address, and the hypervisor -----------------
        $machine = VirtualMachine::query()->where('service_id', $service->getKey())->sole();

        $this->assertNotNull($machine->provider_id);
        $this->assertSame((string) $estate['cluster']->getKey(), $machine->cluster_id);

        $assignment = IpAssignment::query()
            ->where('customer_id', $customer->getKey())
            ->whereNull('released_at')
            ->sole();

        /*
         * Bound to the machine rather than merely to the customer. An address
         * a customer holds and nothing points at is the shape a leak takes:
         * the pool is short of an address and nobody can say what has it.
         */
        $this->assertSame((string) $machine->getKey(), (string) $assignment->assignable_id);
        $this->assertSame((string) $service->getKey(), (string) $assignment->service_id);

        /*
         * And the hypervisor itself, read in this process. The machine was
         * created by a worker; this is the assertion that the platform's record
         * and the provider's agree — the one that was impossible for five
         * families before this gap.
         */
        $remote = app(ComputeProviderFactory::class)
            ->for($estate['cluster'])
            ->getVm($estate['node']->provider_name, (string) $machine->provider_id);

        $this->assertNotNull($remote);
        $this->assertSame($machine->hostname, $remote->name);

        /*
         * And what it was built from. A hostname proves the right machine
         * exists; the installed image proves it is a machine somebody can log
         * in to. Until Gap 8 this value was null for every purchased machine,
         * and the golden path was green anyway — which is why it is asserted
         * on the main path and not only in the matrix.
         */
        $this->assertSame(
            $estate['template']->provider_reference,
            $remote->raw['installed_template'] ?? null,
            'the machine the customer paid for was built with no operating system on it',
        );
    }

    #[Test]
    public function a_redelivered_webhook_produces_one_payment_one_service_and_one_machine(): void
    {
        $estate = $this->committedVpsEstate();

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
        ]);

        $customer = $this->committedCustomer();

        [, $invoice] = $this->orderAndInvoice($customer, $plan, 'golden-vps-dup');

        /*
         * The same event id twice, which is what a gateway does when it does
         * not hear an answer it likes. Everything downstream has to converge:
         * one transaction, one subscription, one service, one machine, one
         * address.
         */
        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_vps_dup');
        $this->payThroughTheProvider($invoice, $customer, 'pi_golden_vps_dup');

        $this->work(self::PAYMENTS_QUEUE);
        $this->work('provisioning');

        $this->assertSame(1, Transaction::query()->where('provider_reference', 'pi_golden_vps_dup')->count());
        $this->assertSame(1, Subscription::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(1, Service::query()->where('customer_id', $customer->getKey())->count());

        /*
         * §11 and §86: one financial effect, stated as money rather than as a
         * row count. A second copy of a webhook can go wrong in three ways and
         * only the first of them is a second transaction — the platform can
         * also apply the same fils twice against the document, or decide the
         * second copy is an overpayment and hand the customer stored value it
         * was never given. So the invoice's own arithmetic is asserted exactly,
         * and the wallet is asserted to have received nothing at all.
         */
        $settled = $invoice->fresh();

        $this->assertNotNull($settled);
        $this->assertSame(InvoiceStatus::Paid, $settled->status);
        $this->assertSame(
            (int) $settled->total_minor,
            (int) $settled->amount_paid_minor,
            'the redelivered webhook changed what this invoice has been paid',
        );
        $this->assertSame(0, (int) $settled->amount_refunded_minor);
        $this->assertSame(
            0,
            (int) Transaction::query()
                ->where('invoice_id', $settled->getKey())
                ->where('status', TransactionStatus::Succeeded->value)
                ->sum('amount_minor') - (int) $settled->total_minor,
            'the captured money attached to this invoice is not the invoice total',
        );
        $this->assertSame(
            0,
            WalletTransaction::query()->where('invoice_id', $settled->getKey())->count(),
            'a duplicate webhook turned into stored value the customer never paid for',
        );

        $service = Service::query()->where('customer_id', $customer->getKey())->sole();

        $this->assertSame(1, ProvisioningJob::query()->where('service_id', $service->getKey())->count());
        $this->assertSame(1, VirtualMachine::query()->where('service_id', $service->getKey())->count());
        $this->assertSame(
            1,
            IpAssignment::query()->where('customer_id', $customer->getKey())->whereNull('released_at')->count(),
        );

        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
    }

    #[Test]
    public function a_declined_payment_leaves_the_order_unpaid_and_builds_nothing(): void
    {
        $estate = $this->committedVpsEstate();

        $plan = $this->committedPlan(ProductKind::Vps, monthlyMinor: 9_000, placement: [
            'cluster_id' => (string) $estate['cluster']->getKey(),
            'ip_pool_id' => (string) $estate['pool']->getKey(),
        ]);

        $customer = $this->committedCustomer();

        [$order, $invoice] = $this->orderAndInvoice($customer, $plan, 'golden-vps-declined');

        $this->outsideTheTransaction(function () use ($invoice, $customer): void {
            $signed = $this->paymentProvider()->emitWebhook(
                ProviderEventKind::PaymentFailed,
                'pi_golden_vps_declined',
                Money::ofMinor(
                    (int) $invoice->total_minor,
                    (string) $invoice->currency,
                ),
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

        /*
         * The failure is recorded through the same queue a success goes
         * through — the platform does not have a fast path for bad news — so
         * the worker runs, and what it must not do is the point.
         */
        $this->work(self::PAYMENTS_QUEUE);

        $this->assertSame(0, $this->queued('provisioning'));

        /*
         * §46 and §47: the root cause is the money, and nothing downstream is
         * allowed to have an opinion about it. No service, no job, no machine
         * and no address — and the assertions say "zero" rather than "not
         * active", because a service that exists and is not active is a
         * different and worse thing.
         */
        $this->assertNotSame(InvoiceStatus::Paid, $invoice->fresh()?->status);
        // Unpaid, and saying why (F-19): the decline is the order's as well.
        $this->assertSame(OrderStatus::PaymentFailed, $order->fresh()?->status);
        $this->assertNull($order->fresh()?->paid_at);

        $this->assertSame(0, Service::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(0, ProvisioningJob::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertSame(0, IpAssignment::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(0, Subscription::query()->where('customer_id', $customer->getKey())->count());
    }
}
