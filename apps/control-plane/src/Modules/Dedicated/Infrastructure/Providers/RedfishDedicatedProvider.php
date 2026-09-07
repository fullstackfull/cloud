<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\ComponentReading;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * The DMTF Redfish API, spoken properly.
 *
 * Redfish is preferred over every vendor protocol because it is specified: it
 * has status codes, a documented resource tree and real error payloads, so a
 * refusal can be told apart from a timeout. That single distinction is what
 * the whole provisioning engine turns on, and it is the thing IPMI cannot
 * give.
 *
 * Five decisions define this adapter:
 *
 *  - **TLS verification is on**, unless one endpoint row turns it off for
 *    itself. The usual shortcut — a global "verify: false" because the lab
 *    BMCs have self-signed certificates — puts every production controller's
 *    Basic credential in the hands of whoever answers the TCP connection, and
 *    that credential is power control and virtual media on a physical host;
 *
 *  - **the boot override is `Once`, never `Continuous`.** A machine left with
 *    PXE first in its boot order reinstalls itself the next time it reboots
 *    for any reason — which is a customer's entire server erased by a power
 *    cut. `Once` is written as a constant and asserted in the test suite
 *    precisely because "Continuous" is the value that makes a flaky install
 *    appear to work;
 *
 *  - **no exception from the HTTP client escapes.** A client exception
 *    stringifies the request that caused it, and every request here carries an
 *    Authorization header holding the BMC password in base64. Failures are
 *    translated here, with this connection's own secrets removed by value
 *    before the shared redactor's generic patterns get a look in — the
 *    redactor cannot recognise a secret whose value only this object knows;
 *
 *  - **a timeout is indeterminate, not failed.** A reset request that stopped
 *    being waited for may well have reset the machine. Reported as an ordinary
 *    failure it would be indistinguishable from a 400, and a caller retrying
 *    on that would power cycle a customer's server twice;
 *
 *  - **the read paths only GET.** Discovery runs on a schedule against the
 *    whole fleet, so a mutation on a read path would be a mutation applied to
 *    every machine the platform owns.
 *
 * The class is deliberately not final and its seams are protected: HPE's iLO
 * is Redfish with gaps, and {@see IloDedicatedProvider} exists to fill exactly
 * those gaps rather than to restate the protocol.
 */
class RedfishDedicatedProvider implements DedicatedProvider
{
    public const string NAME = 'redfish';

    /**
     * The only boot-override lifetime this platform will ever write.
     *
     * `Continuous` is a persistent boot-order change by another name. It is
     * not offered as a parameter, because a parameter is something a caller
     * can get wrong.
     */
    protected const string BOOT_OVERRIDE_ONCE = 'Once';

    protected const string BOOT_TARGET_PXE = 'Pxe';

    /** How many members of a collection one read will follow. */
    protected const int MAX_COLLECTION_MEMBERS = 64;

    public function __construct(
        protected readonly BmcConnection $connection,
        protected readonly SecretRedactor $redactor,
    ) {}

    public function protocol(): BmcProtocol
    {
        return BmcProtocol::Redfish;
    }

    public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth
    {
        $this->assertEndpointMatches($endpoint);

        $system = $this->getResource($this->systemPath(), 'hardware_health');

        $components = [
            ...$this->processorReadings($system),
            ...$this->memoryReadings($system),
            ...$this->networkReadings(),
            ...$this->storageReadings(),
        ];

        return new HardwareHealth(
            overall: $this->overallHealthOf($system),
            powerState: PowerState::fromRedfish($this->stringAt($system, 'PowerState')),
            manufacturer: $this->stringAt($system, 'Manufacturer'),
            model: $this->stringAt($system, 'Model'),
            serialNumber: $this->stringAt($system, 'SerialNumber'),
            biosVersion: $this->stringAt($system, 'BiosVersion'),
            components: $components,
            raw: $this->redactor->redact($system),
        );
    }

