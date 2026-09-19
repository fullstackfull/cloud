<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Http\Client\Response;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use SensitiveParameter;

/**
 * Proxmox VE, identified by its own API and not by the fact that something
 * answered.
 *
 * ===========================================================================
 * WHAT COUNTS AS PROOF
 * ===========================================================================
 *
 * Proxmox serves its API under `/api2/json` and wraps every answer in a
 * `data` envelope. `/version` returns a `data` object carrying `version`,
 * `release` and `repoid`. A load balancer error page does not; a captive
 * portal does not; the egress gateway that answers a trusted TLS handshake for
 * every hostname on earth — see {@see IdentityProof} — does not.
 *
 * ===========================================================================
 * PROXMOX VE IS NOT PROXMOX BACKUP SERVER
 * ===========================================================================
 *
 * And both answer `/api2/json/version` with the same envelope, which makes
 * this the exact case a status code cannot tell apart. A PVE cluster has a
 * `/nodes` collection; a Backup Server does not. So the second request is not
 * decoration: it is what separates "a Proxmox product answered" from "the
 * Proxmox product this row claims to be answered", and a Backup Server
 * registered as a compute provider is reported as a mismatch rather than
 * onboarded as a cluster that will refuse every virtual machine it is asked
 * for.
 *
 * ===========================================================================
 * PRIVILEGES ARE READ, NOT ASSUMED
 * ===========================================================================
 *
 * `/access/permissions` tells us what this token may do, without doing any of
 * it. That is the whole difference between a connection test and a
 * verification: the platform can learn that a token holds `VM.PowerMgmt`
 * without powering anything off, and it cannot learn that a VM will actually
 * build without building one. So a privilege the token holds makes a
 * capability Supported, a privilege it provably lacks makes that capability
 * Unsupported, and anything the privilege list cannot settle stays Unknown.
 *
 * Only an API token is accepted, matching {@see ProxmoxConnection}:
 * there is deliberately no code path in this platform that logs into Proxmox
 * with a username and password.
 */
class ProxmoxConnectionTester extends HttpIdentityTester
{
    /**
     * Proxmox privilege → what holding it proves, and what lacking it
     * disproves.
     *
     * Read as: this capability is offered if and only if the token holds this
     * privilege somewhere. Capabilities absent from this map stay Unknown,
     * because no privilege settles them on its own — `reinstall` needs both an
     * allocation privilege and a working template, and the template half
     * cannot be established without building something.
     *
     * @var array<string, string>
     */
    private const array PRIVILEGES = [
        'create' => 'VM.Allocate',
        'destroy' => 'VM.Allocate',
        'start' => 'VM.PowerMgmt',
        'stop' => 'VM.PowerMgmt',
        'reboot' => 'VM.PowerMgmt',
        'suspend' => 'VM.PowerMgmt',
        'unsuspend' => 'VM.PowerMgmt',
        'console' => 'VM.Console',
        'resize' => 'VM.Config.Disk',
    ];

    public function __construct(string $driver = 'proxmox')
    {
        parent::__construct($driver);
    }

    protected function baseUrl(TestTarget $target): string
    {
        return rtrim((string) $target->endpoint, '/').'/api2/json';
    }

    protected function identityPath(): string
    {
        return '/version';
    }

