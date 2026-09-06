<?php

declare(strict_types=1);

namespace Tests\Unit\Dedicated;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\ComponentKind;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\BmcConnection;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IpmiDedicatedProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The last-resort adapter, and the two rules that make it safe to have at all.
 *
 * 1. Arguments are an ARRAY, so no shell ever parses them. Inventory data is
 *    not trusted input: an address is whatever an operator or a discovery job
 *    wrote, and interpolating one into a shell string is arbitrary command
 *    execution as the queue worker — which holds the credentials for every BMC
 *    in the fleet.
 *
 * 2. The password is in argv because ipmitool offers no alternative, so every
 *    string this class can produce is redacted. A command echoed into an
 *    exception message or a log line would otherwise print a credential for
 *    physical power control verbatim.
 *
 * Both are asserted here against what the adapter would actually execute,
 * rather than reviewed by eye.
 */
final class IpmiDedicatedProviderTest extends TestCase
{
    private const string ENDPOINT_ID = '01JBMCIPMIENDPOINT0000000A';

    private const string USERNAME = 'ADMIN';

    private const string PASSWORD = 'bmc-root-pass-7Kq3';

    /** A hostname that would be a second command if it were ever concatenated. */
    private const string MALICIOUS_ADDRESS = '192.0.2.10; rm -rf /';

    #[Test]
    public function the_command_is_built_as_an_array_of_arguments(): void
    {
        $command = $this->provider()->commandFor($this->endpoint(), ['chassis', 'power', 'status']);

        $this->assertSame([
            'ipmitool',
            '-I', 'lanplus',
            '-H', '192.0.2.10',
            '-p', '623',
            '-U', self::USERNAME,
            '-P', self::PASSWORD,
            'chassis', 'power', 'status',
        ], $command);

        // Every element is a separate argument. A single string anywhere in
        // this list would mean something built it by concatenation.
        foreach ($command as $argument) {
            $this->assertIsString($argument);
        }
    }

    #[Test]
    public function a_hostname_containing_a_shell_metacharacter_cannot_become_a_second_command(): void
    {
        $endpoint = $this->endpoint(self::MALICIOUS_ADDRESS);

        $command = $this->provider()->commandFor($endpoint, ['chassis', 'power', 'off']);

        // The whole hostname, semicolon and all, is ONE element. Passed to
        // execve() it is an argument to ipmitool — which will fail to resolve
        // it — and never a command a shell would run.
        $this->assertContains(self::MALICIOUS_ADDRESS, $command);
        $this->assertSame(self::MALICIOUS_ADDRESS, $command[array_search('-H', $command, true) + 1]);

        // And nothing was split on the separator: "rm" is not an argument of
        // its own, which is what would have happened had the command been
        // built as a string and then exploded by a shell.
        $this->assertNotContains('rm', $command);
        $this->assertNotContains('-rf', $command);
        $this->assertNotContains('/', $command);

        // The subcommand still ends the list, so the injected text has not
        // displaced the operation the platform asked for.
        $this->assertSame(['chassis', 'power', 'off'], array_slice($command, -3));
    }

    #[Test]
    public function the_process_runner_receives_the_argument_array_not_a_string(): void
    {
        Process::fake(['*' => Process::result('Chassis Power is on')]);

        $this->provider()->powerState($this->endpoint(self::MALICIOUS_ADDRESS));

        Process::assertRan(function (PendingProcess $process): bool {
            /*
             * This is the assertion that matters most in the module. Laravel
             * (and Symfony beneath it) runs an ARRAY command without a shell
             * and a STRING command through one. A string here would mean the
             * hostname below is executed rather than passed.
             */
            $this->assertIsArray($process->command);
            $this->assertContains(self::MALICIOUS_ADDRESS, $process->command);

            return true;
        });
    }

