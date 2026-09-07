<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Creates the service a customer bought, and asks for it to be built.
 *
 * ---------------------------------------------------------------------------
 * Why this did not exist
 * ---------------------------------------------------------------------------
 *
 * It is the missing end of the money chain. An order could be placed, invoiced,
 * paid, settled, its coupon redeemed and its subscription started — and nothing
 * in the platform created a `services` row or a provisioning job. Every caller
 * of CreateProvisioningJob was a *later* operation on a machine that already
 * existed: a power change, a reinstall. Nothing built the first one. The
 * end-to-end test named "purchase to active service" asserted a subscription
 * and stopped.
 *
 * ---------------------------------------------------------------------------
 * Idempotency
 * ---------------------------------------------------------------------------
 *
 * One service per purchased line, and the line is the key. The unique index on
 * `services.order_item_id` is what makes that true under two workers rather
 * than one: both may find no service, both may insert, and the database refuses
 * the second — at which point this returns the row the winner wrote. The
 * provisioning job carries its own idempotency key, derived from the same line,
 * so a second pass converges there too.
 *
 * ---------------------------------------------------------------------------
 * Placement
 * ---------------------------------------------------------------------------
 *
 * A VPS has to be built somewhere, and the catalogue is where that decision
 * belongs: a plan's `placement_constraints` may name `cluster_id` and
 * `ip_pool_id`. Where it does not, and exactly one active cluster or pool
 * exists, that one is used — which is the shape of a first deployment. Where
 * the choice is genuinely ambiguous, **no job is created**: the service is left
 * PENDING with the reason recorded, because inventing a placement is how a
 * customer's machine appears in the wrong country. That is an operator's
 * decision and the platform says so rather than guessing.
 */
