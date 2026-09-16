<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IloDedicatedProvider;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use SensitiveParameter;

/**
 * Redfish, and HPE iLO, which is Redfish with a vendor extension.
 *
 * ===========================================================================
 * WHAT COUNTS AS PROOF
 * ===========================================================================
 *
 * A Redfish service root declares the version of the specification it
 * implements — `RedfishVersion` — beside an `@odata.id` naming itself. Two
 * properties from a published standard, in a document served at a path the
 * standard reserves, is the strongest identity evidence of any driver in this
 * platform. And the service root is readable without authentication, which
 * means the product can be identified *before* anything is concluded about the
 * credential: an HTTP 401 from `/redfish/v1/Systems` after a service root that
 * declared itself is an authentication failure that has actually been
 * established, rather than a 401 from an unknown host being read as one.
 *
 * ===========================================================================
 * AN iLO IS A REDFISH SERVICE AND A REDFISH SERVICE IS NOT AN iLO
 * ===========================================================================
 *
 * The asymmetry is deliberate. A machine registered under the `redfish` driver
 * is served correctly by any conforming controller, HPE's included. A machine
 * registered under `ilo` is registered that way because something in this
 * platform means to use HPE's extensions — {@see IloDedicatedProvider}
 * reads firmware from `/redfish/v1/Managers/1/UpdateService/FirmwareInventory`,
 * which is HPE's path and not the standard's. Pointing that driver at a Dell
 * or a Supermicro controller produces a machine that passes its connection
 * test and fails every operation that matters, so the vendor is checked and a
 * mismatch is reported as one.
 *
 * ===========================================================================
 * WHAT IS READ, AND WHAT IS LEFT UNKNOWN
 * ===========================================================================
 *
 * Inventory and power *state* are reads, so they are established. Power
 * *control* and boot override are writes: the only way to find out whether
 * this account may reset a chassis is to reset it, and a connection test that
 * power-cycled a customer's dedicated server to see whether it could would be
 * the single worst thing in this codebase.
 */
class RedfishConnectionTester extends HttpIdentityTester
{
    /**
     * Where a Redfish service must answer, per DSP0266.
     */
    private const string SERVICE_ROOT = '/redfish/v1/';

    /** How many BMCs answer their service root on something other than 443. Enough to parse one. */
    private const int DEFAULT_PORT = 443;

    public function __construct(string $driver = 'redfish')
    {
        parent::__construct($driver);
    }

    protected function baseUrl(TestTarget $target): string
    {
        $endpoint = trim((string) $target->endpoint);

        /*
         * A controller reaches this tester by two doors, and they hand over
         * different shapes.
         *
         * A managed server's test passes the machine's BMC address — a
         * hostname or an IP, optionally with a port — because that is what a
         * server row holds, and EndpointPolicy::assertMachineAddress is what
         * checked it. A BMC *provider row*'s test passes a full HTTPS URL,
         * because assertProviderEndpoint demands a scheme and a host. Both are
         * real paths in this platform, so both are accepted here rather than
         * one of them being declared wrong.
         *
         * Either way the scheme is HTTPS and there is no switch to add a
         * plaintext variant: Redfish authenticates with HTTP Basic, so an
         * unencrypted request would put the credential for a physical machine
         * on the wire in base64.
         */
        if (preg_match('#^https://#i', $endpoint) === 1) {
            return rtrim($endpoint, '/');
        }

        return 'https://'.$this->authority($endpoint);
    }

    protected function identityPath(): string
    {
        return self::SERVICE_ROOT;
    }

    protected function credentialShape(): string
    {
        return 'a controller account, stored as the user name and the password joined by a colon: the user, then a '
            .'colon, then the password. Anonymous access to a controller with power control is not a supported '
            .'configuration.';
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseCredential(TestTarget $target): ?array
    {
        /*
         * The password may contain a colon, so the split is on the first one
         * only. A user name may not, which is what makes the first colon the
         * right boundary rather than a guess.
         */
        if (preg_match('/^(?<user>[^\s:]{1,64}):(?<password>.+)$/s', (string) $target->secret, $parts) !== 1) {
            return null;
        }

        return ['user' => $parts['user'], 'password' => $parts['password']];
    }

    /**
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function headers(#[SensitiveParameter] array $credential): array
    {
        return ['Authorization' => 'Basic '.base64_encode($credential['user'].':'.$credential['password'])];
    }

    protected function identify(Response $response): IdentityProof
    {
        $body = $response->json();

        if (is_array($body) && $this->isRedfishError($body)) {
            /*
             * A Redfish error object — an `error.code` in the standard's
             * `Base.x.y` registry namespace, or its `@Message.ExtendedInfo`
             * array. A controller that protects its service root answers this
             * way, and the answer identifies the product while refusing us,
             * which is precisely the pair IdentityProof exists to keep apart
             * from a bare 401 nobody can attribute.
             */
            return $response->unauthorized() || $response->forbidden()
                ? IdentityProof::credentialRejected('a Redfish error response refused the credential, so the controller is a Redfish service.')
                : IdentityProof::of('the controller answered with a Redfish error object.');
        }

