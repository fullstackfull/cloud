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
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone as DnsZoneValue;
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Give a zone up at the provider.
 *
 * The records go with it, and they are marked deleted here rather than each
 * being deleted individually: a zone that no longer exists cannot be serving
 * any of them, and asking a provider to remove two hundred records from a zone
 * it has already dropped is two hundred calls to be told the same thing.
 *
 * If the zone call does not answer, nothing is marked gone — not the zone and
 * not its records. Saying "deleted" here would tell a customer their names
 * have stopped resolving while they may still be live, and it is the customer
 * who would then be surprised, not the platform.
 */
final class RemoveZone implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly string $zoneId,
    ) {}

    public function handle(DnsProviderFactory $providers, SecretRedactor $redactor): void
    {
        $zone = DnsZone::query()->find($this->zoneId);

        if ($zone === null || $zone->state !== DnsState::Deleting) {
            return;
        }

        try {
            $provider = $providers->make();

            /*
             * No identifier is not the same as no zone.
             *
             * A claim that timed out may well have created the zone; the
             * platform simply never learned what it was called. Looking it up
             * by name is the only honest way to find out, and it is why this
             * asks rather than assuming — assuming would mean writing
             * "deleted" over a zone that is still serving the customer's
             * domain, or leaving one behind for ever.
             */
            $target = $zone->provider_zone_id === null
                ? $provider->findZone($zone->name)
                : DnsZoneValue::of($zone->provider_zone_id, $zone->name, $zone->nameservers ?? []);

            if ($target === null) {
                $this->finish($zone);

                return;
            }

            $provider->deleteZone($target);
        } catch (DnsProviderException $e) {
            $zone->transitionTo(
                $e->isIndeterminate() ? DnsState::Indeterminate : DnsState::NeedsReview,
                ['failure_reason' => $redactor->redactString($e->getMessage())],
            );

            return;
        } catch (DnsNotConfiguredException $e) {
            $zone->transitionTo(DnsState::NeedsReview, [
                'failure_reason' => $redactor->redactString($e->getMessage()),
            ]);

            return;
        }

        $this->finish($zone);
    }

    private function finish(DnsZone $zone): void
    {
        DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('state', '!=', DnsState::Deleted->value)
            ->get()
            ->each(static function (DnsRecord $record): void {
                /*
                 * Through the transition, one row at a time, rather than a
                 * bulk update. A mass update would write `deleted` over a row
                 * whose state forbids it, and the states this module refuses
                 * to leave are the ones that most need a person.
                 */
                if ($record->state->canBecome(DnsState::Deleted)) {
                    $record->transitionTo(DnsState::Deleted);
                }
            });

        $zone->transitionTo(DnsState::Deleted, ['failure_reason' => null, 'provider_zone_id' => null]);
    }
}
