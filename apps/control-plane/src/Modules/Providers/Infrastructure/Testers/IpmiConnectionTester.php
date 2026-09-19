<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Lynomia\Modules\Providers\Domain\Contracts\ConnectionTester;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionStep;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use SensitiveParameter;
use Throwable;

/**
 * IPMI, over ipmitool, with the one advantage IPMI has.
 *
 * ===========================================================================
 * THE ONE ADVANTAGE
 * ===========================================================================
 *
 * There is no false-positive reachability problem here, and that is worth
 * saying out loud in a phase about false positives. IPMI 2.0 over LAN is not
 * HTTPS: there is no TCP connection to accept, no certificate for a gateway to
 * present and nothing for a TLS-inspecting proxy to impersonate. An RMCP+
 * session is established by the credential itself, over UDP, and a controller
 * that completes one has both answered and authenticated. So a successful
 * `mc info` is proof of identity and proof of the credential in a single
 * answer, which none of the HTTPS drivers can manage.
 *
 * ===========================================================================
 * AND THE DISADVANTAGE, WHICH IS EVERYTHING ELSE
 * ===========================================================================
 *
 * ipmitool reports failure as unparsed English on stderr and uses the same
 * sentence — "Unable to establish IPMI v2 / RMCP+ session" — for a wrong
 * password and for a controller that is not there. That ambiguity is not
 * resolved here by guessing. A failure carrying an explicit authentication
 * marker is an authentication failure; one carrying an explicit routing marker
 * is a network failure; the generic session error is
 * {@see ConnectionState::NeedsReview}, which says a person has to look — and
 * saying so is more use to an operator at 2am than a confident answer that is
 * right half the time.
 *
 * ===========================================================================
 * THE PASSWORD IS IN ARGV, SO NOTHING FROM THE PROCESS IS EVER KEPT
 * ===========================================================================
 *
 * ipmitool offers no alternative: `-P` is the only non-interactive mechanism,
 * and the environment-variable form merely moves the secret somewhere a crash
 * dump also captures. Two rules follow, and they are the same two the IPMI
 * adapter beside this one enforces:
 *
 *   1. the command is an **array**, handed to the process runner as a list, so
 *      the values reach execve() directly and no shell ever parses them — a
 *      BMC address containing shell metacharacters is data, not code;
 *   2. no output from the process is ever recorded. stderr is matched against
 *      fixed patterns and discarded; the command line is never rendered. The
 *      strings that reach the connection test are written in this file.
 */