        if (! is_array($body)) {
            return IdentityProof::notThisProduct(
                'the endpoint answered, and the answer is not a Redfish service root: Redfish serves a JSON document '
                .'at /redfish/v1/ and this was not JSON.'
            );
        }

        $version = IdentityProof::token($body['RedfishVersion'] ?? null);

        if ($version === null || ! isset($body['@odata.id'])) {
            return IdentityProof::notThisProduct(
                'the endpoint answered JSON at /redfish/v1/ that declares no RedfishVersion and no @odata.id, so it '
                .'is not a Redfish service root.'
            );
        }

        $vendor = $this->vendorIn($body);

        return IdentityProof::of(sprintf(
            'a Redfish service root declared specification version %s%s.',
            $version,
            $vendor === null ? '' : ', extended by '.$vendor,
        ));
    }

    protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult
    {
        /** @var array<string, mixed> $root */
        $root = (array) $identity->json();

        $systems = $probe->get(self::SERVICE_ROOT.'Systems');

        if ($systems->unauthorized()) {
            /*
             * The clean case, and the reason the service root is read first.
             * The product is established by the standard's own document; the
             * refusal is established by the standard's own status code. This
             * is an authentication failure that has been proven rather than
             * inferred from a 401 nobody can attribute.
             */
            $probe->failed('authenticate', 'the controller identified itself and refused the account.');

            return ConnectionResult::of(
                ConnectionState::AuthFailed,
                $probe->steps,
                $this->allOf($target, CapabilityState::BlockedCredentials),
                'The controller is a Redfish service and rejected the account. The user name or the password is wrong.',
            );
        }

        $probe->passed('authenticate', 'the controller accepted the account.');

        /*
         * The vendor is checked against the service root before the chassis is
         * read, so a controller with an empty systems collection is still
         * caught. It is checked again below with the system in hand, because
         * the system reports a manufacturer the root often does not.
         */
        $fromRoot = $this->vendorMismatch($root, []);

        if ($fromRoot !== null) {
            $probe->failed('identity', $fromRoot);

            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                $fromRoot,
            );
        }

        if ($systems->forbidden()) {
            $probe->failed('authorise', 'the account authenticated and may not read the chassis inventory.');

            return ConnectionResult::of(
                ConnectionState::PermissionInsufficient,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The account is accepted and holds no privilege to read the systems collection. Grant it the '
                .'controller\'s read-only role rather than changing its password.',
            );
        }

        $member = $this->firstSystem($probe, $systems);

        if ($member === null) {
            $probe->passed('capabilities', 'the controller reports no system in its systems collection, so nothing about the chassis could be read.');

            return ConnectionResult::of(
                ConnectionState::ConnectedReadOnly,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The controller is reachable and reports no system. A chassis with no system is worth a look before '
                .'anything is provisioned onto it.',
            );
        }

        $vendorMismatch = $this->vendorMismatch($root, $member);

        if ($vendorMismatch !== null) {
            $probe->failed('identity', $vendorMismatch);

            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                $vendorMismatch,
            );
        }

        $probe->passed('authorise', 'the account may read the chassis inventory.');

        return $this->fromChassis($probe, $target, $member);
    }

    /**
     * What a chassis will let us do, from what it let us read.
     *
     * @param  array<string, mixed>  $system
     */
    private function fromChassis(Probe $probe, TestTarget $target, array $system): ConnectionResult
    {
        $states = ['inventory' => CapabilityState::Supported];

        $power = IdentityProof::token($system['PowerState'] ?? null);

        $states['power_state'] = $power === null ? CapabilityState::Unknown : CapabilityState::Supported;

        $states['firmware'] = $this->firmwareIsReadable($probe)
            ? CapabilityState::Supported
            : CapabilityState::Unsupported;

        $probe->passed('capabilities', sprintf(
            'the chassis inventory is readable%s. Power control and boot override stay unknown: the only way to '
            .'establish them is to use them, and a connection test does not reset a customer\'s machine to find out '
            .'whether it can.',
            $power === null ? '' : ' and reports power '.$power,
        ));

        return ConnectionResult::of(
            ConnectionState::Connected,
            $probe->steps,
            $this->capabilities($target, $states),
        );
    }

    /**
     * What the machine says about itself.
     *
     * Every value passes {@see IdentityProof::token()} before it is kept, and
     * a value that reduces to nothing is left out rather than recorded as
     * empty: a blank serial number on a screen reads as a fact about the
     * machine, and it is a fact about this method.
     *
     * @return array<string, string>
     */
    public function discover(TestTarget $target): array
    {
        $credential = $target->hasSecret() ? $this->parseCredential($target) : null;

        if ($credential === null) {
            return [];
        }

        try {
            $result = $this->test($target);

            if (! $result->state->usable()) {
                return [];
            }

            $probe = new Probe($this->request($target, $credential));

            $systems = $probe->get(self::SERVICE_ROOT.'Systems');
            $system = $this->firstSystem($probe, $systems);

            if ($system === null) {
                return [];
            }

            $root = $probe->get(self::SERVICE_ROOT)->json();

            $facts = [
                'vendor' => IdentityProof::token($system['Manufacturer'] ?? null),
                'model' => IdentityProof::token($system['Model'] ?? null),
                'serial' => IdentityProof::token($system['SerialNumber'] ?? null),
                'bios.version' => IdentityProof::token($system['BiosVersion'] ?? null),
                'power.state' => IdentityProof::token($system['PowerState'] ?? null),
                'cpu.model' => IdentityProof::token(is_array($system['ProcessorSummary'] ?? null) ? ($system['ProcessorSummary']['Model'] ?? null) : null),
                'cpu.count' => IdentityProof::token(is_array($system['ProcessorSummary'] ?? null) ? ($system['ProcessorSummary']['Count'] ?? null) : null),
                'memory.total_gib' => IdentityProof::token(is_array($system['MemorySummary'] ?? null) ? ($system['MemorySummary']['TotalSystemMemoryGiB'] ?? null) : null),
                'redfish.version' => IdentityProof::token(is_array($root) ? ($root['RedfishVersion'] ?? null) : null),
            ];

            return array_filter($facts, static fn (?string $value): bool => $value !== null);
        } catch (RedirectRefused|ConnectionException) {
            return [];
        }
    }

    /**
     * The first member of the systems collection, fetched.
     *
     * @return array<string, mixed>|null
     */
    private function firstSystem(Probe $probe, Response $systems): ?array
    {
        $members = $systems->json('Members');

        if (! is_array($members) || $members === []) {
            return null;
        }

        $first = $members[array_key_first($members)];
        $path = is_array($first) ? ($first['@odata.id'] ?? null) : null;

        /*
         * The collection's own link, and only if it is a path on this same
         * controller. A Redfish body is data from a device an operator typed
         * an address for, so an `@odata.id` that is an absolute URL would be
         * this method following a location the far end chose — the same
         * mistake as following a redirect, arriving by a different door.
         */
        if (! is_string($path) || ! str_starts_with($path, self::SERVICE_ROOT)) {
            return null;
        }

        $system = $probe->get($path)->json();

        return is_array($system) ? $system : null;
    }

    private function firmwareIsReadable(Probe $probe): bool
    {
        return $probe->get(self::SERVICE_ROOT.'UpdateService/FirmwareInventory')->successful();
    }

    /**
     * The vendor extension a service root declares, if any.
     *
     * @param  array<string, mixed>  $root
     */
    private function vendorIn(array $root): ?string
    {
        $vendor = IdentityProof::token($root['Vendor'] ?? null);

        if ($vendor !== null) {
            return $vendor;
        }

        $oem = $root['Oem'] ?? null;

        if (! is_array($oem) || $oem === []) {
            return null;
        }

        return IdentityProof::token((string) array_key_first($oem));
    }

    /**
     * Why this controller is not the vendor this driver is for, or null.
     *
     * Only the `ilo` driver can produce a mismatch here: a machine registered
     * as generic Redfish is served correctly by any conforming controller,
     * HPE's included, so there is nothing for it to mismatch against.
     *
     * The check is positive-evidence-only in one direction. A controller whose
     * system reports a manufacturer that is not HPE is a mismatch, because
     * that is a fact. A controller that reports no manufacturer at all is not
     * called a mismatch, because an absent property is not evidence of
     * anything — and refusing to onboard a machine over a field its firmware
     * declined to fill would be this platform inventing a requirement.
     *
     * @param  array<string, mixed>  $root
     * @param  array<string, mixed>  $system
     */
    private function vendorMismatch(array $root, array $system): ?string
    {
        if ($this->driver() !== 'ilo') {
            return null;
        }

        $named = strtolower(implode(' ', array_filter([
            $this->vendorIn($root),
            IdentityProof::token($system['Manufacturer'] ?? null),
            is_array($system['Oem'] ?? null) ? (string) array_key_first($system['Oem']) : null,
        ], static fn (?string $value): bool => $value !== null)));

        if ($named === '') {
            return null;
        }

        if (preg_match('/\b(hp|hpe|hewlett)/', $named) === 1) {
            return null;
        }

        return 'this is a conforming Redfish controller and it is not an HPE iLO, so the HPE-specific paths this '
            .'driver uses would not be there. Register the machine under the redfish driver instead.';
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isRedfishError(array $body): bool
    {
        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return false;
        }

        return isset($error['@Message.ExtendedInfo'])
            || preg_match('/^Base\.\d/', (string) ($error['code'] ?? '')) === 1;
    }

    /**
     * Host and port, with an unbracketed IPv6 literal bracketed.
     *
     * The address has already been through the endpoint policy, which refuses
     * loopback, link-local, multicast and the cloud metadata services, and
     * which validates the port. What is left here is assembling a URL that
     * cURL will parse the same way that policy read it.
     */
    private function authority(string $address): string
    {
        if (str_starts_with($address, '[')) {
            return $address;
        }

        // More than one colon and no brackets is a bare IPv6 literal, whose
        // colons are part of the address rather than a port separator.
        if (substr_count($address, ':') > 1) {
            return '['.$address.']';
        }

        return str_contains($address, ':') ? $address : $address.':'.self::DEFAULT_PORT;
    }
}
