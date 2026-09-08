<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Events\DriftRecorded;

/**
 * Says something the first time the platform and a provider disagree badly.
 *
 * The drift table and the operator screen already existed, and until now that
 * was the whole story: a machine missing from its hypervisor, or a suspended
 * service still running, was written to a row and waited for somebody to open
 * the right page. The event announcing a first sighting had no listener at
 * all — a reasonable thing to build and an unreasonable thing to leave,
 * because the drift that matters most is the kind nobody is looking for.
 *
 * Only critical drift is logged at error. The severities exist so that an
 * orphaned machine on a node an operator also uses by hand does not page
 * anybody at three in the morning, and routing every severity through the same
 * level would undo that in one line.
 *
 * Deliberately not queued. It is one log line; it must not be lost behind a
 * queue that is itself unhealthy, and the reconciler that raises it is already
 * running on a worker.
 */
final class AlertOnCriticalDrift
{
    public function handle(DriftRecorded $event): void
    {
        if ($event->severity !== DriftSeverity::Critical) {
            return;
        }

        /*
         * Everything an operator needs to find the thing, and nothing that
         * would put a customer's identifiers into an alerting pipeline the
         * platform does not control: the service id because it is how the
         * drift screen is filtered, and the provider reference because it is
         * what the hypervisor calls the resource.
         */
        Log::error('Critical drift was seen between the platform and a provider.', [
            'drift_id' => $event->driftId,
            'provider' => $event->provider,
            'resource_type' => $event->resourceType,
            'kind' => $event->kind->value,
            'service_id' => $event->serviceId,
            'provider_reference' => $event->providerReference,
        ]);
    }
}