final readonly class IpmiConnectionTester implements ConnectionTester
{
    /** IPMI 2.0 over LAN, which is the only interface with encryption. */
    private const string INTERFACE = 'lanplus';

    private const int TIMEOUT_SECONDS = 20;

    /**
     * Sentences ipmitool prints when the controller answered and refused the
     * credential.
     *
     * Each one is a specific RMCP+ or IPMI authentication outcome, not the
     * generic session error — which is the whole point of the list.
     *
     * @var list<string>
     */
    private const array AUTHENTICATION_MARKERS = [
        'rakp 2 hmac is invalid',
        'rakp 2 message indicates an error',
        'unauthorized name',
        'invalid user name',
        'password verification',
        'authentication type',
        'insufficient resources for session',
    ];

    /**
     * Sentences ipmitool prints when nothing was there to answer.
     *
     * @var list<string>
     */
    private const array NETWORK_MARKERS = [
        'no route to host',
        'address lookup',
        'name or service not known',
        'network is unreachable',
        'host not found',
        'connection refused',
    ];

    public function driver(): string
    {
        return 'ipmi';
    }

    public function test(TestTarget $target): ConnectionResult
    {
        $credential = $this->parseCredential($target);

        if ($credential === null) {
            return ConnectionResult::of(
                ConnectionState::CredentialMalformed,
                [ConnectionStep::failed('credential', $target->hasSecret()
                    ? 'the stored credential is not in the shape IPMI takes: the controller account\'s user name, '
                        .'then a colon, then its password. Anonymous access to a controller with power control is '
                        .'not a supported configuration.'
                    : 'no credential is configured for this controller.')],
                $this->allOf($target, CapabilityState::BlockedCredentials),
            );
        }

        $result = $this->run($target, $credential, ['mc', 'info']);

        if ($result === null) {
            /*
             * The binary is not on this controller, or the process could not
             * be started. Neither is a fact about the BMC, and reporting one
             * as unreachable would send an operator to a datacentre over a
             * missing package.
             */
            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                [ConnectionStep::passed('credential', 'the stored credential is in the shape IPMI takes.'),
                    ConnectionStep::failed('identity', 'ipmitool could not be run on this controller, so nothing was asked of the BMC.')],
                $this->allOf($target, CapabilityState::Unknown),
                'ipmitool is not available on this deployment controller, so an IPMI endpoint cannot be tested from '
                .'here. This is a dependency of the controller, not a fault of the machine.',
            );
        }

        $steps = [ConnectionStep::passed('credential', 'the stored credential is in the shape IPMI takes.')];

        if ($result->successful()) {
            return $this->fromController($target, $steps, $result->output());
        }

        // Matched, then discarded: the command line this came from has the
        // password in it, and so may the error text quoting it.
        $said = strtolower($result->errorOutput().' '.$result->output());

        foreach (self::AUTHENTICATION_MARKERS as $marker) {
            if (str_contains($said, $marker)) {
                return ConnectionResult::of(
                    ConnectionState::AuthFailed,
                    [...$steps, ConnectionStep::passed('identity', 'the controller answered the RMCP+ exchange, so a BMC is there.'),
                        ConnectionStep::failed('authenticate', 'the controller refused the account.')],
                    $this->allOf($target, CapabilityState::BlockedCredentials),
                    'The BMC answered and rejected the account. The user name or the password is wrong, or the '
                    .'account is not enabled for IPMI over LAN.',
                );
            }
        }

        foreach (self::NETWORK_MARKERS as $marker) {
            if (str_contains($said, $marker)) {
                return ConnectionResult::of(
                    ConnectionState::NetworkFailed,
                    [...$steps, ConnectionStep::failed('identity', 'nothing answered at that address.')],
                    $this->allOf($target, CapabilityState::BlockedNetwork),
                );
            }
        }

        /*
         * The generic case, and the honest one. "Unable to establish IPMI v2 /
         * RMCP+ session" is what ipmitool prints for a wrong password, for a
         * BMC with IPMI over LAN disabled, for a firewalled port and for a
         * machine that is not plugged in. Choosing between them would be
         * inventing an answer, and every one of the four sends an operator
         * somewhere different.
         */
        return ConnectionResult::of(
            ConnectionState::NeedsReview,
            [...$steps, ConnectionStep::failed('identity', 'no RMCP+ session was established, and IPMI does not say why.')],
            $this->allOf($target, CapabilityState::Unknown),
            'No IPMI session was established. IPMI reports a wrong password, a disabled LAN interface, a blocked port '
            .'and an absent machine with the same message, so this platform will not choose between them. Every '
            .'machine reachable only this way is a machine to put on a list to replace.',
        );
    }

    /**
     * What the controller says about itself.
     *
     * @return array<string, string>
     */
    public function discover(TestTarget $target): array
    {
        $credential = $this->parseCredential($target);

        if ($credential === null) {
            return [];
        }

        $info = $this->run($target, $credential, ['mc', 'info']);

        if ($info === null || ! $info->successful()) {
            return [];
        }

        $fields = $this->fields($info->output());

        $facts = [
            'bmc.vendor' => $fields['manufacturer name'] ?? null,
            'bmc.firmware' => $fields['firmware revision'] ?? null,
            'bmc.ipmi_version' => $fields['ipmi version'] ?? null,
            'bmc.product' => $fields['product name'] ?? null,
        ];

        $power = $this->run($target, $credential, ['chassis', 'power', 'status']);

        if ($power !== null && $power->successful()) {
            // "Chassis Power is on"
            $facts['power.state'] = str_contains(strtolower($power->output()), ' on') ? 'on' : 'off';
        }

        return array_filter($facts, static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * A controller that answered `mc info`, which means it authenticated us.
     *
     * @param  list<ConnectionStep>  $steps
     */
    private function fromController(TestTarget $target, array $steps, string $output): ConnectionResult
    {
        $fields = $this->fields($output);

        /*
         * `Device ID` and `Firmware Revision` are the two fields the IPMI
         * specification requires a Get Device ID response to carry, so
         * requiring both is requiring the specification's own answer. An exit
         * code of zero is not enough on its own: `ipmitool` exits zero for
         * some commands that print nothing useful, and a command that printed
         * nothing has not identified anything.
         */
        if (! isset($fields['device id'], $fields['firmware revision'])) {
            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                [...$steps, ConnectionStep::failed('identity', 'ipmitool exited successfully and did not report a device id, so what answered is not established.')],
                $this->allOf($target, CapabilityState::Unknown),
                'The IPMI command succeeded without reporting a controller. A person should look at it.',
            );
        }

        $vendor = $fields['manufacturer name'] ?? null;

        $steps[] = ConnectionStep::passed('identity', sprintf(
            'a baseboard management controller answered the IPMI device-id command%s, reporting firmware %s.',
            $vendor === null ? '' : ' ('.$vendor.')',
            $fields['firmware revision'],
        ));

        // The session was established with the credential, so authenticating
        // is not a separate thing that could have gone differently.
        $steps[] = ConnectionStep::passed('authenticate', 'an RMCP+ session was established, which the credential is what establishes.');

        $power = $this->run($target, $this->parseCredential($target) ?? [], ['chassis', 'power', 'status']);
        $powerReadable = $power !== null && $power->successful();

        $steps[] = $powerReadable
            ? ConnectionStep::passed('capabilities', 'the chassis power state is readable. Power control and boot '
                .'override stay unknown: the only way to establish them is to use them, and a connection test does '
                .'not power-cycle a customer\'s machine to find out whether it can.')
            : ConnectionStep::failed('capabilities', 'the account authenticated and may not read the chassis power state.');

        return ConnectionResult::of(
            $powerReadable ? ConnectionState::Connected : ConnectionState::ConnectedReadOnly,
            $steps,
            $this->capabilities($target, [
                'inventory' => CapabilityState::Supported,
                'power_state' => $powerReadable ? CapabilityState::Supported : CapabilityState::Unsupported,
                // IPMI has no inventory service worth the name. The adapter's
                // own docblock says so, and claiming firmware inventory here
                // would put a screen in front of an operator that the adapter
                // cannot fill.
                'firmware' => CapabilityState::Unsupported,
            ]),
            $powerReadable
                ? null
                : 'The account is accepted and may not read the chassis. Give it the OPERATOR privilege level on the '
                    .'controller rather than changing its password.',
        );
    }

    /**
     * ipmitool's "Key : Value" output, lowercased on the key.
     *
     * Every value passes {@see IdentityProof::token()}, because this is output
     * from a binary parsing a response from a device an operator typed an
     * address for, and the strings it produces are persisted and shown.
     *
     * @return array<string, string>
     */
    private function fields(string $output): array
    {
        $fields = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);

            $key = strtolower(trim($key));
            $clean = IdentityProof::token(trim($value));

            if ($key !== '' && $clean !== null) {
                $fields[$key] = $clean;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, string>|null
     */
    private function parseCredential(TestTarget $target): ?array
    {
        if (! $target->hasSecret()) {
            return null;
        }

        if (preg_match('/^(?<user>[^\s:]{1,64}):(?<password>.+)$/s', (string) $target->secret, $parts) !== 1) {
            return null;
        }

        return ['user' => $parts['user'], 'password' => $parts['password']];
    }

    /**
     * Run one ipmitool command, or null if it could not be run at all.
     *
     * @param  array<string, string>  $credential
     * @param  list<string>  $arguments
     */
    private function run(TestTarget $target, #[SensitiveParameter] array $credential, array $arguments): ?ProcessResult
    {
        if ($credential === []) {
            return null;
        }

        [$host, $port] = $this->hostAndPort((string) $target->endpoint);

        $command = ['ipmitool', '-I', self::INTERFACE, '-H', $host];

        if ($port !== null) {
            $command[] = '-p';
            $command[] = $port;
        }

        /*
         * An array, never an interpolated string, so every value is handed to
         * execve() directly and no shell parses it. The address and the user
         * name are whatever an operator or a discovery job wrote; a hostname
         * containing shell metacharacters would otherwise be arbitrary command
         * execution as the queue worker, which holds the credentials for every
         * BMC in the fleet.
         */
        $command = [...$command, '-U', $credential['user'], '-P', $credential['password'], ...$arguments];

        try {
            return Process::timeout(self::TIMEOUT_SECONDS)->run($command);
        } catch (Throwable) {
            /*
             * Including the timeout. A read that ran out of time tells us
             * nothing, and the exception's own message is never used for the
             * reason this whole file exists: it stringifies the command line,
             * and the command line has the password in it.
             */
            return null;
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function hostAndPort(string $address): array
    {
        /*
         * Already checked by the endpoint policy, which validates the port and
         * refuses loopback, link-local, multicast and the metadata services.
         *
         * A scheme is stripped rather than refused because a BMC provider row
         * carries a full HTTPS URL — assertProviderEndpoint demands one — while
         * a managed server carries a bare address. ipmitool takes neither a
         * scheme nor a path, and IPMI is not HTTP at all, so what is wanted
         * here is the authority whichever door the address arrived through.
         */
        $address = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $address);
        $address = explode('/', $address)[0];

        if (preg_match('/^\[(?<host>[0-9A-Fa-f:.]+)\](?::(?<port>\d{1,5}))?$/', $address, $parts) === 1) {
            return [$parts['host'], ($parts['port'] ?? '') === '' ? null : $parts['port']];
        }

        if (substr_count($address, ':') === 1) {
            [$host, $port] = explode(':', $address, 2);

            return [$host, $port];
        }

        return [$address, null];
    }

    /**
     * @return array<string, CapabilityState>
     */
    private function allOf(TestTarget $target, CapabilityState $state): array
    {
        return array_fill_keys($target->probeCapabilities, $state);
    }

    /**
     * @param  array<string, CapabilityState>  $known
     * @return array<string, CapabilityState>
     */
    private function capabilities(TestTarget $target, array $known): array
    {
        $states = $this->allOf($target, CapabilityState::Unknown);

        foreach ($known as $capability => $state) {
            if (array_key_exists($capability, $states)) {
                $states[$capability] = $state;
            }
        }

        return $states;
    }
}
