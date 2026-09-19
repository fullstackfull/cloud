<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Http\Client\Response;
use Lynomia\Modules\Backups\Infrastructure\Providers\ProxmoxBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;

/**
 * The backup path, which is a Proxmox VE cluster with somewhere to write to.
 *
 * ===========================================================================
 * WHY THIS TESTS A HYPERVISOR AND NOT A BACKUP SERVER
 * ===========================================================================
 *
 * Because that is what the adapter talks to. {@see ProxmoxBackupProvider}
 * builds a {@see ProxmoxConnection}
 * and calls `/nodes/{node}/vzdump`, `/nodes/{node}/qemu` and
 * `/nodes/{node}/storage/{datastore}/content` — every one of them a Proxmox VE
 * endpoint. The datastore itself lives on a Proxmox Backup Server, and it is
 * declared and applied by the `pbs` Ansible role rather than by this platform.
 *
 * Writing this tester against a Backup Server's own API would have been
 * testing something the platform never calls: a credential could pass and
 * every backup still fail. The repository's truth decides what is tested, not
 * the name of the driver.
 *
 * ===========================================================================
 * THE SECOND HALF OF THE IDENTITY
 * ===========================================================================
 *
 * A PVE cluster with no Proxmox Backup Server storage attached is the right
 * product and the wrong configuration: every capability this driver exists for
 * would fail, and it would fail at the moment a customer asked for a restore.
 * So the storage list is read, and a cluster with no `pbs` storage comes back
 * Connected with its backup capabilities **Unsupported** — which is the
 * accurate statement, and the one the readiness engine turns into a blocker on
 * the products that need backups rather than into a false green.
 */
final class ProxmoxBackupConnectionTester extends ProxmoxConnectionTester
{
    public function __construct()
    {
        parent::__construct('proxmox_backup');
    }

    protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult
    {
        $nodes = $probe->get('/nodes');

        if ($nodes->notFound()) {
            /*
             * The mirror image of the compute tester's check, and the reason
             * both exist. This row says "back up through a PVE cluster" and
             * the endpoint is a Backup Server's own API, which this platform
             * has no adapter for. Saying so is more use than a credential
             * error an operator would spend an afternoon on.
             */
            $probe->failed('identity', 'this Proxmox endpoint has no /nodes collection, so it is a Proxmox Backup Server\'s own API.');

            return ConnectionResult::of(
                ConnectionState::IdentityMismatch,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The endpoint is a Proxmox Backup Server\'s own API. This platform takes backups through a Proxmox VE '
                .'cluster that has a Backup Server datastore attached, so the endpoint for this row is the cluster.',
            );
        }

        if ($nodes->unauthorized() || $nodes->forbidden()) {
            $probe->failed('authorise', 'the token authenticated and may not read the cluster\'s node list.');

            return ConnectionResult::of(
                ConnectionState::PermissionInsufficient,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The token is accepted and holds no audit privilege on the cluster.',
            );
        }

        if (! $nodes->successful() || ! is_array($nodes->json('data'))) {
            $probe->failed('authorise', 'the cluster did not answer its own node list.');

            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The cluster is identified and did not answer its node list in a shape this platform understands.',
            );
        }

        $probe->passed('authorise', 'the token may read the cluster.');

        return $this->fromStorage($probe, $target);
    }

    /**
     * Whether there is anywhere to write a backup to.
     */
    private function fromStorage(Probe $probe, TestTarget $target): ConnectionResult
    {
        $storage = $probe->get('/storage', ['type' => 'pbs']);

        if (! $storage->successful() || ! is_array($storage->json('data'))) {
            $probe->passed('capabilities', 'the cluster did not report its storage list, so whether a backup datastore is attached is unknown.');

            return ConnectionResult::of(
                ConnectionState::Connected,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
            );
        }

        $datastores = array_values(array_filter(
            (array) $storage->json('data'),
            static fn (mixed $row): bool => is_array($row) && ($row['type'] ?? null) === 'pbs',
        ));

        if ($datastores === []) {
            $probe->failed('capabilities', 'the cluster has no Proxmox Backup Server storage attached, so nothing can be archived to one.');

            return ConnectionResult::of(
                ConnectionState::Connected,
                $probe->steps,
                $this->capabilities($target, [
                    'create' => CapabilityState::Unsupported,
                    'restore' => CapabilityState::Unsupported,
                    'delete' => CapabilityState::Unsupported,
                    'retention' => CapabilityState::Unsupported,
                ]),
                'The cluster is reachable and has no Proxmox Backup Server datastore attached. Declare one in inventory '
                .'and apply the pbs role; until then no backup can be written and no restore can be offered.',
            );
        }

        $probe->passed('capabilities', sprintf(
            '%d Proxmox Backup Server datastore(s) are attached to this cluster.',
            count($datastores),
        ));

        /*
         * `verify` is Unsupported and that is a statement about Proxmox rather
         * than about this credential: a PBS verification runs on the backup
         * server on its own schedule, and the hypervisor API has no endpoint
         * that starts one. The adapter's supportsVerification() answers false
         * for the same reason, and the two must agree — a capability row
         * claiming verification would put a button on a screen that the
         * adapter would refuse.
         *
         * `file_browse` and `file_restore` are Unsupported because this
         * adapter does not implement FileLevelBackupProvider at all.
         */
        return ConnectionResult::of(
            ConnectionState::Connected,
            $probe->steps,
            $this->capabilities($target, [
                'verify' => CapabilityState::Unsupported,
                'file_browse' => CapabilityState::Unsupported,
                'file_restore' => CapabilityState::Unsupported,
            ]),
        );
    }
}
