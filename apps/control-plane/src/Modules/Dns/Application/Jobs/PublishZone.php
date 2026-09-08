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
use Lynomia\Modules\Dns\Infrastructure\DnsProviderFactory;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Create the zone at the provider and record what it must be delegated to.
 *
 * One attempt. A refusal is an answer — the provider will refuse the same name
 * just as firmly in thirty seconds — and a timeout is not: the zone may well
 * have been created, and asking again is how an account ends up holding one
 * domain twice at a provider that allows it. The row goes to `indeterminate`
 * and reconciliation, or a person, settles it.
 */
final class PublishZone implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** See the class docblock: neither failure mode is retried. */
    public int $tries = 1;

    public function __construct(
        private readonly string $zoneId,
    ) {}

    public function handle(DnsProviderFactory $providers, SecretRedactor $redactor): void
    {
        $zone = DnsZone::query()->find($this->zoneId);

        // Claimed and given up again before the worker got to it. Nothing to
        // create, and nothing to report.
        if ($zone === null || $zone->state !== DnsState::Pending) {
            return;
        }

        try {
            $created = $providers->make()->createZone($zone->name);
        } catch (DnsProviderException $e) {
            $zone->transitionTo(
                $e->isIndeterminate() ? DnsState::Indeterminate : DnsState::Failed,
                // Redacted before it is stored: a zone client that fails
                // quotes the request it sent, and that request carried the
                // API token.
                ['failure_reason' => $redactor->redactString($e->getMessage())],
            );

            return;
        } catch (DnsNotConfiguredException $e) {
            // A deployment that cannot create zones is an operator's problem,
            // not the customer's, and it is not indeterminate: nothing was
            // sent.
            $zone->transitionTo(DnsState::Failed, ['failure_reason' => $redactor->redactString($e->getMessage())]);

            return;
        }

        $zone->transitionTo(DnsState::Active, [
            'provider_zone_id' => $created->id(),
            'nameservers' => $created->nameservers(),
            'failure_reason' => null,
            'last_synced_at' => now(),
        ]);
    }
}
