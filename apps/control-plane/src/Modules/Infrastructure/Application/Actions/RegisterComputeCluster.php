<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;

/**
 * A hypervisor cluster, written down.
 *
 * VmTemplateController began with `ComputeCluster::findOrFail` and no writer
 * existed, so the image catalogue — written, routed and tested — could not be
 * reached on a fresh deployment. This is the row it was looking for.
 *
 * ---------------------------------------------------------------------------
 * Written down is not reached
 * ---------------------------------------------------------------------------
 *
 * Nothing here dials the endpoint. The row is created with `last_synced_at`
 * null and stays that way until the reconciler actually talks to the cluster,
 * which is the distinction the whole platform is arranged around: configured
 * is a statement by an operator, verified is a statement by a provider, and
 * collapsing the two is how a platform sells capacity on a cluster that does
 * not answer.
 *
 * The endpoint is still checked, by the policy every other outbound
 * configuration is checked by. A cluster is our own hardware, so private
 * addressing is expected and allowed; loopback, link-local and the cloud
 * metadata services are not, and a Create button is not a reason to relax
 * that — a form that can write `169.254.169.254` into a field something later
 * dials is an SSRF with a nice label on it.
 *
 * The credential is a reference, never a value: `credentials_reference` names
 * an entry the secret resolver looks up at the moment a request is made. A
 * token in this table is a token in every backup and every support export of
 * a cluster listing.
 */
final readonly class RegisterComputeCluster
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
        Datacenter $datacenter,
        string $slug,
        string $name,
        ComputeDriver $driver,
        ?string $apiEndpoint,
        bool $verifyTls,
        ?string $credentialsReference,
        User $operator,
    ): ComputeCluster {
        if ($apiEndpoint !== null && trim($apiEndpoint) !== '') {
            $this->endpoints->assertProviderEndpoint(
                $apiEndpoint,
                controlledDriver: $driver === ComputeDriver::Fake,
                // A hypervisor cluster is on our own management network.
                onOurHardware: true,
                production: $this->app->environment('production'),
            );
        }

        return $this->record->execute(
            act: fn (): ComputeCluster => ComputeCluster::query()->create([
                'datacenter_id' => $datacenter->getKey(),
                'slug' => $slug,
                'name' => $name,
                'driver' => $driver,
                'api_endpoint' => $apiEndpoint,
                'verify_tls' => $verifyTls,
                'credentials_reference' => $credentialsReference,
                'status' => ClusterStatus::Active,
            ]),
            describe: fn (ComputeCluster $cluster): AuditedAct => new AuditedAct(
                action: AuditAction::ComputeClusterRegistered,
                subject: $cluster,
                context: [
                    'slug' => $cluster->slug,
                    'driver' => $cluster->driver->value,
                    'datacenter' => $datacenter->slug,
                    // The reference, which is a name in configuration, never
                    // the secret it resolves to.
                    'credentials_reference' => $credentialsReference,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
