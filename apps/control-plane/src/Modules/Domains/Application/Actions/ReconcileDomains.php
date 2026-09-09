<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Enums\RegistrarCapability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\RegistrarNotAvailableException;
use Lynomia\Modules\Domains\Domain\Exceptions\UnknownRegistrarDriverException;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;

/**
 * Asking the registry what it actually holds.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * Because the Timeout Rule creates work rather than finishing it.
 *
 * When a registration times out, the platform records `indeterminate` and
 * stops — correctly, because retrying a purchase that may have succeeded buys
 * a second term. But a row that says "nobody knows" and has nothing to resolve
 * it is not honesty, it is an unattended queue: the customer has paid, the
 * screen says something went wrong, and no amount of waiting improves it.
 *
 * This is the other half. It is a **read**: it asks the registry what it
 * holds, and it writes down the answer. It never registers, never renews,
 * never spends. That is what makes it safe to run on a clock against exactly
 * the rows a retry must never touch.
 *
 * ===========================================================================
 * THE THREE DISAGREEMENTS
 * ===========================================================================
 *
 *  1. **The platform thinks it does not know.** Indeterminate rows: the
 *     registry either holds the name for this platform or it does not, and one
 *     lookup settles a customer's week.
 *
 *  2. **The platform thinks a name is held and the registry disagrees.** A
 *     domain that expired, was transferred away, or was never really
 *     registered. Left for a person rather than deleted: a name vanishing from
 *     an account without explanation is worse than one flagged.
 *
 *  3. **The registry holds names the platform has no row for.** Recorded, not
 *     adopted. Adopting a name automatically would attach somebody's domain to
 *     whichever account happened to be reconciled, and the platform cannot
 *     tell whose it is.
 */