    protected function credentialShape(): string
    {
        return 'a Proxmox API token, stored as the token identifier and its secret joined by an equals sign: '
            .'user@realm!tokenname followed by = followed by the secret. Username and password authentication is '
            .'not supported by this platform.';
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseCredential(TestTarget $target): ?array
    {
        /*
         * The token's own native form, which is also exactly what goes into
         * the header, so there is no reassembly step in which the two halves
         * could be put back together wrongly.
         */
        if (preg_match('/^(?<id>[^\s!=]+![^\s!=]+)=(?<secret>\S+)$/', (string) $target->secret, $parts) !== 1) {
            return null;
        }

        return ['id' => $parts['id'], 'secret' => $parts['secret']];
    }

    /**
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function headers(#[SensitiveParameter] array $credential): array
    {
        return ['Authorization' => sprintf('PVEAPIToken=%s=%s', $credential['id'], $credential['secret'])];
    }

    protected function identify(Response $response): IdentityProof
    {
        $body = $this->envelope($response);

        if ($body === null) {
            return IdentityProof::notThisProduct(
                'the endpoint answered, and the answer is not a Proxmox API response: Proxmox wraps every answer '
                .'in a JSON object with a "data" key, and this had none.'
            );
        }

        if ($response->unauthorized() || $response->forbidden()) {
            /*
             * The narrowest evidence in this file, and it is deliberately
             * narrow. A bare 401 from an unidentified host proves nothing —
             * every proxy, portal and default vhost on earth can produce one —
             * so what is required is that the refusal arrived inside Proxmox's
             * own envelope. Anything that merely returns 401 with an HTML body
             * falls through to a mismatch below, which is the honest answer:
             * the platform does not know what refused it.
             */
            return IdentityProof::credentialRejected(
                'a Proxmox API envelope refused the credential. The token, its secret, or its permissions on this '
                .'cluster are wrong.'
            );
        }

        if (! $response->successful() || ! is_array($body)) {
            return IdentityProof::notThisProduct(
                'the endpoint answered with a Proxmox-shaped envelope and no version, so what it is cannot be established.'
            );
        }

        $version = IdentityProof::token($body['version'] ?? null);
        $release = IdentityProof::token($body['release'] ?? null);
        $repository = IdentityProof::token($body['repoid'] ?? null);

        if ($version === null || preg_match('/^\d+\.\d/', $version) !== 1) {
            return IdentityProof::notThisProduct(
                'the endpoint answered a Proxmox-shaped envelope with no version number in it. A Proxmox product '
                .'reports its version here; something answering for one does not.'
            );
        }

        /*
         * A version inside a `data` key is not enough, and the negative matrix
         * in the test suite proved it rather than a reviewer noticing: WHM
         * answers its own version command with
         * `{"metadata":{...},"data":{"version":"11.126.0.4"}}`, which has a
         * `data` object carrying a `version` that matches the pattern above.
         * A cPanel node registered under the proxmox driver was therefore
         * identified as a Proxmox cluster and reported read-only-connected.
         *
         * Proxmox's own answer carries the build it came from as well as the
         * version — `release`, `repoid`, or both, on PVE and on Backup Server
         * alike. Requiring one of them is requiring something documented that
         * only this product reports, which is what identification means.
         */
        if ($release === null && $repository === null) {
            return IdentityProof::notThisProduct(
                'the endpoint answered a JSON object with a "data" key and a version in it, and did not report the '
                .'release or the repository the build came from. Proxmox reports those; other products with a "data" '
                .'envelope and a version — WHM among them — do not, and a version in a data key is a shape rather '
                .'than an identity.'
            );
        }

