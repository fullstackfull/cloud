<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\StateMachines;

use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Shared\Domain\Contracts\AbstractStateMachine;

/**
 * The service lifecycle.
 *
 * The table is the whole specification. Nothing writes services.status
 * directly, so a provisioning job that fails cannot leave a service looking
 * active, and a terminated service cannot be resurrected by a late-arriving
 * webhook.
 *
 * @extends AbstractStateMachine<ServiceStatus>
 */
final class ServiceStateMachine extends AbstractStateMachine
{
    public function subject(): string
    {
        return 'Service';
    }

    /**
     * @return array<string, list<ServiceStatus>>
     */
    public function transitions(): array
    {
        return [
            ServiceStatus::Pending->value => [
                ServiceStatus::Provisioning,
                // An order cancelled before a single job ran ends here rather
                // than lingering as pending forever.
                ServiceStatus::Terminated,
            ],

            ServiceStatus::Provisioning->value => [
                ServiceStatus::Active,
                ServiceStatus::Failed,
            ],

            ServiceStatus::Active->value => [
                ServiceStatus::Suspended,
                ServiceStatus::Terminated,
            ],

            ServiceStatus::Suspended->value => [
                ServiceStatus::Active,
                ServiceStatus::Terminated,
            ],

            ServiceStatus::Failed->value => [
                // A failed build is retried, not recreated: the same service
                // row goes round again so the customer's order, subscription
                // and reserved addresses stay attached to it.
                ServiceStatus::Provisioning,
                ServiceStatus::Terminated,
            ],

            // Terminated is terminal. Anything else would mean a service the
            // customer has stopped paying for could come back.
            ServiceStatus::Terminated->value => [],
        ];
    }
}
