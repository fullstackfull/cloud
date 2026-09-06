<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;

/**
 * Returns addresses held by dead jobs to the pool.
 *
 * The name says "expired", but expiry is not what this action acts on, and the
 * distinction is the entire design:
 *
 *   A reservation is released because its job is dead, never because
 *   its clock ran out.
 *
 * Time is a terrible proxy for death. A hypervisor under load takes twenty
 * minutes to clone a template it usually clones in forty seconds; a storage
 * array doing a rebuild makes every disk operation crawl; a provider API rate
 * limits us and the job sits in backoff. In every one of those cases the job
 * is alive, the address is about to be written onto a NIC, and the reservation
 * is doing exactly its job by holding it.
 *
 * Reclaim on elapsed time and this is what happens: the reaper hands the
 * address to a second job, the second job configures it, the first job — which
 * was never dead — finishes and configures the same address on another
 * machine. Two customers, one address, ARP deciding which of them has working
 * networking this minute. That is precisely the collision SELECT ... FOR
 * UPDATE SKIP LOCKED was written to prevent, recreated by a background job
 * that thought a slow job was a dead one.
 *
 * So the reaper reads the job, not the clock. Only a job that has reached a
 * terminal FAILED state — one that will never run again — releases its
 * addresses. A reservation whose job is still queued, running or retrying is
 * left alone however old it is; if that is genuinely a leak, it is a leak with
 * a job row attached to it, which an operator can find and resolve. Leaking a
 * handful of addresses is recoverable. Handing one address to two customers is
 * not.
 *
 * Timed-out jobs are deliberately absent from the terminal set for the same
 * reason config/provisioning.php excludes them from automatic retry: a timeout
 * means the platform stopped waiting, not that the provider stopped working,
 * and the VM may well exist with that address already configured.
 */
final readonly class ReapExpiredReservations
{
    /**
     * Job statuses that mean "this job will never run again, and whatever it
     * was building does not exist".
     *
     * Two settled statuses are deliberately absent, and both omissions are the
     * same judgement:
     *
     *  - `needs_review`. The engine has stopped, but it stopped precisely
     *    because it could not tell whether the resource exists. The machine
     *    may be running right now with this address configured on it.
     *  - anything meaning "timed out". A timeout says the platform stopped
     *    waiting, not that the provider stopped working — which is why
     *    config/provisioning.php refuses to auto-retry them either.
     *
     * Kept as a list of strings rather than an enum import because
     * provisioning_jobs belongs to another module; the constructor takes an
     * override so the coordinator can bind the authoritative set.
     *
     * @var list<string>
     */
    public const array TERMINAL_FAILURE_STATUSES = [
        'failed',
        'cancelled',
    ];

    /**
     * @param  list<string>  $terminalFailureStatuses
     */
    public function __construct(
        private IpAllocator $allocator,
        private array $terminalFailureStatuses = self::TERMINAL_FAILURE_STATUSES,
    ) {}

    /**
     * @param  int  $limit  Bounds one run, so a backlog is worked off over several
     *                      passes instead of one transaction holding thousands of locks.
     * @return list<IpReservation> the reservations this run released
     */
    public function execute(int $limit = 250): array
    {
        $doomed = IpReservation::query()
            ->live()
            /*
             * A reservation with no job id is NOT reaped. The column is
             * nullable because the job row may be deleted, and "the job record
             * is gone" is not evidence that the machine it built is gone. Those
             * need a human, not a background loop.
             */
            ->whereNotNull('provisioning_job_id')
            ->whereIn(
                'provisioning_job_id',
                DB::table('provisioning_jobs')
                    ->select('id')
                    ->whereIn('status', $this->terminalFailureStatuses),
            )
            // Oldest first: if the backlog is longer than the limit, the
            // addresses that have been held longest come back first.
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $released = [];

        foreach ($doomed as $reservation) {
            // Each release is its own transaction inside the allocator, so one
            // reservation that loses a race does not roll back the whole run.
            $released[] = $this->allocator->release($reservation, ReleaseReason::JobFailed);
        }

        return $released;
    }
}
