<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Application\DTOs\HardwareInventorySyncResult;
use Lynomia\Modules\Dedicated\Domain\DTOs\ComponentReading;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Refreshes what the platform believes about one machine's hardware.
 *
 * **Read-only at the controller, without exception.** It calls exactly three
 * methods on the adapter — hardwareHealth(), powerState() by way of the health
 * read, and firmwareInventory() — every one of which is a GET. It never powers,
 * resets, sets a boot device or writes a single controller setting.
 *
 * That is not fastidiousness. This runs on a schedule across the whole fleet,
 * so a mutation reaching this path would be a mutation applied to every machine
 * the platform owns, including every customer's production server, in one pass.
 * It is why discovery and provisioning are different objects rather than one
 * "reconcile" that could grow a repair step: there is no branch here for
 * anything to be added to.
 *
 * Two write rules keep it from doing damage with local writes alone:
 *
 *  - **`status` is never written.** That column is intent — an operator's
 *    decision to take a machine out of service, an order's claim on it — and
 *    the controller knows nothing about either. A sync that inferred status
 *    would return a machine to stock because it answered a ping, while a
 *    customer's data was still on its disks;
 *
 *  - **a component that stops being reported is flagged, never deleted.** A
 *    drive that vanished from the inventory has been pulled or has died, and
 *    deleting the row would erase the serial number of the disk a customer's
 *    data was on at exactly the moment that serial number became interesting.
 */
final readonly class SyncHardwareInventory
{
    public function __construct(
        private DedicatedProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @throws BmcNotConfiguredException
     * @throws DedicatedProviderException
     */
    public function execute(DedicatedServer $server): HardwareInventorySyncResult
    {
        $endpoint = $server->preferredBmcEndpoint();

        if ($endpoint === null) {
            throw BmcNotConfiguredException::noEndpoint((string) $server->getKey());
        }

        $provider = $this->providers->for($endpoint);

        try {
            $health = $provider->hardwareHealth($endpoint);
            $firmware = $provider->firmwareInventory($endpoint);
        } catch (DedicatedProviderException $e) {
            /*
             * Recorded on the endpoint and re-thrown. A controller whose sync
             * keeps failing is something an operator has to see on the row
             * without reading logs — and the message is written through the
             * redactor because a provider's text can quote the request that
             * carried the credential.
             *
             * Written before this method opens its own transaction, so a
             * failure part-way through the component upsert cannot roll the
             * note back. A caller that wraps this action in a transaction of
             * its own and then rolls back WILL lose it; nothing in this module
             * does that, and a separate connection to guarantee otherwise
             * would be a heavier promise than a diagnostic note is worth.
             */
            $endpoint->forceFill([
                'last_error' => $this->redactor->redactString($e->getMessage()),
            ])->save();

            throw $e;
        }

        return DB::transaction(function () use ($server, $endpoint, $health, $firmware): HardwareInventorySyncResult {
            $counts = ['created' => 0, 'updated' => 0];
            $seen = [];

            foreach ($health->components as $reading) {
                $component = $this->upsertComponent($server, $reading, $counts);
                $seen[] = (string) $component->getKey();
            }

            $missing = $this->flagMissingComponents($server, $seen);

            $endpoint->forceFill([
                // The BMC's own firmware version, which is the one an advisory
                // about the controller names.
                'firmware_version' => $this->bmcFirmwareVersion($firmware) ?? $endpoint->firmware_version,
                'last_contacted_at' => now(),
                'last_error' => null,
            ])->save();

            $server->forceFill([
                // Observed fact. Note what is absent: status.
                'power_state' => $health->powerState,
                'last_seen_at' => now(),
                // Identity is believed from the machine only where it has
                // something to say; a controller that returns nothing must not
                // erase a serial an operator typed in from the chassis label.
                'manufacturer' => $health->manufacturer ?? $server->manufacturer,
                'model' => $health->model ?? $server->model,
            ])->save();

            return new HardwareInventorySyncResult(
                serverId: (string) $server->getKey(),
                health: $health->effectiveHealth(),
                powerState: $health->powerState,
                componentsReported: count($health->components),
                componentsCreated: $counts['created'],
                componentsUpdated: $counts['updated'],
                componentsMissing: $missing,
                firmwareReported: count($firmware),
            );
        });
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function upsertComponent(DedicatedServer $server, ComponentReading $reading, array &$counts): ServerComponent
    {
        /*
         * Matched on serial where there is one, and on the controller's own
         * label where there is not.
         *
         * Serial first because a drive moved from bay 1 to bay 3 is the same
         * drive and must not become a second row plus a "missing" one. The
         * label is the fallback for parts that have no serial to give — a
         * memory summary, a fan — and is stable across polls for exactly that
         * reason.
         */
        $query = ServerComponent::query()
            ->where('dedicated_server_id', $server->getKey())
            ->where('kind', $reading->kind->value);

        $component = $reading->serial !== null
            ? (clone $query)->where('serial', $reading->serial)->first()
            : (clone $query)->where('model', $reading->model)->where('serial', null)->first();

        $attributes = [
            'model' => $reading->model,
            'serial' => $reading->serial,
            'quantity' => $reading->quantity,
            'attributes' => [...$reading->attributes, 'name' => $reading->name],
            'health' => $reading->health,
            'health_checked_at' => now(),
        ];

        if ($component === null) {
            $counts['created']++;

            return ServerComponent::query()->create([
                'dedicated_server_id' => $server->getKey(),
                'kind' => $reading->kind,
                ...$attributes,
            ]);
        }

        $counts['updated']++;

        $component->forceFill($attributes)->save();

        return $component;
    }

    /**
     * Mark as unknown every part the controller did not mention this pass.
     *
     * Unknown, not deleted and not critical. Deleting loses the serial number;
     * marking critical raises an incident for a part that may simply not be
     * reported by this firmware version. Unknown says exactly what is true —
     * the platform has stopped hearing about it — and is the state a health
     * report can act on without crying wolf.
     *
     * @param  list<string>  $seenIds
     */
    private function flagMissingComponents(DedicatedServer $server, array $seenIds): int
    {
        $query = ServerComponent::query()
            ->where('dedicated_server_id', $server->getKey())
            ->where('health', '!=', ComponentHealth::Unknown->value);

        if ($seenIds !== []) {
            $query->whereNotIn('id', $seenIds);
        }

        return $query->update([
            'health' => ComponentHealth::Unknown->value,
            'health_checked_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<FirmwareComponent>  $firmware
     */
    private function bmcFirmwareVersion(array $firmware): ?string
    {
        foreach ($firmware as $component) {
            $id = strtolower($component->id);

            if ($id === 'bmc' || str_contains($id, 'ilo') || str_contains($id, 'idrac') || str_contains($id, 'bmc')) {
                return $component->version;
            }
        }

        return null;
    }
}
