<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Application\Jobs\RemoveZone;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * An account gives a domain up.
 *
 * The most destructive thing on this surface, and the reason the HTTP layer
 * makes somebody type the domain back. A zone that is gone answers NXDOMAIN
 * for every name under it — the website, the mail, the things somebody set up
 * years ago and forgot — and unlike a deleted record there is no partial
 * failure that leaves most of it working.
 *
 * There is no grace period, and that is deliberate rather than an omission.
 * A backup sits on a datastore doing nothing while a customer thinks; a zone
 * is being served, and "we will stop answering for your domain in an hour"
 * would be an outage scheduled for a time the customer cannot see. The
 * confirmation is the pause.
 */
final readonly class ReleaseZone
{
    /**
     * @throws DnsRefusedException
     */
    public function execute(DnsZone $zone): DnsZone
    {
        return DB::transaction(static function () use ($zone): DnsZone {
            /** @var DnsZone $locked */
            $locked = DnsZone::query()->whereKey($zone->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state->isBeingDeleted()) {
                throw DnsRefusedException::zoneNotEditable($locked->name);
            }

            /*
             * A zone that never reached the provider is simply gone: there is
             * nothing out there to give up, and a job for it would be a call
             * whose only possible answer is "no such zone". Note that this
             * asks the state, not the identifier — a claim that timed out has
             * no identifier and may still have created the zone.
             */
            if (! $locked->state->mayBeAtProvider()) {
                $locked->transitionTo(DnsState::Deleted);

                DnsRecord::query()
                    ->where('dns_zone_id', $locked->getKey())
                    ->where('state', '!=', DnsState::Deleted->value)
                    ->get()
                    ->each(static function (DnsRecord $record): void {
                        if ($record->state->canBecome(DnsState::Deleted)) {
                            $record->transitionTo(DnsState::Deleted);
                        }
                    });

                return $locked->refresh();
            }

            /*
             * Marked here rather than left for the job, so that nothing can be
             * added to a zone on its way out in the seconds before the worker
             * picks it up. The records themselves are settled by the job: it
             * is the one that knows whether the zone really went.
             */
            $locked->transitionTo(DnsState::Deleting);

            DnsRecord::query()
                ->where('dns_zone_id', $locked->getKey())
                ->whereIn('state', [DnsState::Pending->value, DnsState::Active->value])
                ->get()
                ->each(static function (DnsRecord $record): void {
                    $record->transitionTo(DnsState::Deleting);
                });

            DB::afterCommit(static function () use ($locked): void {
                RemoveZone::dispatch((string) $locked->getKey());
            });

            return $locked->refresh();
        });
    }
}
