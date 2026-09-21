<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;

/**
 * How to reach a machine's baseboard controller, written down.
 *
 * ---------------------------------------------------------------------------
 * The most dangerous row an operator can create
 * ---------------------------------------------------------------------------
 *
 * A BMC address is dialled by the platform, with credentials, to do things no
 * customer-facing path can do: power a machine, mount media, reinstall it. So
 * the address goes through the same policy every other outbound configuration
 * does — `assertMachineAddress`, which is what the connection testers already
 * use — and it is checked here, at the moment it is written, rather than only
 * at the moment it is dialled. A row that cannot be dialled safely should not
 * exist, and refusing it at the form is the difference between an operator
 * seeing a validation message and a worker seeing an SSRF.
 *
 * The policy is not relaxed for this path. A BMC is on the management network,
 * so private addressing is expected and allowed; loopback, link-local and the
 * cloud metadata services are refused, and there is no flag here that turns
 * that off.
 *
 * `username` is the account name, which is not a secret and is on the row
 * already. The password is not: it is a `credentials_reference` the secret
 * resolver looks up, and nothing on this path accepts a value.
 *
 * Writing it down is not reaching it. `last_contacted_at` stays null until
 * something actually talks to the controller.
 */
final readonly class RecordBmcEndpoint
{
    public function __construct(
        private RecordActAtomically $record,
        private EndpointPolicy $endpoints,
        private Application $app,
    ) {}

    /**
     * @throws EndpointRefused
     */
    public function execute(
        DedicatedServer $server,
        BmcProtocol $protocol,
        string $address,
        ?int $port,
        ?string $username,
        bool $verifyTls,
        ?string $credentialsReference,
        User $operator,
    ): BmcEndpoint {
        $this->endpoints->assertMachineAddress(
            $port === null ? $address : $address.':'.$port,
            production: $this->app->environment('production'),
        );

        return $this->record->execute(
            act: fn (): BmcEndpoint => BmcEndpoint::query()->updateOrCreate(
                // One controller per machine: a second row would be a second
                // answer to "where is this machine's BMC", and the power path
                // would pick one of them.
                ['dedicated_server_id' => $server->getKey()],
                [
                    'protocol' => $protocol,
                    'address' => $address,
                    'port' => $port,
                    'username' => $username,
                    'verify_tls' => $verifyTls,
                    'credentials_reference' => $credentialsReference,
                ],
            ),
            describe: fn (BmcEndpoint $endpoint): AuditedAct => new AuditedAct(
                action: AuditAction::BmcEndpointRecorded,
                subject: $endpoint,
                context: [
                    'serial' => $server->serial,
                    'protocol' => $protocol->value,
                    'credentials_reference' => $credentialsReference,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