    public function powerState(BmcEndpoint $endpoint): PowerState
    {
        $this->assertEndpointMatches($endpoint);

        return PowerState::fromRedfish(
            $this->stringAt($this->getResource($this->systemPath(), 'power_state'), 'PowerState'),
        );
    }

    public function powerOn(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->sendReset($endpoint, 'On', 'power_on', PowerState::On);
    }

    public function powerOff(BmcEndpoint $endpoint): BmcOperation
    {
        // ForceOff, not GracefulShutdown: this method is the documented "cut
        // the power" operation and must not quietly become a polite request
        // that a hung machine ignores. The caller that wanted politeness has
        // gracefulShutdown() and chose not to use it.
        return $this->sendReset($endpoint, 'ForceOff', 'power_off', PowerState::Off);
    }

    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->sendReset($endpoint, 'GracefulShutdown', 'graceful_shutdown', PowerState::Off);
    }

    public function reset(BmcEndpoint $endpoint): BmcOperation
    {
        // ForceRestart rather than GracefulRestart: reset() is what a network
        // install is started with, and a machine that has just been armed for
        // PXE has no operating system cooperation to ask for.
        return $this->sendReset($endpoint, 'ForceRestart', 'reset', PowerState::On);
    }

    /**
     * One ComputerSystem.Reset action, whatever the caller called it.
     *
     * All four power methods share a single endpoint on the controller and
     * differ only in the ResetType they send, so the branching lives here and
     * the public methods stay named after what an operator asked for rather
     * than after the wire format.
     */
    protected function sendReset(BmcEndpoint $endpoint, string $resetType, string $operation, PowerState $resulting): BmcOperation
    {
        $this->assertEndpointMatches($endpoint);

        $response = $this->send(
            'POST',
            $this->systemPath().'/Actions/ComputerSystem.Reset',
            ['ResetType' => $resetType],
            $operation,
        );

        $this->assertAccepted($response, $operation, ['reset_type' => $resetType]);

        return new BmcOperation(
            operation: $operation,
            endpointId: $this->connection->endpointId,
            protocol: $this->protocol(),
            // Redfish returns a task for work it cannot finish inline. When it
            // does, the caller has a handle; when it does not, there is
            // nothing to poll and pretending otherwise would invent one.
            taskId: $this->taskIdFrom($response),
            resultingPowerState: $resulting,
            metadata: ['reset_type' => $resetType, 'status' => $response->status()],
        );
    }

    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
    {
        $this->assertEndpointMatches($endpoint);

        $operation = 'set_one_time_pxe';

        /*
         * BootSourceOverrideEnabled is "Once" and the platform has no code
         * path that sends "Continuous".
         *
         * "Continuous" is a persistent boot-order change wearing a different
         * name: the machine would network boot every single time it powers on,
         * so the next unplanned reboot — a power cut, a kernel panic, an
         * engineer pressing the wrong button — silently reinstalls a
         * customer's server and erases it. "Once" is consumed by the boot it
         * arms, which is why an install that fails leaves a machine that comes
         * back up as it was rather than one that reinstalls for ever.
         */
        $response = $this->send('PATCH', $this->systemPath(), [
            'Boot' => [
                'BootSourceOverrideTarget' => self::BOOT_TARGET_PXE,
                'BootSourceOverrideEnabled' => self::BOOT_OVERRIDE_ONCE,
            ],
        ], $operation);

        $this->assertAccepted($response, $operation, ['boot_target' => self::BOOT_TARGET_PXE]);

        return new BmcOperation(
            operation: $operation,
            endpointId: $this->connection->endpointId,
            protocol: $this->protocol(),
            taskId: $this->taskIdFrom($response),
            metadata: [
                'boot_source_override_target' => self::BOOT_TARGET_PXE,
                'boot_source_override_enabled' => self::BOOT_OVERRIDE_ONCE,
            ],
        );
    }

    public function bootOrder(BmcEndpoint $endpoint): array
    {
        $this->assertEndpointMatches($endpoint);

        $system = $this->getResource($this->systemPath(), 'boot_order');

        return $this->bootOrderFrom($system);
    }

    public function firmwareInventory(BmcEndpoint $endpoint): array
    {
        $this->assertEndpointMatches($endpoint);

        $components = [];

        foreach ($this->collection($this->firmwareInventoryPath(), 'firmware_inventory') as $member) {
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
     * The Redfish path for the system this connection addresses.
     */
    protected function systemPath(): string
    {
        return '/redfish/v1/Systems/'.$this->connection->systemId;
    }

    /**
     * Where the firmware inventory lives.
     *
     * A seam rather than a constant: the standard location is under
     * UpdateService, and vendors whose implementation predates that put it
     * somewhere else.
     */
    protected function firmwareInventoryPath(): string
    {
        return '/redfish/v1/UpdateService/FirmwareInventory';
    }

    /**
     * The chassis-level verdict.
     *
     * A seam because vendors that predate a complete Status implementation
     * report aggregate health in an Oem block instead.
     *
     * @param  array<string, mixed>  $system
     */
    protected function overallHealthOf(array $system): ComponentHealth
    {
        $status = $system['Status'] ?? [];

        return ComponentHealth::fromRedfish(is_array($status) ? $this->stringAt($status, 'Health') : null);
    }

    /**
     * The configured boot order, most preferred first.
     *
     * Standard Redfish puts it in Boot.BootOrder. When a controller does not
     * publish one, the allowable override targets are returned instead: they
     * are not the order, but they are what the caller can actually assert
     * against, and returning an empty list would read as "this machine has no
     * boot devices".
     *
     * @param  array<string, mixed>  $system
     * @return list<string>
     */
    protected function bootOrderFrom(array $system): array
    {
        $boot = $system['Boot'] ?? [];

        if (! is_array($boot)) {
            return [];
        }

        $order = $boot['BootOrder'] ?? $boot['BootSourceOverrideTarget@Redfish.AllowableValues'] ?? [];

        if (! is_array($order)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $entry): string => is_string($entry) ? $entry : (string) json_encode($entry),
            $order,
        ));
    }

    /**
     * CPU readings, taken from the system summary.
     *
     * The summary rather than a walk of /Processors: a socket-by-socket read
     * costs one request per socket on every discovery pass across the whole
     * fleet, and the summary already carries the count and the verdict, which
     * is what a health check acts on.
     *
     * @param  array<string, mixed>  $system
     * @return list<ComponentReading>
     */
    protected function processorReadings(array $system): array
    {
        $summary = $system['ProcessorSummary'] ?? null;

        if (! is_array($summary)) {
            return [];
        }

        $status = is_array($summary['Status'] ?? null) ? $summary['Status'] : [];

        return [new ComponentReading(
            kind: ComponentKind::Cpu,
            name: $this->stringAt($summary, 'Model') ?? 'Processors',
            health: ComponentHealth::fromRedfish($this->stringAt($status, 'HealthRollup') ?? $this->stringAt($status, 'Health')),
            model: $this->stringAt($summary, 'Model'),
            quantity: max(1, (int) ($summary['Count'] ?? 1)),
            attributes: array_filter([
                'logical_processors' => isset($summary['LogicalProcessorCount']) ? (int) $summary['LogicalProcessorCount'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        )];
    }

    /**
     * @param  array<string, mixed>  $system
     * @return list<ComponentReading>
     */
    protected function memoryReadings(array $system): array
    {
        $summary = $system['MemorySummary'] ?? null;

        if (! is_array($summary)) {
            return [];
        }

        $status = is_array($summary['Status'] ?? null) ? $summary['Status'] : [];

        return [new ComponentReading(
            kind: ComponentKind::Memory,
            name: 'System memory',
            health: ComponentHealth::fromRedfish($this->stringAt($status, 'HealthRollup') ?? $this->stringAt($status, 'Health')),
            attributes: array_filter([
                'total_gib' => isset($summary['TotalSystemMemoryGiB']) ? (int) $summary['TotalSystemMemoryGiB'] : null,
            ], static fn (mixed $value): bool => $value !== null),
        )];
    }

    /**
     * NIC readings, walked one member at a time.
     *
     * Worth the extra requests where the summaries are not: the MAC address is
     * only published per interface, and a MAC address is the identity a PXE
     * authorisation is granted to. Without it the boot server would have to be
     * told "any machine on this VLAN may install", which is not an
     * authorisation at all.
     *
     * @return list<ComponentReading>
     */
    protected function networkReadings(): array
    {
        $readings = [];

        foreach ($this->collection($this->systemPath().'/EthernetInterfaces', 'hardware_health') as $nic) {
            $status = is_array($nic['Status'] ?? null) ? $nic['Status'] : [];
            $id = $this->stringAt($nic, 'Id') ?? 'nic';

            $readings[] = new ComponentReading(
                kind: ComponentKind::Nic,
                name: $this->stringAt($nic, 'Name') ?? $id,
                health: ComponentHealth::fromRedfish($this->stringAt($status, 'Health')),
                model: $this->stringAt($nic, 'Model'),
                attributes: array_filter([
                    'mac_address' => $this->stringAt($nic, 'MACAddress') ?? $this->stringAt($nic, 'PermanentMACAddress'),
                    'speed_mbps' => isset($nic['SpeedMbps']) ? (int) $nic['SpeedMbps'] : null,
                    'interface_id' => $id,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $readings;
    }

    /**
     * Drive readings, walked through the storage subsystems.
     *
     * Also worth the requests: a disk is the component whose failure is a
     * customer's data, and the serial number a warranty claim is made against
     * exists nowhere except on the drive resource.
     *
     * @return list<ComponentReading>
     */
    protected function storageReadings(): array
    {
        $readings = [];

        foreach ($this->collection($this->systemPath().'/Storage', 'hardware_health') as $subsystem) {
            foreach ($this->storageControllerReadings($subsystem) as $reading) {
                $readings[] = $reading;
            }

            $drives = $subsystem['Drives'] ?? [];

            if (! is_array($drives)) {
                continue;
            }

            foreach (array_slice($drives, 0, self::MAX_COLLECTION_MEMBERS) as $reference) {
                $path = is_array($reference) ? ($reference['@odata.id'] ?? null) : null;

                if (! is_string($path)) {
                    continue;
                }

                $drive = $this->getResource($this->resolveLink($path, 'hardware_health'), 'hardware_health');
                $status = is_array($drive['Status'] ?? null) ? $drive['Status'] : [];

                $readings[] = new ComponentReading(
                    kind: ComponentKind::Disk,
                    name: $this->stringAt($drive, 'Name') ?? $this->stringAt($drive, 'Id') ?? 'Drive',
                    health: ComponentHealth::fromRedfish($this->stringAt($status, 'Health')),
                    model: $this->stringAt($drive, 'Model'),
                    serial: $this->stringAt($drive, 'SerialNumber'),
                    attributes: array_filter([
                        'capacity_bytes' => isset($drive['CapacityBytes']) ? (int) $drive['CapacityBytes'] : null,
                        'media_type' => $this->stringAt($drive, 'MediaType'),
                        'protocol' => $this->stringAt($drive, 'Protocol'),
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }
        }

        return $readings;
    }

    /**
     * @param  array<string, mixed>  $subsystem
     * @return list<ComponentReading>
     */
    protected function storageControllerReadings(array $subsystem): array
    {
        $controllers = $subsystem['StorageControllers'] ?? [];

        if (! is_array($controllers)) {
            return [];
        }

        $readings = [];

        foreach ($controllers as $controller) {
            if (! is_array($controller)) {
                continue;
            }

            $status = is_array($controller['Status'] ?? null) ? $controller['Status'] : [];

            $readings[] = new ComponentReading(
                kind: ComponentKind::RaidController,
                name: $this->stringAt($controller, 'Name') ?? 'Storage controller',
                health: ComponentHealth::fromRedfish($this->stringAt($status, 'Health')),
                model: $this->stringAt($controller, 'Model'),
                serial: $this->stringAt($controller, 'SerialNumber'),
                attributes: array_filter([
                    'firmware_version' => $this->stringAt($controller, 'FirmwareVersion'),
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        return $readings;
    }

    /**
     * Fetch a Redfish collection and then each of its members.
     *
     * Redfish collections carry only links, so a member's content costs a
     * second request; that is the protocol, not a design choice here. The cap
     * is a design choice: a chassis reporting a thousand members must not turn
     * one scheduled health check into a thousand requests against a controller
     * whose entire CPU is slower than a phone's.
     *
     * A collection that cannot be read is treated as empty rather than fatal.
     * Vendors omit whole subtrees, and a machine with no /Storage must still
     * produce a health reading rather than failing discovery for the fleet.
     *
     * @return list<array<string, mixed>>
     */
    protected function collection(string $path, string $operation): array
    {
        try {
            $collection = $this->getResource($path, $operation);
        } catch (DedicatedProviderException $e) {
            if ($e->isIndeterminate()) {
                // A timeout is never swallowed: an empty inventory that is
                // really "the controller stopped answering" would be recorded
                // as a machine whose disks have all disappeared.
                throw $e;
            }

            return [];
        }

        $members = $collection['Members'] ?? [];

        if (! is_array($members)) {
            return [];
        }

        $resources = [];

        foreach (array_slice($members, 0, self::MAX_COLLECTION_MEMBERS) as $member) {
            if (! is_array($member)) {
                continue;
            }

            $memberPath = $member['@odata.id'] ?? null;

            if (! is_string($memberPath)) {
                continue;
            }

            // The member link is response data and is validated before it is
            // turned into a request that carries the controller credential.
            $resources[] = $this->getResource($this->resolveLink($memberPath, $operation), $operation);
        }

        return $resources;
    }

    /**
     * Turn a link the controller published into a path this adapter is
     * willing to send a credential to.
     *
     * Redfish is a tree of links: a collection carries only `@odata.id`
     * references and every member's content costs a second request built from
     * one. Those references are RESPONSE DATA, and every request this adapter
     * makes carries HTTP Basic — the controller's username and password, which
     * is power control and virtual media on a physical host.
     *
     * The HTTP client treats a value beginning with "http://" or "https://" as
     * an absolute URL and ignores the configured base, so a controller that
     * answered with `{"@odata.id": "https://somewhere-else/"}` would have the
     * platform post its BMC credential to whatever host it named — and, with
     * the worker sitting on the management network, fetch arbitrary internal
     * URLs on request. A compromised controller is exactly the machine that
     * would do it, and an endpoint row may legitimately waive certificate
     * verification, so a controller's answers cannot be trusted to be its own.
     *
     * A relative path is therefore the only shape followed without question. A
     * fully qualified link is accepted only when it points back at this same
     * controller, because some implementations publish self-links that way.
     * Anything else is refused out loud rather than skipped: a machine naming
     * somewhere else is not a machine whose inventory should be quietly
     * reported as short a disk.
     *
     * @throws DedicatedProviderException
     */
    protected function resolveLink(string $reference, string $operation): string
    {
        $link = trim($reference);

        // "//host/path" is protocol-relative and is NOT a path, so it is
        // excluded here rather than left to the client to interpret.
        if (str_starts_with($link, '/') && ! str_starts_with($link, '//')) {
            return $link;
        }

        $parts = parse_url($link);

        if (is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === $this->connection->address
            && (int) ($parts['port'] ?? 443) === $this->connection->port
        ) {
            return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        throw DedicatedProviderException::unexpectedResponse(
            static::NAME,
            $operation,
            'the controller published a link pointing somewhere other than itself, and it will not be followed '
            .'because every request this adapter makes carries the controller credential',
            ['link' => $this->scrub(mb_substr($link, 0, 256))],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getResource(string $path, string $operation): array
    {
        $response = $this->send('GET', $path, [], $operation);

        if ($response->failed()) {
            throw DedicatedProviderException::requestFailed(static::NAME, $operation, [
                'path' => $path,
                'status' => $response->status(),
                'provider_message' => $this->errorMessage($response),
            ], indeterminate: self::isIndeterminateStatus($response->status()));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw DedicatedProviderException::unexpectedResponse(
                static::NAME,
                $operation,
                'the controller answered with a body that is not a Redfish resource',
                ['path' => $path, 'status' => $response->status()],
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    protected function assertAccepted(Response $response, string $operation, array $context = []): void
    {
        if (! $response->failed()) {
            return;
        }

        throw DedicatedProviderException::requestFailed(static::NAME, $operation, [
            ...$context,
            'status' => $response->status(),
            'provider_message' => $this->errorMessage($response),
        ], indeterminate: self::isIndeterminateStatus($response->status()));
    }

    /**
     * Redfish reports long-running work as a task, either in a Location header
     * or as a body with an Id. Null means the controller finished inline.
     */
    protected function taskIdFrom(Response $response): ?string
    {
        $body = $response->json();

        if (is_array($body) && isset($body['Id']) && is_string($body['Id'])) {
            return $body['Id'];
        }

        $location = $response->header('Location');

        return $location === '' ? null : $location;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $path, array $payload, string $operation): Response
    {
        try {
            $request = $this->request();

            $response = match ($method) {
                'GET' => $request->get($path),
                'POST' => $request->post($path, $payload),
                'PATCH' => $request->patch($path, $payload),
                default => throw DedicatedProviderException::requestFailed(static::NAME, $operation, [
                    'provider_message' => sprintf('unsupported HTTP method "%s"', $method),
                ]),
            };

            $this->assertNotRedirect($response, $operation, ['path' => $path]);

            return $response;
        } catch (ConnectionException $e) {
            /*
             * Caught by type and re-thrown as our own, because a connection
             * exception carries the whole request — Authorization header
             * included — in its message.
             *
             * Flagged indeterminate, which is the part that matters. This is a
             * timeout or a dropped connection: the platform stopped waiting,
             * the controller did not stop working. A reset it accepted a
             * moment before the socket died is already under way, and a caller
             * that retried would power cycle a customer's machine a second
             * time — possibly mid-install.
             */
            throw DedicatedProviderException::requestFailed(static::NAME, $operation, [
                'path' => $path,
                'provider_message' => $this->scrub($e->getMessage()),
            ], previous: $e, indeterminate: true);
        } catch (DedicatedProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Anything else from the transport is unknown by definition: we do
            // not know whether the request reached the controller, so it is
            // treated with the same caution as a timeout.
            throw DedicatedProviderException::requestFailed(static::NAME, $operation, [
                'path' => $path,
                'provider_message' => $this->scrub($e->getMessage()),
            ], previous: $e, indeterminate: true);
        }
    }

    /**
     * A 3xx is not an answer, it is a destination — and it is chosen by the
     * device, not by the platform.
     *
     * Redirects are disabled on the client, so one arrives here as an ordinary
     * response with no body. It is refused rather than parsed: `failed()` is
     * false for a 3xx, so an unrefused redirect would be read as an empty
     * success. Flagged indeterminate because a controller that answered a
     * reset with a redirect may still have accepted the reset.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws DedicatedProviderException
     */
    protected function assertNotRedirect(Response $response, string $operation, array $context = []): void
    {
        if ($response->status() < 300 || $response->status() > 399) {
            return;
        }

        throw DedicatedProviderException::unexpectedResponse(
            static::NAME,
            $operation,
            'the controller answered with a redirect, which is not followed because every request this '
            .'adapter makes carries the controller credential',
            [...$context, 'status' => $response->status()],
            indeterminate: true,
        );
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->connection->baseUrl())
            /*
             * Basic auth over TLS. Redfish also offers session tokens, which
             * would be marginally better, but a session has to be created,
             * refreshed and deleted — and a controller with a handful of
             * session slots, which is typical, locks the platform out of a
             * machine as soon as a worker dies without logging out. Basic is
             * stateless and cannot leak a session nobody can close.
             */
            ->withBasicAuth($this->connection->username, $this->connection->password)
            // Explicit rather than left to the client default, so that a change
            // to that default cannot silently disable certificate verification
            // for every controller in the fleet at once.
            //
            // Redirects are refused for the same reason resolveLink() refuses
            // an off-host @odata.id: a Location header is response data too,
            // and following one would let a controller point any request this
            // adapter makes at a host of its choosing — with the worker on the
            // management network — without the URL ever becoming a link in a
            // body that resolveLink() could inspect.
            ->withOptions(['verify' => $this->connection->verifyTls, 'allow_redirects' => false])
            ->timeout($this->connection->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }

    /**
     * Statuses that mean "somebody in the path gave up", not "the controller
     * refused".
     *
     * A 502, 503 or 504 comes from a load balancer or from the controller's
     * own overloaded web stack and says nothing about whether the request
     * arrived. A 4xx is the controller answering: it read the request and
     * declined it.
     */
    protected static function isIndeterminateStatus(int $status): bool
    {
        return in_array($status, [408, 429, 502, 503, 504], true);
    }

    /**
     * The controller's own words, scrubbed.
     *
     * Kept rather than discarded because "the value Continuous is not
     * supported" and "insufficient privilege" need completely different human
     * responses, and a generic message would hide both.
     */
    protected function errorMessage(Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $error = $body['error'] ?? null;

            if (is_array($error)) {
                $extended = $error['@Message.ExtendedInfo'] ?? null;

                if (is_array($extended) && $extended !== []) {
                    $messages = [];

                    foreach ($extended as $entry) {
                        if (is_array($entry) && isset($entry['Message']) && is_string($entry['Message'])) {
                            $messages[] = $entry['Message'];
                        }
                    }

                    if ($messages !== []) {
                        return $this->scrub(implode('; ', $messages));
                    }
                }

                if (isset($error['message']) && is_string($error['message'])) {
                    return $this->scrub($error['message']);
                }
            }
        }

        // Truncated: an HTML error page from a controller's web stack is
        // kilobytes of no use in a log line.
        return $this->scrub(mb_substr(trim($response->body()), 0, 512));
    }

    /**
     * Remove this connection's credential, then everything else that looks
     * like one.
     *
     * The explicit replacement comes first and is not redundant. The shared
     * redactor recognises credential SHAPES; a BMC password is an arbitrary
     * string that matches no pattern anybody could write without knowing this
     * deployment's configuration. Only this object knows the value, so only
     * this object can guarantee it is gone.
     */
    protected function scrub(string $message): string
    {
        $stripped = str_replace(
            $this->connection->secretValues(),
            SecretRedactor::PLACEHOLDER,
            $message,
        );

        return $this->redactor->redactString($stripped);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function stringAt(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Refuse to operate on a machine this adapter was not built for.
     *
     * The adapter carries one endpoint's address and credential; being handed
     * a different row means a caller resolved the wrong provider, and the
     * request would go to the machine this adapter points at rather than the
     * one the caller meant. On a hypervisor that is a wrong VM id; here it is
     * somebody else's physical server being power cycled or reinstalled.
     */
    protected function assertEndpointMatches(BmcEndpoint $endpoint): void
    {
        if ((string) $endpoint->getKey() !== $this->connection->endpointId) {
            throw BmcNotConfiguredException::endpointMismatch(
                $this->connection->endpointId,
                (string) $endpoint->getKey(),
            );
        }
    }
}
