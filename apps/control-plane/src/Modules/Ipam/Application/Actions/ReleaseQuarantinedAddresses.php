<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;

/**
 * Puts addresses back into circulation once their quarantine has elapsed.
 *
 * The counterpart to IpAllocator::releaseAssignment(). Quarantine is a
 * deliberate withdrawal of scarce capacity, so something has to give it back —
 * without this the pool bleeds an address per cancellation, permanently.
 */
final readonly class ReleaseQuarantinedAddresses
{
    /**
     * @param  int  $limit  Bounds the number of rows one pass locks.
     * @return int how many addresses returned to the pool
     */
    public function execute(int $limit = 1000): int
    {
        return DB::transaction(function () use ($limit): int {
            /*
             * SKIP LOCKED again, for the same reason as the allocator: this
             * runs on a schedule and must never queue behind — or worse,
             * deadlock with — a live allocation that is holding one of these
             * rows. A row it skips is simply released on the next pass.
             */
            /** @var list<string> $ids */
            $ids = DB::table('ip_addresses')
                ->select('id')
                ->where('status', IpAddressStatus::Quarantined->value)
                /*
                 * A quarantined row with no expiry is left quarantined for
                 * ever rather than released now. It means something set the
                 * status without setting the window, and the safe reading of
                 * an unknown quarantine is "still serving it".
                 */
                ->whereNotNull('quarantined_until')
                ->where('quarantined_until', '<=', now())
                ->orderBy('quarantined_until')
                ->limit($limit)
                ->lock('for update skip locked')
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return 0;
            }

            return IpAddress::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => IpAddressStatus::Available->value,
                    // Cleared together with the status: a stale
                    // quarantine_reason on an available address would read as
                    // "this address is tainted" to the next person to look.
                    'quarantined_until' => null,
                    'quarantine_reason' => null,
                    'updated_at' => now(),
                ]);
        });
    }
}