final readonly class ReconcileDomains
{
    public function __construct(
        private DomainRegistrarFactory $registrars,
        private RecordDrift $drift,
    ) {}

    /**
     * Settle every name whose state the platform is unsure of.
     *
     * @return array{settled: int, disagreed: int, unreachable: int}
     */
    public function execute(int $limit = 100): array
    {
        $unsure = Domain::query()
            ->whereIn('state', [
                DomainState::Indeterminate->value,
                DomainState::RegistrationPending->value,
                DomainState::TransferPending->value,
            ])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $settled = 0;
        $disagreed = 0;
        $unreachable = 0;

        foreach ($unsure as $domain) {
            $outcome = $this->settle($domain);

            match ($outcome) {
                'settled' => $settled++,
                'disagreed' => $disagreed++,
                default => $unreachable++,
            };
        }

        return ['settled' => $settled, 'disagreed' => $disagreed, 'unreachable' => $unreachable];
    }

    /**
     * Names the registrar holds that this platform has no row for.
     *
     * Recorded as drift and never adopted. A name at a registrar could belong
     * to any account, or to none — it could be the platform's own, bought by
     * an operator outside the product — and attaching it to whichever customer
     * happened to be reconciled would hand somebody another person's domain.
     * So the platform says what it saw and stops.
     *
     * It goes through the same drift table as every other provider
     * disagreement on this platform, and appears on the same operator screen,
     * because a second reconciliation surface is a surface nobody checks.
     *
     * @return int how many names the registrar holds that this platform does not
     */
    public function findOrphans(string $driver): int
    {
        try {
            $provider = $this->registrars->make($driver);
        } catch (UnknownRegistrarDriverException) {
            return 0;
        }

        try {
            $atTheRegistrar = $provider->heldNames();
        } catch (RegistrarNotAvailableException|DomainRegistrarException) {
            // A listing that failed is not an empty registrar. Nothing is
            // recorded, because "the platform saw nothing" and "the platform
            // could not look" must not produce the same rows.
            return 0;
        }

        if ($atTheRegistrar === []) {
            return 0;
        }

        $known = Domain::query()
            ->where('provider', $driver)
            ->whereIn('name', $atTheRegistrar)
            ->whereIn('state', DomainState::thatHoldTheName())
            ->pluck('name')
            ->all();

        $orphans = array_values(array_diff($atTheRegistrar, $known));

        foreach ($orphans as $name) {
            $this->drift->execute(
                provider: $driver,
                resourceType: 'domain',
                kind: DriftKind::OrphanAtProvider,
                providerReference: $name,
                observed: ['name' => $name],

                /*
                 * A warning rather than critical. An unrecognised domain at a
                 * registrar costs money quietly — it renews on somebody's card
                 * — but it is not an outage, and paging somebody at three in
                 * the morning for it is how alerts get ignored.
                 */
                severity: DriftSeverity::Warning,
            );
        }

        return count($orphans);
    }

    /**
     * @return 'settled'|'disagreed'|'unreachable'
     */
    private function settle(Domain $domain): string
    {
        /*
         * A transfer is the one case where the registry has not finished
         * deciding. Asking `inspect` about a name the platform does not hold
         * yet answers the wrong question — it is still the losing registrar's
         * — so a pending transfer is polled through the transfer's own status
         * instead.
         */
        if ($domain->state === DomainState::TransferPending) {
            return $this->settleTransfer($domain);
        }

        try {
            $provider = $this->registrars->make($domain->provider);
        } catch (UnknownRegistrarDriverException) {
            return 'unreachable';
        }

        if (! $provider->supports(RegistrarCapability::Inspection)) {
            /*
             * A registrar that cannot be asked what it holds cannot have its
             * indeterminate rows settled by machine. They stay for a person,
             * which is the honest outcome rather than a guess.
             */
            return 'unreachable';
        }

        try {
            $held = $provider->inspect($domain->name);
        } catch (RegistrarNotAvailableException) {
            /*
             * A registrar with a seat and no integration. Unreachable rather
             * than disagreeing: it has not told the platform anything about
             * this name, and marking the row for review would be reporting a
             * disagreement that nobody observed.
             */
            return 'unreachable';
        } catch (DomainRegistrarException $e) {
            if ($e->isIndeterminate()) {
                // The registry is unreachable, not disagreeing. Try later.
                return 'unreachable';
            }

            /*
             * The registry answered and does not hold this name for this
             * platform. A name the platform believed it had is a disagreement
             * a person reads, not a row a sweep deletes: the customer paid for
             * it, and it may have been transferred away by somebody entitled
             * to do so.
             */
            $domain->forceFill([
                'state' => DomainState::NeedsReview,
                'review_reason' => 'The registry does not report this name as held here. '
                    .'It may have been transferred away, or the registration may never have completed.',
                'reconciled_at' => CarbonImmutable::now(),
            ])->save();

            $this->closeTheOperation($domain, DomainOperationState::NeedsReview);

            return 'disagreed';
        }

        /*
         * The registry holds it. Whatever the platform believed, this is now
         * the fact: an indeterminate registration completed after all, and the
         * customer has the name they paid for.
         *
         * Unless the registry's own expiry is already past — which is what an
         * indeterminate REDEMPTION that did not happen looks like: the name is
         * still held, still lapsed. Then it is `expired`, not `active`, and
         * the lifecycle sweep walks it on by the registry's clock as before.
         */
        $domain->forceFill([
            'state' => $held->expiresAt->isPast() ? DomainState::Expired : DomainState::Active,
            'provider_reference' => $held->providerReference ?? $domain->provider_reference,
            'registered_at' => $held->registeredAt ?? $domain->registered_at ?? CarbonImmutable::now(),
            'expires_at' => $held->expiresAt,
            'nameservers' => $held->nameservers === [] ? $domain->nameservers : $held->nameservers,
            'transfer_locked' => $held->transferLocked ?? $domain->transfer_locked,
            'review_reason' => null,
            'reconciled_at' => CarbonImmutable::now(),
        ])->save();

        $this->closeTheOperation($domain, DomainOperationState::Completed);

        return 'settled';
    }

    /**
     * A transfer the platform started and the registry has not finished.
     *
     * Polled rather than waited on, because five days is a normal answer and
     * nothing else in the platform will find out on its own. A transfer that
     * completes here is the moment the customer actually gains the name.
     *
     * @return 'settled'|'disagreed'|'unreachable'
     */
    private function settleTransfer(Domain $domain): string
    {
        try {
            $provider = $this->registrars->make($domain->provider);
        } catch (UnknownRegistrarDriverException) {
            return 'unreachable';
        }

        if (! $provider->supports(RegistrarCapability::TransferIn)) {
            return 'unreachable';
        }

        try {
            $status = $provider->transferStatus($domain->name);
        } catch (RegistrarNotAvailableException|DomainRegistrarException) {
            return 'unreachable';
        }

        if ($status->isPending()) {
            // Still with the losing registrar. Nothing to record but the fact
            // that the platform looked.
            $domain->forceFill(['reconciled_at' => CarbonImmutable::now()])->save();

            return 'unreachable';
        }

        if (! $status->isCompleted()) {
            /*
             * Refused, or timed out at the registry. The name stays where it
             * is, working, and the customer paid for a move that did not
             * happen — which is a refund and a conversation, not a row a sweep
             * can close on its own.
             */
            $domain->forceFill([
                'state' => DomainState::NeedsReview,
                'review_reason' => 'The transfer did not complete: '
                    .($status->reason ?? 'the registry did not say why')
                    .'. The name is still with its previous registrar and the customer has paid.',
                'reconciled_at' => CarbonImmutable::now(),
            ])->save();

            $this->closeTheOperation($domain, DomainOperationState::NeedsReview);

            return 'disagreed';
        }

        $domain->forceFill([
            'state' => DomainState::Active,
            'provider_reference' => $status->providerReference ?? $domain->provider_reference,
            'expires_at' => $status->expiresAt ?? $domain->expires_at,
            'registered_at' => $domain->registered_at ?? CarbonImmutable::now(),
            'review_reason' => null,
            'reconciled_at' => CarbonImmutable::now(),
        ])->save();

        $this->closeTheOperation($domain, DomainOperationState::Completed);

        return 'settled';
    }

    /**
     * The operation that was left hanging, given the same answer as the name.
     *
     * Without this the domain reads `active` on the customer's screen while
     * the operation beside it still says the registration did not finish —
     * two truths on one page, which is how a support queue fills up.
     */
    private function closeTheOperation(Domain $domain, DomainOperationState $state): void
    {
        DomainOperation::query()
            ->where('domain_id', $domain->getKey())
            ->whereIn('state', [
                DomainOperationState::Indeterminate->value,
                DomainOperationState::Running->value,
                DomainOperationState::AwaitingRegistry->value,
            ])
            ->update([
                'state' => $state->value,
                'completed_at' => $state === DomainOperationState::Completed ? CarbonImmutable::now() : null,
                'updated_at' => CarbonImmutable::now(),
            ]);
    }
}
