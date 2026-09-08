<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Domain\Services;

use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Domain\Exceptions\VpsNotActiveException;
use Lynomia\Modules\Vps\Domain\Exceptions\VpsNotProvisionedException;
use Lynomia\Modules\Vps\Domain\Exceptions\VpsOperationInFlightException;
use Lynomia\Modules\Vps\Domain\Exceptions\VpsOperationNeedsReviewException;

/**
 * The three questions asked before any lifecycle work is queued for a machine.
 *
 * All three refuse rather than queue, which is the point. Work accepted now
 * and executed later is work whose preconditions were true at a moment nobody
 * recorded: a stop queued against a machine that was still building runs at an
 * unpredictable point in the build, and a second reinstall queued behind the
 * first rebuilds a machine that is already being rebuilt.
 *
 * It lives in Domain rather than in a controller because a queue worker, a
 * console command and an operator surface all have to reach the same verdict.
 * A guard that only exists on the HTTP path is a guard the next caller skips.
 */
final class VpsOperationGuard
{
    /**
     * A machine that may be powered, reinstalled, or attached to.
     *
     * @throws VpsNotActiveException the service behind it is not active
     * @throws VpsNotProvisionedException the hypervisor has never confirmed it
     */
    public function assertOperable(VirtualMachine $machine): void
    {
        $this->assertServiceActive($machine);
        $this->assertReachable($machine);
    }

    /**
     * The thing the customer bought is still a thing they may use.
     *
     * Split out of assertOperable() because the console needs it too, and for
     * a while it did not have it. A console is root access, so it cannot be
     * more permissive than a reboot: a suspended service is one the platform
     * deliberately cut off — for non-payment, or for abuse — and a terminated
     * one is a service somebody stopped paying for. Handing either a live
     * permit to a keyboard on the box while POST /power answers 409 is the
     * suspension lever failing on the one door that matters most.
     *
     * @throws VpsNotActiveException
     */
    public function assertServiceActive(VirtualMachine $machine): void
    {
        $service = $machine->service()->first();

        // No service is not "inactive", it is a machine whose owning row has
        // gone — and the query that produced this machine started from a
        // customer's services, so reaching here at all means something else
        // deleted it mid-request. Refused as inactive rather than dereferenced.
        if ($service === null || $service->status !== ServiceStatus::Active) {
            throw VpsNotActiveException::forStatus($service->status ?? ServiceStatus::Terminated);
        }
    }

    /**
     * A machine the hypervisor has actually confirmed, on a node and a cluster
     * the platform can still address.
     *
     * Separate from assertOperable() because the console is deliberately
     * available to a machine whose service is active but whose guest is
     * stopped or broken — that is what a console is for — while it is not
     * available to a machine that does not exist at the hypervisor.
     *
     * @throws VpsNotProvisionedException
     */
    public function assertReachable(VirtualMachine $machine): void
    {
        if (! $machine->existsRemotely() || $machine->node_id === null || $machine->cluster_id === null) {
            throw VpsNotProvisionedException::make();
        }
    }

    /**
     * Nothing else is mid-flight for this service, and nothing earlier is
     * still unaccounted for.
     *
     * Checked against the service rather than the machine because that is what
     * the provisioning engine keys jobs on, and because a destroy queued for
     * the service is every bit as much a reason not to accept a reboot.
     *
     * Three statuses, not two. Queued and running are the obvious pair; the
     * third is `needs_review`, which is where a TIMED-OUT operation comes to
     * rest. A timeout means the platform stopped waiting, never that the
     * hypervisor stopped working, so a job sitting there is an operation whose
     * outcome nobody knows — and the machine's state is therefore unknown too.
     * Queueing a reboot on top of it sends a command to a guest that may be
     * halfway through a rebuild, and queueing a second reinstall starts
     * destroying a disk the first one may still be writing. Refusing until a
     * person has looked is the only answer that does not gamble with somebody
     * else's data.
     *
     * @throws VpsOperationInFlightException a job is queued or running
     * @throws VpsOperationNeedsReviewException an earlier job is waiting for an operator
     */
    public function assertNothingInFlight(VirtualMachine $machine): void
    {
        $unresolved = ProvisioningJob::query()
            ->where('service_id', $machine->service_id)
            ->whereIn('status', [
                ProvisioningJobStatus::Queued->value,
                ProvisioningJobStatus::Running->value,
                ProvisioningJobStatus::NeedsReview->value,
            ])
            ->get();

        // Live work first: "wait for this to finish" is actionable, and it is
        // the answer a customer can act on without opening a ticket.
        $live = $unresolved->first(
            static fn (ProvisioningJob $job): bool => ! $job->status->needsAttention(),
        );

        if ($live !== null) {
            throw VpsOperationInFlightException::forKind($live->kind);
        }

        $stranded = $unresolved->first();

        if ($stranded !== null) {
            throw VpsOperationNeedsReviewException::forKind($stranded->kind);
        }
    }
}
