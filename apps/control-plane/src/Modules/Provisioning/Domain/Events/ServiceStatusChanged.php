<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Events;

use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;

/**
 * A service moved from one status to another, and the move has committed.
 *
 * Raised by TransitionService — the one place a service's status changes — and
 * only for a real move: a transition that converges on the status the row
 * already has announces nothing. Dispatched after the outermost transaction
 * commits, so a listener never acts on a move that is about to roll back.
 *
 * The order a service was bought on is carried because that is what the
 * listener needs to find, and it is the reason this event exists (F-19): an
 * order used to be paid for and then never told anything again, while
 * fulfilment, suspension and the end of the service all happened on this row.
 * The order's module decides what a service's move means for the purchase;
 * this module only says that it happened.
 *
 * @immutable
 */
final readonly class ServiceStatusChanged
{
    public function __construct(
        public string $serviceId,
        public ?string $orderId,
        public ServiceStatus $from,
        public ServiceStatus $to,
    ) {}
}
