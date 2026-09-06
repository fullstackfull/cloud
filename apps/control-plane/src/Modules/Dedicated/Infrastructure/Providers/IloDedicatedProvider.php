<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Providers;

use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;

/**
 * HPE iLO, which is Redfish with holes in it.
 *
 * This class exists to fill the holes and nothing else. It extends the Redfish
 * adapter and overrides only where HPE's implementation genuinely differs,
 * because every method restated here is a method that stops receiving fixes
 * made to the protocol adapter — and the protocol is the same protocol.
 *
 * What actually differs, and why each override earns its place:
 *
 *  - **the system id.** iLO enumerates the one system as "1"; the DMTF
 *    examples and several vendors use longer identifiers. Getting it wrong is
 *    a 404 on every call, so it is a default rather than a guess made per
 *    request;
 *
 *  - **the boot order.** iLO does not publish `Boot.BootOrder`. The persistent
 *    order lives in an HPE Oem block, so reading the standard field alone
 *    would report every HPE machine as having no boot devices — and the one
 *    thing bootOrder() is called for is to prove PXE is NOT first;
 *
 *  - **the firmware inventory.** Current iLO serves it at the standard
 *    UpdateService path; older generations serve it only from the manager's
 *    own subtree. The fallback is a second GET and never a mutation;
 *
 *  - **aggregate health.** Some iLO resources omit `Status.Health` entirely
 *    and report the chassis verdict as `Oem.Hpe.AggregateHealthStatus`.
 *    Without this override a healthy HPE machine and a silent one are
 *    indistinguishable, and a fleet health report goes green on machines that
 *    never answered.
 *
 * Everything else — the Once boot override, the reset action, TLS
 * verification, the scrubbing of the credential out of every message, the
 * treatment of a timeout as indeterminate — is inherited deliberately. Those
 * are the properties that must not diverge per vendor.
 */
final class IloDedicatedProvider extends RedfishDedicatedProvider
{
    public const string NAME = 'ilo';

    /** Where iLO 4 keeps its firmware inventory when the standard path is absent. */
    private const string LEGACY_FIRMWARE_PATH = '/redfish/v1/Managers/1/UpdateService/FirmwareInventory';

    public function protocol(): BmcProtocol
    {
        return BmcProtocol::Ilo;
    }

    /**
     * Read the persistent boot order from HPE's Oem block, falling back to the
     * standard fields.
     *
     * The fallback order is deliberate: the standard field is preferred when
     * iLO does publish it, so a firmware update that adds proper support is
     * picked up without a code change.
     *
     * @param  array<string, mixed>  $system
     * @return list<string>
     */
    protected function bootOrderFrom(array $system): array
    {
        $standard = parent::bootOrderFrom($system);

        if ($standard !== []) {
            return $standard;
        }

        $order = $this->hpeBootBlock($system)['PersistentBootConfigOrder'] ?? null;

        if (! is_array($order)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $entry): string => is_string($entry) ? $entry : (string) json_encode($entry),
            $order,
        ));
    }

    /**
     * The chassis verdict, taken from HPE's aggregate status when Redfish's
     * own field is missing.
     *
     * Only used as a fallback. Where iLO fills in `Status.Health` that value
     * wins, because it is the one the rest of the platform's health rules are
     * written against.
     *
     * @param  array<string, mixed>  $system
     */
    protected function overallHealthOf(array $system): ComponentHealth
    {
        $standard = parent::overallHealthOf($system);

        if ($standard !== ComponentHealth::Unknown) {
            return $standard;
        }

        $aggregate = $this->hpeOem($system)['AggregateHealthStatus'] ?? null;

        if (! is_array($aggregate)) {
            return ComponentHealth::Unknown;
        }

        $status = $aggregate['Status'] ?? null;

        // HPE nests its verdict one level deeper than Redfish does, under a
        // Status object of its own, and spells the values the same way.
        return ComponentHealth::fromRedfish(
            is_array($status) ? $this->stringAt($status, 'Health') : null,
        );
    }

    /**
     * Every firmware image, from whichever path this generation of iLO serves.
     *
     * @return list<FirmwareComponent>
     */
    public function firmwareInventory(BmcEndpoint $endpoint): array
    {
        $components = parent::firmwareInventory($endpoint);

        if ($components !== []) {
            return $components;
        }

        // The standard path answered with nothing, which on iLO 4 means the
        // inventory is somewhere else rather than that the machine has no
        // firmware. Both reads are GETs, so the retry cannot change anything.
        return $this->firmwareComponentsFrom(self::LEGACY_FIRMWARE_PATH);
    }

    /**
     * @return list<FirmwareComponent>
     */
    private function firmwareComponentsFrom(string $path): array
    {
        $components = [];

        foreach ($this->collection($path, 'firmware_inventory') as $member) {
            $id = $this->stringAt($member, 'Id') ?? $this->stringAt($member, 'Name');

            if ($id === null) {
                continue;
            }

            $components[] = new FirmwareComponent(
                id: $id,
                name: $this->stringAt($member, 'Name') ?? $id,
                version: $this->stringAt($member, 'Version'),
                updateable: ($member['Updateable'] ?? false) === true,
                manufacturer: $this->stringAt($member, 'Manufacturer'),
            );
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function hpeOem(array $resource): array
    {
        $oem = $resource['Oem'] ?? null;

        if (! is_array($oem)) {
            return [];
        }

        // "Hpe" on iLO 5 and later, "Hp" on iLO 4. The rename happened with a
        // firmware generation, not with a model, so a fleet has both.
        $vendor = $oem['Hpe'] ?? $oem['Hp'] ?? null;

        return is_array($vendor) ? $vendor : [];
    }

    /**
     * @param  array<string, mixed>  $system
     * @return array<string, mixed>
     */
    private function hpeBootBlock(array $system): array
    {
        $boot = $this->hpeOem($system)['Boot'] ?? null;

        return is_array($boot) ? $boot : [];
    }
}
