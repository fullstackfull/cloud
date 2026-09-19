<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\DTOs;

use InvalidArgumentException;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * A request for a unit of provisioning work.
 *
 * The idempotency key is the whole reason this is a value object rather than a
 * long argument list: it must be chosen by the caller, from facts that are
 * stable across every retry of the same intent — an order item id, a service
 * id and an operation — and never from a clock or a random string, which would
 * make every retry a new server.
 *
 * @immutable
 */
final readonly class ProvisioningJobRequest
{
    /**
     * @param  array<string, mixed>  $payload  Never a credential: the column redacts them, and the handler
     *                                         would receive "[redacted]" instead.
     */
    public function __construct(
        public ProvisioningJobKind $kind,
        public string $idempotencyKey,
        public string $provider,
        public ?string $serviceId = null,
        public ?string $orderId = null,
        public ?string $customerId = null,
        public array $payload = [],
        public ?int $maxAttempts = null,
        public ?int $timeoutSeconds = null,
        public ?string $correlationId = null,
        /**
         * The signed-in user who asked, where a person asked at all.
         *
         * Null is a real answer and the common one: the build that follows a
         * paid order, the suspension that follows an unpaid one and the
         * reconciler correcting drift are the platform acting. The activity
         * feed reads a null here as "Lynomia", never as whoever was nearest.
         */
        public ?string $requestedByUserId = null,
    ) {
        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('A provisioning job needs an idempotency key.');
        }

        // Truncating would be worse than rejecting: two distinct intents whose
        // keys agree in their first 128 characters would silently become one
        // job, and the second customer would never get their server.
        if (mb_strlen($this->idempotencyKey) > 128) {
            throw new InvalidArgumentException('A provisioning idempotency key may not exceed 128 characters.');
        }
    }

    /**
     * The common case: work for a service the platform already created.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function forService(
        Service $service,
        ProvisioningJobKind $kind,
        string $provider,
        array $payload = [],
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?string $requestedByUserId = null,
    ): self {
        return new self(
            kind: $kind,
            // Derived from the service and the operation, so a retried
            // dispatch of "suspend this service" is the same job rather than
            // a second one.
            idempotencyKey: $idempotencyKey ?? sprintf('service:%s:%s', $service->getKey(), $kind->value),
            provider: $provider,
            serviceId: (string) $service->getKey(),
            orderId: $service->order_id,
            customerId: $service->customer_id,
            payload: $payload,
            timeoutSeconds: $kind->defaultTimeoutSeconds(),
            correlationId: $correlationId,
            requestedByUserId: $requestedByUserId,
        );
    }
}
