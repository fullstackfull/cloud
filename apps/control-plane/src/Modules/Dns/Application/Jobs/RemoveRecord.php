<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone as DnsZoneValue;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Take one record out of a zone.
 *
 * A delete that does not answer is the case this whole module is arranged
 * around. The record may be gone; it may not. Retrying is not free — a second
 * delete against a name the customer has since re-created removes the new
 * record — so the row goes to `indeterminate` and the reconciler settles it by
 * looking, which is the only thing that can.
 */
final class RemoveRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly string $recordId,
    ) {}

    public function handle(DnsProviderFactory $providers, SecretRedactor $redactor): void
    {
        $record = DnsRecord::query()->with('zone')->find($this->recordId);

        if ($record === null || $record->state !== DnsState::Deleting) {
            return;
        }

        $zone = $record->zone;

        /*
         * No zone at the provider means no record at the provider. Marked
         * deleted rather than left waiting: there is nothing left that could
         * be serving this name.
         */
        if ($zone === null || $zone->provider_zone_id === null) {
            $record->transitionTo(DnsState::Deleted);

            return;
        }

        try {
            $value = $record->toValue();
        } catch (InvalidDnsRecordException) {
            /*
             * A row that cannot be rebuilt cannot be sent, and cannot have
             * been sent either — every publish goes through the same
             * construction. Nothing is at the provider under this value.
             */
            $record->transitionTo(DnsState::Deleted);

            return;
        }

        try {
            $providers->make()->delete(
                DnsZoneValue::of($zone->provider_zone_id, $zone->name, $zone->nameservers ?? []),
                $value,
            );
        } catch (DnsProviderException $e) {
            $record->transitionTo(
                $e->isIndeterminate() ? DnsState::Indeterminate : DnsState::NeedsReview,
                ['failure_reason' => $redactor->redactString($e->getMessage())],
            );

            return;
        } catch (DnsNotConfiguredException $e) {
            $record->transitionTo(DnsState::NeedsReview, [
                'failure_reason' => $redactor->redactString($e->getMessage()),
            ]);

            return;
        }

        $record->transitionTo(DnsState::Deleted, ['failure_reason' => null]);
    }
}