        return IdentityProof::of(sprintf(
            'the Proxmox API reported version %s%s.',
            $version,
            $release === null ? '' : ' (release '.$release.')',
        ));
    }

    protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult
    {
        $nodes = $probe->get('/nodes');

        if ($nodes->notFound()) {
            /*
             * A Proxmox product with no /nodes collection is a Backup Server.
             * Reported as the mismatch it is: the credential is fine, the
             * endpoint is fine, and this row is pointed at the wrong one of
             * the two Proxmox products.
             */
            $probe->failed('identity', 'this Proxmox endpoint has no /nodes collection, so it is a Proxmox Backup Server rather than a Proxmox VE cluster.');

            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The endpoint is a Proxmox Backup Server, not a Proxmox VE cluster. Register it under the proxmox_backup driver.',
            );
        }

        if ($nodes->forbidden() || $nodes->unauthorized()) {
            $probe->failed('authorise', 'the token authenticated and may not read the cluster\'s node list.');

            return ConnectionResult::of(
                ConnectionState::PermissionInsufficient,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The token is accepted and holds no audit privilege on the cluster. Grant it a role with Sys.Audit rather than issuing a new token.',
            );
        }

        if ($nodes->serverError()) {
            $probe->failed('authorise', 'the cluster answered its own node list with a server error.');

            return ConnectionResult::of(
                ConnectionState::ProviderUnavailable,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The cluster is identified and is failing its own requests. This is the cluster\'s problem to recover from, not a credential to rotate.',
            );
        }

        $list = $nodes->json('data');

        if (! is_array($list)) {
            $probe->failed('authorise', 'the node list did not arrive as a list.');

            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The cluster answered its node list in a shape this platform does not understand. A person should look at it.',
            );
        }

        $probe->passed('authorise', sprintf('the token may read the cluster: %d node(s) are visible.', count($list)));

        return $this->fromPrivileges($probe, $target, count($list));
    }

    /**
     * What the token may do, read from the cluster's own permission list.
     */
    private function fromPrivileges(Probe $probe, TestTarget $target, int $nodeCount): ConnectionResult
    {
        $permissions = $probe->get('/access/permissions');

        if (! $permissions->successful() || ! is_array($permissions->json('data'))) {
            /*
             * Connected, and nothing claimed about what it can do. A cluster
             * that will not report its own permissions has not said no to
             * anything; it has said nothing, and Unknown is what that means.
             */
            $probe->passed('capabilities', 'the cluster did not report this token\'s privileges, so what it may do is unknown until an operation is attempted.');

            return ConnectionResult::of(
                ConnectionState::Connected,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                $nodeCount === 0 ? 'The cluster is reachable and reports no nodes.' : null,
            );
        }

        $held = $this->privilegesIn((array) $permissions->json('data'));

        $states = [];

        foreach (self::PRIVILEGES as $capability => $privilege) {
            $states[$capability] = in_array($privilege, $held, strict: true)
                ? CapabilityState::Supported
                : CapabilityState::Unsupported;
        }

        /*
         * Reading the task list is a read, so unlike every write above it can
         * be established without doing anything. It is the capability the
         * whole asynchronous provisioning path depends on, so it is worth the
         * extra request.
         */
        $states['task_polling'] = $nodeCount > 0 && in_array('Sys.Audit', $held, strict: true)
            ? CapabilityState::Supported
            : CapabilityState::Unknown;

        $writeable = array_filter(
            $states,
            static fn (CapabilityState $state): bool => $state === CapabilityState::Supported,
        );

        $canWrite = array_intersect_key($writeable, array_flip(['create', 'start', 'stop', 'reboot', 'resize', 'destroy', 'suspend', 'unsuspend'])) !== [];

        $probe->passed('capabilities', sprintf(
            'the cluster reported this token\'s privileges; %d of the capabilities asked about are offered.',
            count($writeable),
        ));

        return ConnectionResult::of(
            $canWrite ? ConnectionState::Connected : ConnectionState::ConnectedReadOnly,
            $probe->steps,
            $this->capabilities($target, $states),
            $canWrite
                ? null
                : 'The token is accepted and holds no privilege that changes a virtual machine. Correct for an audit token and for a cluster being onboarded read-only.',
        );
    }

    /**
     * Every privilege the token holds anywhere, flattened.
     *
     * Proxmox answers per path — `/`, `/vms/101`, `/storage/local` — and what
     * matters to a capability question is whether the privilege is held at
     * all. A token that may power on exactly one machine can power on a
     * machine, and the per-machine detail belongs to the operation rather than
     * to the account's capability list.
     *
     * @param  array<array-key, mixed>  $data
     * @return list<string>
     */
    private function privilegesIn(array $data): array
    {
        $held = [];

        foreach ($data as $privileges) {
            if (! is_array($privileges)) {
                continue;
            }

            foreach ($privileges as $privilege => $granted) {
                if (is_string($privilege) && (bool) $granted) {
                    $held[$privilege] = true;
                }
            }
        }

        return array_keys($held);
    }

    /**
     * The contents of Proxmox's `data` envelope, or null when there is no
     * envelope at all.
     *
     * Null and an empty envelope are different answers and the caller needs
     * both: no envelope means this is not a Proxmox API, while an envelope
     * holding null is what Proxmox itself returns when it refuses a request.
     */
    private function envelope(Response $response): mixed
    {
        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('data', $body)) {
            return null;
        }

        return $body['data'] ?? [];
    }
}