    #[Test]
    public function an_ipmi_password_never_appears_in_an_exception_message_or_context(): void
    {
        // ipmitool printing its own usage back at us, command line and all,
        // which is a real thing it does on a malformed invocation.
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'Error: Invalid command; used: ipmitool -I lanplus -H 192.0.2.10 -U ADMIN -P '.self::PASSWORD,
                exitCode: 1,
            ),
        ]);

        try {
            $this->provider()->powerOn($this->endpoint());

            $this->fail('A failed ipmitool invocation was reported as success.');
        } catch (DedicatedProviderException $e) {
            $serialised = $e->getMessage().json_encode($e->context());

            $this->assertStringNotContainsString(self::PASSWORD, $serialised);
            // The command IS reported, because an operator needs to see what
            // was attempted — with the credential masked out of it.
            $this->assertStringContainsString('ipmitool', (string) $e->context()['command']);
            $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, (string) $e->context()['command']);
        }
    }

    #[Test]
    public function an_ipmi_password_never_reaches_a_log_line(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'Unable to establish LAN session', exitCode: 1),
        ]);

        $lines = [];

        // Capture what the application would actually write, rather than
        // asserting on a string the test built itself.
        Log::listen(function (MessageLogged $event) use (&$lines): void {
            $lines[] = $event->message.json_encode($event->context);
        });

        try {
            $this->provider()->reset($this->endpoint());
        } catch (DedicatedProviderException $e) {
            Log::error($e->getMessage(), $e->context());
        }

        $this->assertNotEmpty($lines, 'The failure was never logged, so the assertion would be vacuous.');

        foreach ($lines as $line) {
            $this->assertStringNotContainsString(self::PASSWORD, $line);
        }
    }

    #[Test]
    public function the_rendered_command_masks_the_password_and_keeps_everything_else(): void
    {
        $provider = $this->provider();

        $rendered = $provider->redactCommand(
            $provider->commandFor($this->endpoint(), ['chassis', 'bootdev', 'pxe']),
        );

        $this->assertStringNotContainsString(self::PASSWORD, $rendered);
        $this->assertStringContainsString('-P '.SecretRedactor::PLACEHOLDER, $rendered);

        // The username is not a secret and stays legible: an operator reading
        // a failure needs to know which account was refused.
        $this->assertStringContainsString('-U '.self::USERNAME, $rendered);
        $this->assertStringContainsString('chassis bootdev pxe', $rendered);
    }

    #[Test]
    public function a_one_time_pxe_boot_never_asks_for_a_persistent_boot_device(): void
    {
        Process::fake(['*' => Process::result('Set Boot Device to pxe')]);

        $operation = $this->provider()->setOneTimePxeBoot($this->endpoint());

        Process::assertRan(function (PendingProcess $process): bool {
            /** @var list<string> $command */
            $command = $process->command;

            $this->assertSame(['chassis', 'bootdev', 'pxe'], array_slice($command, -3));

            /*
             * `persistent` is what would make the override permanent, and its
             * absence is what makes this one-time. A machine left booting PXE
             * reinstalls itself at the next power cut.
             */
            foreach ($command as $argument) {
                $this->assertStringNotContainsString('persistent', $argument);
            }

            return true;
        });

        $this->assertFalse($operation->metadata['persistent']);
    }

    #[Test]
    public function a_process_that_is_killed_at_its_deadline_is_indeterminate(): void
    {
        Process::fake(['*' => fn () => throw new ProcessTimedOutException(
            new \Symfony\Component\Process\Exception\ProcessTimedOutException(
                new \Symfony\Component\Process\Process(['ipmitool']),
                \Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL,
            ),
            Process::result(''),
        )]);

        try {
            $this->provider()->powerOff($this->endpoint());

            $this->fail('A killed ipmitool process was reported as a clean failure.');
        } catch (DedicatedProviderException $e) {
            /*
             * ipmitool fires a UDP request and waits. A timeout says nothing
             * about whether the BMC received it, so a power-off that timed out
             * may have taken the machine down a second after we gave up.
             */
            $this->assertTrue($e->isIndeterminate());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage().json_encode($e->context()));
        }
    }

    #[Test]
    public function a_lost_lan_session_is_indeterminate_but_a_rejected_credential_is_not(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'Error: Unable to establish LAN session', exitCode: 1)]);

        try {
            $this->provider()->powerOff($this->endpoint());
            $this->fail('A lost session was reported as success.');
        } catch (DedicatedProviderException $e) {
            // The request may have arrived and been acted on before the reply
            // was lost, so the cautious reading is the correct one.
            $this->assertTrue($e->isIndeterminate());
        }

        Process::fake(['*' => Process::result(output: '', errorOutput: 'Invalid user name', exitCode: 1)]);

        try {
            $this->provider()->powerOff($this->endpoint());
            $this->fail('A rejected credential was reported as success.');
        } catch (DedicatedProviderException $e) {
            // The controller answered and declined; nothing happened.
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function the_chassis_power_status_line_is_read_into_a_power_state(): void
    {
        Process::fake(['*' => Process::result('Chassis Power is off')]);

        $this->assertSame(PowerState::Off, $this->provider()->powerState($this->endpoint()));

        Process::fake(['*' => Process::result('Chassis Power is on')]);

        $this->assertSame(PowerState::On, $this->provider()->powerState($this->endpoint()));

        // Anything unrecognised is Unknown rather than a guess: inventing
        // "off" for a line this adapter has not seen is how a running machine
        // gets power cycled.
        Process::fake(['*' => Process::result('Chassis Power is something new')]);

        $this->assertSame(PowerState::Unknown, $this->provider()->powerState($this->endpoint()));
    }

    #[Test]
    public function a_health_read_never_powers_or_reconfigures_the_machine(): void
    {
        Process::fake(['*' => Process::result(implode("\n", [
            'CPU1 Temp        | 41 degrees C      | ok',
            'PS1 Status       | 0x01              | ok',
            'DIMM A1 Temp     | 33 degrees C      | nc',
        ]))]);

        $health = $this->provider()->hardwareHealth($this->endpoint());

        // Discovery runs on a schedule across the whole fleet, so a mutation on
        // this path would be a mutation applied to every machine the platform
        // owns in a single pass.
        Process::assertNotRan(function (PendingProcess $process): bool {
            /** @var list<string> $command */
            $command = $process->command;

            foreach (['on', 'off', 'reset', 'cycle', 'soft', 'bootdev'] as $mutation) {
                if (in_array($mutation, $command, true)) {
                    return true;
                }
            }

            return false;
        });

        // A non-critical DIMM sensor degrades the machine's verdict: silence
        // and health are not the same answer.
        $this->assertSame(ComponentHealth::Warning, $health->effectiveHealth());
        $this->assertCount(3, $health->components);
    }

    #[Test]
    public function sensor_names_are_classified_without_claiming_unrelated_readings(): void
    {
        Process::fake(['*' => Process::result(implode("\n", [
            'CPU1 Temp        | 41 degrees C      | ok',
            'PS1 Status       | 0x01              | ok',
            'Inlet Temps      | 22 degrees C      | ok',
        ]))]);

        $health = $this->provider()->hardwareHealth($this->endpoint());

        $this->assertCount(1, $health->componentsOfKind(ComponentKind::Cpu));
        // "Temps" contains "ps" and must not be read as a power supply — a
        // misclassified sensor is an engineer sent to pull the wrong part.
        $this->assertCount(1, $health->componentsOfKind(ComponentKind::PowerSupply));
    }

    #[Test]
    public function the_boot_device_selector_is_read_back_from_boot_parameter_five(): void
    {
        Process::fake(['*' => Process::result(implode("\n", [
            'Boot parameter version: 1',
            'Boot Device Selector : Force PXE',
        ]))]);

        // Read specifically to prove a negative: that a one-time override was
        // consumed rather than left behind as a permanent boot order.
        $this->assertSame(['Force PXE'], $this->provider()->bootOrder($this->endpoint()));
    }

    #[Test]
    public function the_firmware_inventory_reports_only_the_controllers_own_revision(): void
    {
        Process::fake(['*' => Process::result(implode("\n", [
            'Device ID                 : 32',
            'Firmware Revision         : 2.60',
            'Manufacturer Name         : Hewlett-Packard',
        ]))]);

        $firmware = $this->provider()->firmwareInventory($this->endpoint());

        // One component, and it is the BMC. IPMI publishes nothing about BIOS,
        // RAID or NIC firmware — the very images a security advisory names —
        // which is the strongest practical argument for migrating a machine to
        // Redfish.
        $this->assertCount(1, $firmware);
        $this->assertSame('bmc', $firmware[0]->id);
        $this->assertSame('2.60', $firmware[0]->version);
    }

    private function endpoint(string $address = '192.0.2.10'): BmcEndpoint
    {
        $endpoint = new BmcEndpoint;

        $endpoint->forceFill([
            'protocol' => BmcProtocol::Ipmi,
            'address' => $address,
            'port' => null,
            'username' => self::USERNAME,
            'verify_tls' => true,
        ]);

        $endpoint->id = self::ENDPOINT_ID;

        return $endpoint;
    }

    private function provider(): IpmiDedicatedProvider
    {
        return new IpmiDedicatedProvider(
            new BmcConnection(
                endpointId: self::ENDPOINT_ID,
                protocol: BmcProtocol::Ipmi,
                address: '192.0.2.10',
                port: 623,
                username: self::USERNAME,
                password: self::PASSWORD,
                timeoutSeconds: 5,
            ),
            new SecretRedactor,
        );
    }
}
