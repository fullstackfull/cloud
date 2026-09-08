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
 * Push one recorded value to the provider.
 *
 * Carries the record's id rather than the model, so the worker reads the row
 * as it is when it runs: a customer who changed their mind twice in ten
 * seconds gets the value they last asked for, not the one that happened to be
 * serialised into the first message.
 *
 * One attempt, for the reason given on {@see PublishZone}. A publish that does
 * not answer leaves the row `indeterminate` — not `failed`, because failed is
 * a claim the platform cannot support, and a customer reading it would publish
 * the record again, which is exactly the duplicate the Timeout Rule exists to
 * prevent.
 */
final class PublishRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly string $recordId,
    ) {}

    public function handle(DnsProviderFactory $providers, SecretRedactor $redactor): void
    {
        $record = DnsRecord::query()->with('zone')->find($this->recordId);

        if ($record === null || $record->state !== DnsState::Pending) {
            return;
        }

        $zone = $record->zone;

        /*
         * The zone went out from under the record — given up, or never
         * published. Nothing is marked failed: the customer has nothing to fix
         * about the record, and the zone's own state already says what
         * happened.
         */
        if ($zone === null || $zone->state !== DnsState::Active || $zone->provider_zone_id === null) {
            return;
        }

        try {
            /*
             * Rebuilt through the value object here, at the last point before
             * the call. The row was written by an action that validates, but
             * this job is also what an operator re-dispatches against a row
             * that has sat in the database for a month, and a provider must
             * never be handed a value that has not been through these rules.
             */
            $value = $record->toValue();
        } catch (InvalidDnsRecordException $e) {
            $record->transitionTo(DnsState::Failed, ['failure_reason' => $redactor->redactString($e->getMessage())]);

            return;
        }

        try {
            $published = $providers->make()->publish(
                DnsZoneValue::of($zone->provider_zone_id, $zone->name, $zone->nameservers ?? []),
                $value,
            );
        } catch (DnsProviderException $e) {
            $record->transitionTo(
                $e->isIndeterminate() ? DnsState::Indeterminate : DnsState::Failed,
                ['failure_reason' => $redactor->redactString($e->getMessage())],
            );

            return;
        } catch (DnsNotConfiguredException $e) {
            $record->transitionTo(DnsState::Failed, ['failure_reason' => $redactor->redactString($e->getMessage())]);

            return;
        }

        $record->transitionTo(DnsState::Active, [
            'provider_record_id' => $published->id(),
            'failure_reason' => null,
            'last_published_at' => now(),
        ]);
    }
}
