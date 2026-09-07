<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Providers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
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
 * IPMI, over ipmitool, as a last resort.
 *
 * This adapter exists because some machines have nothing better, not because
 * IPMI is a reasonable thing to speak. It reports failure as unparsed English
 * on stderr, cannot distinguish a refusal from a timeout without guessing, and
 * has no inventory to speak of. Every machine reachable only this way is a
 * machine that should be on a list to replace.
 *
 * Two rules here are absolute, and both are enforced in code and asserted in
 * the test suite rather than left to reviewer discipline:
 *
 * 1. **Arguments are an ARRAY, never an interpolated string.** Every command
 *    is built as a list and handed to the process runner as a list, so the
 *    values are passed to execve() directly and no shell ever parses them.
 *    Inventory data is not trusted input: an address, a username or a sensor
 *    name is whatever an operator or a discovery job wrote, and a hostname
 *    containing `; rm -rf /` interpolated into a shell string would be
 *    arbitrary command execution — running as whatever user the queue worker
 *    is, holding the credentials for every BMC in the fleet. There is
 *    deliberately no code path in this class that concatenates a command.
 *
 * 2. **The password is in argv, so every string that could be logged is
 *    redacted.** ipmitool offers no alternative: `-P` is the only non-
 *    interactive mechanism, and the environment-variable form
 *    (IPMI_PASSWORD with -E) merely moves the secret somewhere else that a
 *    crash dump also captures. The password therefore reaches argv, and this
 *    class guarantees the only thing it can: that no message, exception,
 *    context value or log line built here contains it. Commands are rendered
 *    for humans by {@see self::redactCommand()}, which masks the value after
 *    -P before the string exists — the redaction is not a filter applied on
 *    the way out, because a filter is something a future branch can bypass.
 */
final class IpmiDedicatedProvider implements DedicatedProvider
{
    public const string NAME = 'ipmi';

    /** The interface: IPMI 2.0 over LAN, which is the only one with encryption. */
    private const string INTERFACE = 'lanplus';

    /**
     * How many sensors one health read will report on.
     *
     * A chassis with hundreds of sensors would otherwise turn a scheduled
     * health check into hundreds of component rows per machine per pass.
     */
    private const int MAX_SENSORS = 64;

    public function __construct(
        private readonly BmcConnection $connection,
        private readonly SecretRedactor $redactor,
    ) {}

    public function protocol(): BmcProtocol
    {
        return BmcProtocol::Ipmi;
    }

    public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth
    {
        $this->assertEndpointMatches($endpoint);

        $power = $this->run($endpoint, ['chassis', 'power', 'status'], 'hardware_health');
        $sensors = $this->run($endpoint, ['sdr', 'list'], 'hardware_health');

        $components = $this->sensorReadings($sensors->output());

        $overall = ComponentHealth::Unknown;

        foreach ($components as $component) {
            $overall = $overall->worseOf($component->health);
        }

        return new HardwareHealth(
            /*
             * IPMI has no chassis-level verdict, so the rollup of the sensors
             * is all there is. That is a real loss and not a shortcut: a
             * Redfish machine reports faults the sensor list never mentions,
             * which is one more reason this protocol is the last resort.
             */
            overall: $overall,
            powerState: PowerState::fromIpmiChassisStatus($power->output()),
            components: $components,
            raw: ['sensor_count' => count($components)],
        );
    }

    public function powerState(BmcEndpoint $endpoint): PowerState
    {
        $this->assertEndpointMatches($endpoint);

        return PowerState::fromIpmiChassisStatus(
            $this->run($endpoint, ['chassis', 'power', 'status'], 'power_state')->output(),
        );
    }

    public function powerOn(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->power($endpoint, 'on', 'power_on', PowerState::On);
    }

    public function powerOff(BmcEndpoint $endpoint): BmcOperation
    {
        // "off" is the hard cut. "soft" is the ACPI request and belongs to
        // gracefulShutdown(); conflating them would make a documented
        // power-off silently ignorable by a hung machine.
        return $this->power($endpoint, 'off', 'power_off', PowerState::Off);
    }

