<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\DriftAlreadyReviewedException;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;

/**
 * An operator's verdict on a disagreement the platform could not settle.
 *
 * RecordDrift deliberately never heals anything, which makes this the other
 * half of the workflow and the reason the drift table has resolution columns
 * at all. Until now it had no caller: drift accumulated, nobody could mark it
 * seen, and the alerting had no state to quieten. A queue of findings that
 * cannot be worked is the same as no findings.
 *
 * Two verdicts, and the difference matters:
 *
 *  - **Acknowledged** means a person has seen it and it is still true. Fresh
 *    sightings keep landing on the row, because the situation has not changed.
 *  - **Resolved** means it is no longer true. A resolved drift that is seen
 *    again starts a new row rather than reopening this one, so a resolution
 *    that did not hold stays visible in history instead of being erased by
 *    the reconciler that disproved it.
 *
 * What this does NOT do is act on the provider. Adopting an orphan, rebuilding
 * a missing machine and correcting a power state are three different
 * operations with three different risks, and each has — or does not yet have —
 * its own action. Marking a row resolved is a statement about the world, not
 * a change to it.
 */
final readonly class ReviewDrift
{
    /**
     * @throws DriftAlreadyReviewedException
     */
    public function execute(
        ResourceDrift $drift,
        DriftStatus $verdict,
        ?string $resolution = null,
        ?string $reviewedByUserId = null,
    ): ResourceDrift {
        if ($verdict === DriftStatus::Open) {
            // Reopening is not a verdict. A drift that is true again is a new
            // sighting, and the reconciler produces those.
            throw DriftAlreadyReviewedException::cannotReopen((string) $drift->getKey());
        }

        return DB::transaction(function () use ($drift, $verdict, $resolution, $reviewedByUserId): ResourceDrift {
            /** @var ResourceDrift $locked */
            $locked = ResourceDrift::query()->lockForUpdate()->findOrFail($drift->getKey());

            /*
             * Re-read under the lock, because two operators looking at the
             * same alert channel routinely click the same row. Converging on
             * an identical verdict is fine; overwriting somebody else's
             * different one, and their name, is not.
             */
            if ($locked->status === $verdict) {
                return $locked;
            }

            if ($locked->status === DriftStatus::Resolved) {
                throw DriftAlreadyReviewedException::alreadyResolved(
                    (string) $locked->getKey(),
                    $locked->resolved_by_user_id,
                );
            }

            $locked->status = $verdict;
            $locked->resolution = $resolution;
            $locked->resolved_by_user_id = $reviewedByUserId;
            $locked->resolved_at = $verdict === DriftStatus::Resolved ? now() : null;
            $locked->save();

            return $locked;
        });
    }
}