final readonly class ProvisionOrderedService
{
    public function __construct(
        private CreateProvisioningJob $createJob,
    ) {}

    public function execute(Order $order, OrderItem $item, ?Subscription $subscription = null): ?Service
    {
        if ($item->plan_id === null) {
            // An add-on line rides along with the plan it belongs to; it is not
            // a service of its own.
            return null;
        }

        $plan = Plan::query()->with('product')->find($item->plan_id);

        if ($plan === null || $plan->product === null) {
            Log::error('A purchased line names a plan that no longer exists; nothing can be provisioned.', [
                'order_id' => (string) $order->getKey(),
                'order_item_id' => (string) $item->getKey(),
                'plan_id' => (string) $item->plan_id,
            ]);

            return null;
        }

        $service = $this->serviceFor($order, $item, $plan);

        if ($service->status !== ServiceStatus::Pending) {
            // Already being built, already built, or already gone. Nothing here
            // starts a second build for it.
            return $service;
        }

        if ($subscription !== null && $service->subscription_id === null) {
            $service->forceFill(['subscription_id' => $subscription->getKey()])->save();
        }

        $payload = $this->payloadFor($plan, $service);

        if ($payload === null) {
            // The reason is already recorded on the service. No job is created,
            // because a job with no placement fails at the provider and looks
            // like an outage rather than a decision nobody has made.
            return $service;
        }

        $job = $this->createJob->execute(new ProvisioningJobRequest(
            kind: self::kindFor($plan->product->kind),
            // The line, not the order: an order for two machines is two builds,
            // and a retry of either must converge on its own job.
            idempotencyKey: 'order-item:'.$item->getKey(),
            provider: $this->providerFor($plan, $payload),
            serviceId: (string) $service->getKey(),
            orderId: (string) $order->getKey(),
            customerId: (string) $order->customer_id,
            payload: $payload,
        ));

        $service->forceFill(['status' => ServiceStatus::Provisioning])->save();

        RunProvisioningJob::dispatch((string) $job->getKey());

        return $service;
    }

    /**
     * The service row for this line, created once.
     */
    private function serviceFor(Order $order, OrderItem $item, Plan $plan): Service
    {
        /** @var Service|null $existing */
        $existing = Service::query()->where('order_item_id', $item->getKey())->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Service => Service::query()->create([
                'customer_id' => $order->customer_id,
                'order_id' => $order->getKey(),
                'order_item_id' => $item->getKey(),
                'plan_id' => $plan->getKey(),
                'kind' => $plan->product?->kind->value,
                'status' => ServiceStatus::Pending,
                // Names are translatable JSON, so the label is built from the
                // fallback locale rather than by stringifying an array.
                'label' => $this->labelFor($plan),
                // Snapshotted from the line, not read from the plan later: a
                // catalogue edit must not resize a machine somebody is running.
                'resources' => $item->resources_snapshot ?? $plan->resources,
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another worker created it between the read and the insert. Its
            // row is the one that exists.
            /** @var Service $winner */
            $winner = Service::query()->where('order_item_id', $item->getKey())->firstOrFail();

            return $winner;
        }
    }

    /**
     * @return array<string, mixed>|null null when the platform cannot decide
     *                                   where this belongs
     */
    private function payloadFor(Plan $plan, Service $service): ?array
    {
        /** @var array<string, mixed> $resources */
        $resources = $service->resources;
        /** @var array<string, mixed> $constraints */
        $constraints = $plan->placement_constraints ?? [];

        if ($plan->product?->kind !== ProductKind::Vps) {
            // Dedicated and shared hosting resolve their own target inside
            // their handlers — a chassis is reserved from inventory, and a
            // hosting node is chosen by the hosting scheduler — so the line's
            // resources are the whole payload.
            return $resources;
        }

        $cluster = $this->soleTarget(
            $constraints['cluster_id'] ?? null,
            static fn (): ?string => self::soleId(ComputeCluster::query()->where('status', 'active')),
        );

        if ($cluster === null) {
            $this->cannotPlace($service, 'no single active compute cluster, and the plan names none');

            return null;
        }

        $pool = $this->soleTarget(
            $constraints['ip_pool_id'] ?? null,
            static fn (): ?string => self::soleId(IpPool::query()->where('is_active', true)->where('ip_version', 4)),
        );

        if ($pool === null) {
            $this->cannotPlace($service, 'no single IP pool, and the plan names none');

            return null;
        }

        return array_merge($resources, [
            'cluster_id' => $cluster,
            'ip_pool_id' => $pool,
            'storage_class' => $constraints['storage_class'] ?? 'nvme',
            'hostname' => $this->hostnameFor($service),
        ]);
    }

    /**
     * @param  callable(): ?string  $fallback
     */
    private function soleTarget(mixed $declared, callable $fallback): ?string
    {
        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return $fallback();
    }

    /**
     * The id of the only row a query returns, or null when there is not exactly
     * one.
     *
     * "Exactly one" is the whole point: with two clusters the platform has no
     * basis for choosing, and picking the first would place a customer's
     * machine by row order.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private static function soleId(mixed $query): ?string
    {
        $ids = $query->limit(2)->pluck('id')->all();

        return count($ids) === 1 ? (string) $ids[0] : null;
    }

    private function cannotPlace(Service $service, string $reason): void
    {
        Log::warning('A purchased service cannot be placed automatically; it is waiting for an operator.', [
            'service_id' => (string) $service->getKey(),
            'customer_id' => (string) $service->customer_id,
            'reason' => $reason,
        ]);

        $service->forceFill([
            'resources' => array_merge((array) $service->resources, ['placement_blocked_reason' => $reason]),
        ])->save();
    }

    private function labelFor(Plan $plan): string
    {
        $locale = (string) config('app.fallback_locale', 'en');

        return trim(($plan->product?->nameFor($locale) ?? '').' — '.$plan->nameFor($locale), ' —');
    }

    private function hostnameFor(Service $service): string
    {
        return 'vps-'.strtolower((string) $service->getKey());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerFor(Plan $plan, array $payload): string
    {
        if ($plan->product?->kind !== ProductKind::Vps) {
            return $plan->product?->kind->value ?? 'unknown';
        }

        $cluster = ComputeCluster::query()->find($payload['cluster_id'] ?? null);

        return (string) ($cluster?->driver->value ?? 'unknown');
    }

    private static function kindFor(ProductKind $kind): ProvisioningJobKind
    {
        return match ($kind) {
            ProductKind::Vps => ProvisioningJobKind::CreateVps,
            ProductKind::Dedicated => ProvisioningJobKind::ProvisionDedicated,
            ProductKind::SharedHosting => ProvisioningJobKind::CreateHostingAccount,
        };
    }
}