    public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->power($endpoint, 'soft', 'graceful_shutdown', PowerState::Off);
    }

    public function reset(BmcEndpoint $endpoint): BmcOperation
    {
        return $this->power($endpoint, 'reset', 'reset', PowerState::On);
    }

    public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
    {
        $this->assertEndpointMatches($endpoint);

        /*
         * `chassis bootdev pxe` with NO options is one-time by definition: the
         * IPMI boot flags it sets are marked valid for the next boot only and
         * the BMC clears them once consumed.
         *
         * The `persistent` option is what would make it permanent, and it is
         * deliberately absent here — not defaulted, not configurable, not
         * reachable. A machine left booting PXE reinstalls itself the next
         * time it powers on for any reason, which is a customer's whole server
         * erased by a power cut.
         */
        $result = $this->run($endpoint, ['chassis', 'bootdev', 'pxe'], 'set_one_time_pxe');

        return new BmcOperation(
            operation: 'set_one_time_pxe',
            endpointId: $this->connection->endpointId,
            protocol: BmcProtocol::Ipmi,
            // IPMI has no task handle. Null rather than a synthesised id,
            // because a caller must not be able to believe it can poll for an
            // outcome that will never be published.
            taskId: null,
            metadata: ['boot_device' => 'pxe', 'persistent' => false, 'exit_code' => $result->exitCode()],
        );
    }

    public function bootOrder(BmcEndpoint $endpoint): array
    {
        $this->assertEndpointMatches($endpoint);

        // Boot parameter 5 is the boot flags record, which is the closest IPMI
        // comes to publishing an order: it names the device selected for the
        // next boot and whether that selection persists.
        $result = $this->run($endpoint, ['chassis', 'bootparam', 'get', '5'], 'boot_order');

        return $this->bootDevicesFrom($result->output());
    }

    public function firmwareInventory(BmcEndpoint $endpoint): array
    {
        $this->assertEndpointMatches($endpoint);

        $result = $this->run($endpoint, ['mc', 'info'], 'firmware_inventory');

        $fields = $this->parseColonFields($result->output());

        $version = $fields['firmware revision'] ?? null;

        if ($version === null) {
            return [];
        }

        /*
         * One component, and it is the controller's own firmware.
         *
         * IPMI publishes no inventory of BIOS, RAID, NIC or drive firmware —
         * the very images a security advisory names. A fleet on IPMI cannot
         * answer "which machines are running the vulnerable BIOS" at all,
         * which is the strongest practical argument for migrating a machine to
         * Redfish.
         */
        return [new FirmwareComponent(
            id: 'bmc',
            name: 'Baseboard management controller',
            version: $version,
            updateable: false,
            manufacturer: $fields['manufacturer name'] ?? null,
        )];
    }

    /**
     * The exact argv for one ipmitool invocation.
     *
     * Public because it is the security property of this class worth asserting
     * directly: a test can read the list the platform would execute and prove
     * that a hostname containing a shell metacharacter stays a single element
     * of it rather than becoming a second command.
     *
     * @param  list<string>  $arguments  The ipmitool subcommand and its arguments, already split.
     * @return list<string>
     */
    public function commandFor(BmcEndpoint $endpoint, array $arguments): array
    {
        return [
            'ipmitool',
            '-I', self::INTERFACE,
            // Every value below is inventory data. It is placed as its own
            // array element and therefore reaches execve() as one argument,
            // whatever characters it contains. This is the whole mitigation:
            // there is no shell, so there is nothing to escape.
            '-H', $endpoint->address,
            '-p', (string) $endpoint->effectivePort(),
            '-U', $this->connection->username,
            '-P', $this->connection->password,
            ...$arguments,
        ];
    }

    /**
     * Render an argv list as a human-readable command with the password
     * masked.
     *
     * The masking happens as the string is built, not afterwards. A function
     * that produced the real command and then filtered it would leave a
     * window — and a future branch that logged the unfiltered value — whereas
     * here the plaintext password never becomes part of any string at all.
     *
     * @param  list<string>  $command
     */
    public function redactCommand(array $command): string
    {
        $rendered = [];
        $maskNext = false;

        foreach ($command as $argument) {
            if ($maskNext) {
                $rendered[] = SecretRedactor::PLACEHOLDER;
                $maskNext = false;

                continue;
            }

            // -P is ipmitool's password flag. -f names a file holding one, so
            // the path is masked too: a path like /run/secrets/bmc-root is
            // itself a map to the credential.
            $maskNext = $argument === '-P' || $argument === '-f';

            $rendered[] = $argument;
        }

        return implode(' ', $rendered);
    }

    private function power(BmcEndpoint $endpoint, string $action, string $operation, PowerState $resulting): BmcOperation
    {
        $this->assertEndpointMatches($endpoint);

        $result = $this->run($endpoint, ['chassis', 'power', $action], $operation);

        return new BmcOperation(
            operation: $operation,
            endpointId: $this->connection->endpointId,
            protocol: BmcProtocol::Ipmi,
            taskId: null,
            resultingPowerState: $resulting,
            metadata: ['action' => $action, 'exit_code' => $result->exitCode()],
        );
    }

    /**
     * Run one ipmitool command and translate every way it can go wrong.
     *
     * @param  list<string>  $arguments
     *
     * @throws DedicatedProviderException
     */
    private function run(BmcEndpoint $endpoint, array $arguments, string $operation): ProcessResult
    {
        $command = $this->commandFor($endpoint, $arguments);

        // Built once, before anything can fail, so that every branch below has
        // a safe string to talk about and none of them is tempted to build one
        // from the live command.
        $safeCommand = $this->redactCommand($command);

        try {
            $result = Process::timeout($this->connection->timeoutSeconds)->run($command);
        } catch (ProcessTimedOutException $e) {
            /*
             * The process was killed at its deadline. Indeterminate, and this
             * is the branch that matters most on physical hardware: ipmitool
             * fires a UDP request and waits, so a timeout tells us nothing
             * about whether the BMC received it. A power-off that timed out
             * may have taken the machine down a second after we stopped
             * waiting, and a reset that timed out may have dropped the machine
             * into a network install that is running right now.
             *
             * The exception's own message is never used: it stringifies the
             * command line, and the command line has the password in it.
             */
            throw DedicatedProviderException::requestFailed(self::NAME, $operation, [
                'command' => $safeCommand,
                'timeout_seconds' => $this->connection->timeoutSeconds,
            ], previous: $e, indeterminate: true);
        } catch (DedicatedProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Anything else — the binary is missing, the process could not be
            // forked — is unknown by definition, and treated with the same
            // caution as a timeout. Note the message is scrubbed rather than
            // used verbatim for the same reason as above.
            throw DedicatedProviderException::requestFailed(self::NAME, $operation, [
                'command' => $safeCommand,
                'provider_message' => $this->scrub($e->getMessage()),
            ], previous: $e, indeterminate: true);
        }

        if ($result->failed()) {
            /*
             * A non-zero exit is where IPMI is at its worst: "Unable to
             * establish LAN session" is returned both for a wrong password and
             * for a BMC that is not answering, and the exit code is 1 for
             * both. The text is inspected because it is the only signal there
             * is, and anything not recognisably a refusal is treated as
             * indeterminate — the cautious direction, since the alternative is
             * retrying a power operation that may already have happened.
             */
            throw DedicatedProviderException::requestFailed(self::NAME, $operation, [
                'command' => $safeCommand,
                'exit_code' => $result->exitCode(),
                'provider_message' => $this->scrub(trim($result->errorOutput() ?: $result->output())),
            ], indeterminate: ! self::isDefiniteRefusal($result->errorOutput().$result->output()));
        }

        return $result;
    }

    /**
     * Whether ipmitool's output is the BMC saying no, rather than the BMC
     * saying nothing.
     *
     * Only phrases that can come from a controller that answered are listed. A
     * session that could not be established is deliberately NOT here: the
     * request may have arrived and been acted on before the reply was lost.
     */
    private static function isDefiniteRefusal(string $output): bool
    {
        $normalised = strtolower($output);

        foreach ([
            'invalid user name',
            'password',
            'privilege level',
            'insufficient privilege',
            'invalid command',
            'unsupported command',
            'invalid data field',
        ] as $phrase) {
            if (str_contains($normalised, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn `ipmitool sdr list` output into component readings.
     *
     * The format is "Name | Reading | State", pipe separated. Sensor names are
     * vendor text and are treated purely as data.
     *
     * @return list<ComponentReading>
     */
    private function sensorReadings(string $output): array
    {
        $readings = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (count($readings) >= self::MAX_SENSORS) {
                break;
            }

            $columns = array_map(trim(...), explode('|', $line));

            if (count($columns) < 3 || $columns[0] === '') {
                continue;
            }

            $readings[] = new ComponentReading(
                kind: self::sensorKind($columns[0]),
                name: $columns[0],
                health: ComponentHealth::fromIpmiSensorState($columns[2]),
                attributes: ['reading' => $columns[1]],
            );
        }

        return $readings;
    }

    /**
     * Guess a component kind from a sensor's name.
     *
     * A guess, and labelled as one: IPMI sensor names are free text chosen by
     * the board vendor. Anything unrecognised becomes a fan rather than a
     * disk, because the cost of the wrong guess is asymmetric — a
     * misclassified sensor reported as a failing disk would send an engineer
     * to pull a healthy drive out of a customer's array.
     */
    private static function sensorKind(string $name): ComponentKind
    {
        $normalised = strtolower($name);

        return match (true) {
            str_contains($normalised, 'cpu'), str_contains($normalised, 'proc') => ComponentKind::Cpu,
            str_contains($normalised, 'dimm'), str_contains($normalised, 'mem') => ComponentKind::Memory,
            // "PS" is the usual shorthand for a power supply and only as a
            // prefix: matched anywhere it would also claim "Temps" and "Chips".
            str_starts_with($normalised, 'ps'), str_contains($normalised, 'power supply') => ComponentKind::PowerSupply,
            default => ComponentKind::Fan,
        };
    }

    /**
     * @return list<string>
     */
    private function bootDevicesFrom(string $output): array
    {
        $devices = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/Boot Device Selector\s*:\s*(.+)$/i', $line, $matches) === 1) {
                $devices[] = trim($matches[1]);
            }
        }

        return $devices;
    }

    /**
     * @return array<string, string>
     */
    private function parseColonFields(string $output): array
    {
        $fields = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Remove this connection's password by value, then everything else that
     * looks like a credential.
     *
     * The explicit removal is first and is not redundant with the shared
     * redactor: the redactor recognises the SHAPE of `-P something` on a
     * command line, and this removes the specific string even where it appears
     * without its flag — inside an error message that quotes it, for example.
     */
    private function scrub(string $message): string
    {
        $stripped = str_replace($this->connection->secretValues(), SecretRedactor::PLACEHOLDER, $message);

        return $this->redactor->redactString($stripped);
    }

    /**
     * Refuse to operate on a machine this adapter was not built for.
     *
     * The consequence of getting this wrong is a physical host belonging to
     * somebody else being power cycled, so it is checked at every entry point
     * rather than assumed of the caller.
     */
    private function assertEndpointMatches(BmcEndpoint $endpoint): void
    {
        if ((string) $endpoint->getKey() !== $this->connection->endpointId) {
            throw BmcNotConfiguredException::endpointMismatch(
                $this->connection->endpointId,
                (string) $endpoint->getKey(),
            );
        }
    }
}
