<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dns\Application\Jobs\RemoveRecord as RemoveRecordJob;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;

/**
 * Take a record out of a zone.
 *
 * A record that never reached the provider is simply gone — there is nothing
 * out there to remove, and queueing a delete for it would be a call whose only
 * possible answer is "no such record". One that did reach the provider goes to
 * `deleting` and a job asks.
 */
final readonly class RemoveRecord
{
    /**
     * @throws DnsRefusedException
     */
    public function execute(DnsRecord $record): DnsRecord
    {
        return DB::transaction(static function () use ($record): DnsRecord {
            /** @var DnsRecord $locked */
            $locked = DnsRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state->isBeingDeleted()) {
                throw DnsRefusedException::notEditable((string) $locked->getKey());
            }

            if (! $locked->state->mayBeAtProvider()) {
                $locked->transitionTo(DnsState::Deleted);

                return $locked->refresh();
            }

            $locked->transitionTo(DnsState::Deleting);

            DB::afterCommit(static function () use ($locked): void {
                RemoveRecordJob::dispatch((string) $locked->getKey());
            });

            return $locked->refresh();
        });
    }
}
