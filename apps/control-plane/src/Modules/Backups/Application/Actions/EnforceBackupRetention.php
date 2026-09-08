<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupDeletionRefusedException;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The sweep that keeps a datastore from growing for ever.
 *
 * Two rules, applied in this order and both from the service's own plan:
 *
 *  1. **Expired.** A backup past `expires_at` has outlived what the customer
 *     bought. It is marked for deletion, not deleted — the request and the act
 *     stay separate here as everywhere else, so an operator can see what the
 *     sweep is about to do.
 *  2. **Over the ceiling.** A plan that keeps at most N backups drops the
 *     oldest beyond N. Oldest first and never newest first: the most recent
 *     backup is the one a customer restoring from a disaster reaches for.
 *
 * **The sweep marks; it never asks the provider.** Acting is
 * {@see DeleteBackupAtProvider}, driven by the same sweep on the following
 * pass, after the grace period. That gap is deliberate — a customer who
 * cancels a service by mistake, or an operator who has just noticed a plan
 * misconfigured to one-day retention, has an hour to stop it.
 *
 * **Nothing it cannot delete becomes a failure.** A backup being restored
 * from, or held by a termination hold, is skipped silently and looked at again
 * tomorrow. A sweep that marked those as needing review would fill an
 * operator's screen with rows that are behaving correctly.
 */
final readonly class EnforceBackupRetention
{
    public function __construct(
        private RequestBackupDeletion $request,
        private DeleteBackupAtProvider $delete,
        private ResolveBackupPolicy $policy,
    ) {}

    /**
     * @return array{marked: int, deleted: int, skipped: int}
     */
    public function execute(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $marked = 0;
        $skipped = 0;

        foreach ($this->servicesWithBackups() as $serviceId) {
            /** @var Service|null $service */
            $service = Service::query()->whereKey($serviceId)->first();
            $policy = $this->policy->execute($service);

            /** @var Collection<int, Backup> $available */
            $available = Backup::query()
                ->where('service_id', $serviceId)
                ->whereIn('state', $this->availableStates())
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $doomed = $this->expired($available, $now)
                ->merge($this->beyondTheCeiling($available, $policy->maxRetained))
                ->unique(static fn (Backup $backup): string => (string) $backup->getKey());

            foreach ($doomed as $backup) {
                try {
                    /*
                     * One clock for one pass. Marking with `now()` while
                     * deciding what is due against `$now` — captured at the
                     * top of this method — makes a zero-hour grace period
                     * depend on which side of a second boundary the two calls
                     * land, which is exactly the kind of failure that appears
                     * on one CI runner and not the other.
                     */
                    $this->request->execute($backup, RequestBackupDeletion::BY_RETENTION, at: $now);
                    $marked++;
                } catch (BackupDeletionRefusedException) {
                    // Being restored from, or held. Correct behaviour, not a
                    // failure: looked at again on the next pass.
                    $skipped++;
                }
            }
        }

        return ['marked' => $marked, 'deleted' => $this->actOnWhatIsDue($now), 'skipped' => $skipped];
    }

    /**
     * Asks the provider to remove what was marked long enough ago.
     */
    private function actOnWhatIsDue(CarbonImmutable $now): int
    {
        $grace = $now->subHours(max(0, (int) config('backups.deletion_grace_hours', 1)));

        $due = Backup::query()
            ->where(function ($query) use ($grace): void {
                $query
                    ->where(function ($requested) use ($grace): void {
                        $requested->where('state', BackupState::DeleteRequested->value)
                            ->where('deletion_requested_at', '<=', $grace);
                    })
                    // Already asked and not yet confirmed gone. No grace on
                    // these: the decision was made a pass ago.
                    ->orWhere('state', BackupState::Deleting->value);
            })
            ->orderBy('deletion_requested_at')
            ->limit(200)
            ->get();

        $deleted = 0;

        foreach ($due as $backup) {
            $after = $this->delete->execute($backup);

            if ($after->state === BackupState::Deleted) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param  Collection<int, Backup>  $available
     * @return Collection<int, Backup>
     */
    private function expired(Collection $available, CarbonImmutable $now): Collection
    {
        return $available->filter(
            static fn (Backup $backup): bool => $backup->expires_at !== null && $backup->expires_at->lessThanOrEqualTo($now),
        );
    }

    /**
     * Oldest first, and never the newest: the most recent backup is the one
     * somebody restoring from a disaster reaches for.
     *
     * @param  Collection<int, Backup>  $available
     * @return Collection<int, Backup>
     */
    private function beyondTheCeiling(Collection $available, ?int $ceiling): Collection
    {
        if ($ceiling === null || $available->count() <= $ceiling) {
            return new Collection;
        }

        return $available->take($available->count() - $ceiling);
    }

    /**
     * @return list<string>
     */
    private function servicesWithBackups(): array
    {
        /** @var list<string> $ids */
        $ids = Backup::query()
            ->whereIn('state', $this->availableStates())
            ->distinct()
            ->pluck('service_id')
            ->all();

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function availableStates(): array
    {
        return array_values(array_map(
            static fn (BackupState $state): string => $state->value,
            array_filter(BackupState::cases(), static fn (BackupState $state): bool => $state->isAvailable()),
        ));
    }
}
