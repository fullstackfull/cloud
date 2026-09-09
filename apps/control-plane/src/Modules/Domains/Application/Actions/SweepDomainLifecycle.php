<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\ValueObjects\RegistrableDomain;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;

/**
 * The clock a domain lives by.
 *
 * ===========================================================================
 * WHY THIS IS NOT OPTIONAL
 * ===========================================================================
 *
 * Because `auto_renew` defaults to true on every domain this platform sells,
 * and a default that nothing acts on is a promise the product does not keep.
 * A customer reading "auto-renew: on" and losing their domain anyway has been
 * misled by a checkbox.
 *
 * ===========================================================================
 * WHAT IT DOES, IN ORDER
 * ===========================================================================
 *
 *  1. **Orders renewals** for names with auto-renew on that fall inside the
 *     lead time. It issues an invoice; it does not renew. The registry is
 *     asked only when the money arrives, by the same listener that handles a
 *     renewal the customer clicked — one path, so the automatic one cannot
 *     drift from the manual one.
 *
 *  2. **Advances the states a registry advances.** Active past its expiry
 *     becomes expired; expired past the grace window becomes redemption; past
 *     redemption it is gone. These are the registry's own timers, mirrored so
 *     that a customer's screen says what their registry says.
 *
 * ===========================================================================
 * WHAT IT DELIBERATELY DOES NOT DO
 * ===========================================================================
 *
 * It never talks to a registrar. Every date here comes from the platform's own
 * record of what the registry told it, and where the two disagree it is
 * reconciliation — a read — that settles it, not this sweep. A sweep that both
 * guessed at registry state and acted on the guess would be a sweep that
 * deletes a domain the registry still holds.
 *
 * It also never advances a name whose expiry the platform does not know. A
 * null `expires_at` is a fact the platform is missing, not a date in the past.
 */
final readonly class SweepDomainLifecycle
{
    public function __construct(
        private QuoteDomain $quotes,
        private OrderDomainRenewal $renewals,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @return array{renewals_ordered: int, warned: int, abandoned: int, expired: int, redemption: int, deleted: int, skipped: int}
     */
    public function execute(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'renewals_ordered' => $this->orderAutomaticRenewals($now),
            'warned' => $this->warnAboutExpiry($now),
            'abandoned' => $this->releaseAbandonedOrders($now),
            ...$this->advanceStates($now),
        ];
    }

    /**
     * Give up names that were claimed and never paid for.
     *
     * ---------------------------------------------------------------------
     * Why this has to exist
     * ---------------------------------------------------------------------
     *
     * A registration claims the name inside this platform before the invoice
     * is paid, so that two customers cannot buy it in the same minute. That
     * claim has no expiry of its own — and without one, a customer who orders
     * a name and never pays holds it against everybody else for ever, this
     * platform included.
     *
     * The window is generous, because the failure it guards against is a
     * customer whose bank took a day. It is not indefinite, because the cost
     * of that is a name nobody can buy.
     *
     * Only `registration_pending` and `transfer_pending` are released, and
     * only when no payment arrived: a name in any other state either belongs
     * to somebody or is a question for a person.
     */
    private function releaseAbandonedOrders(CarbonImmutable $now): int
    {
        $days = max(1, (int) config('domains.abandoned_order_days', 7));
        $cutoff = $now->subDays($days);

        $abandoned = Domain::query()
            ->whereIn('state', [
                DomainState::RegistrationPending->value,
                DomainState::TransferPending->value,
            ])
            ->where('created_at', '<=', $cutoff)
            ->limit(200)
            ->get();

        $released = 0;

        foreach ($abandoned as $domain) {
            $stillWanted = DomainOperation::query()
                ->where('domain_id', $domain->getKey())
                ->whereNotIn('state', [
                    DomainOperationState::Requested->value,
                    DomainOperationState::Failed->value,
                ])
                ->exists();

            if ($stillWanted) {
                /*
                 * Somebody paid, or the platform is mid-attempt. Neither is
                 * abandoned, and releasing the name under a registration that
                 * is running is how a customer pays for a name the platform
                 * has just given away.
                 */
                continue;
            }

            $domain->forceFill([
                'state' => DomainState::Failed,
                'review_reason' => sprintf(
                    'The order for this name was never paid for; the claim was released after %d days.',
                    $days,
                ),
            ])->save();

            DomainOperation::query()
                ->where('domain_id', $domain->getKey())
                ->where('state', DomainOperationState::Requested->value)
                ->update([
                    'state' => DomainOperationState::Failed->value,
                    'failure_code' => 'domain.order_abandoned',
                    'failure_message' => 'The invoice for this order was never paid.',
                    'updated_at' => $now,
                ]);

            $released++;
        }

        return $released;
    }

    /**
     * Tell people their name is about to lapse.
     *
     * Separate from the renewal ordering, and it runs for names with
     * auto-renew **off** as well as on. A customer who turned it off did not
     * ask to lose the domain silently — they asked to decide for themselves,
     * and deciding needs knowing.
     *
     * The idempotency key carries the expiry date, so one warning goes out per
     * term rather than one a night for a month. A renewal moves the date and
     * the next term earns its own warning.
     */
    private function warnAboutExpiry(CarbonImmutable $now): int
    {
        $warn = max(1, (int) config('domains.renewal.warn_days', 45));

        $approaching = Domain::query()
            ->whereIn('state', [DomainState::Active->value, DomainState::Expired->value, DomainState::Grace->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now->addDays($warn))
            ->orderBy('expires_at')
            ->limit(500)
            ->get();

        $warned = 0;

        foreach ($approaching as $domain) {
            $expiry = $domain->expires_at;

            if ($expiry === null) {
                continue;
            }

            $sent = $this->notify->execute(
                customerId: (string) $domain->customer_id,
                type: NotificationType::DomainExpiring,
                idempotencyKey: 'domain-expiring:'.$domain->getKey().':'.CarbonImmutable::instance($expiry)->toDateString(),
                subject: $domain,
                data: ['domain' => $domain->name, 'date' => CarbonImmutable::instance($expiry)->toDateString()],
                link: '/domains',
            );

            if ($sent !== null) {
                $warned++;
            }
        }

        return $warned;
    }

    /**
     * Invoice the names that are about to lapse and are set to renew.
     */
    private function orderAutomaticRenewals(CarbonImmutable $now): int
    {
        $lead = max(1, (int) config('domains.renewal.lead_days', 30));

        $due = Domain::query()
            ->where('auto_renew', true)
            ->whereIn('state', [DomainState::Active->value, DomainState::Expired->value, DomainState::Grace->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now->addDays($lead))
            ->orderBy('expires_at')
            ->limit(200)
            ->get();

        $ordered = 0;

        foreach ($due as $domain) {
            $customer = $domain->customer;

            if (! $customer instanceof Customer) {
                continue;
            }

            try {
                $quote = $this->quotes->execute(
                    $customer,
                    RegistrableDomain::parse($domain->name, [$domain->tld]),
                    DomainOperationKind::Renew,
                    $domain->term_years,
                );

                $this->renewals->execute($customer, $domain, (string) $quote->getKey());

                $ordered++;
            } catch (DomainRefusedException) {
                /*
                 * Already has a renewal in flight, or the namespace has stopped
                 * being renewable. Neither is an error worth failing the sweep
                 * over — the next pass will find it if it becomes orderable,
                 * and a name with a renewal already queued is the common case
                 * on every run after the first.
                 */
                continue;
            }
        }

        return $ordered;
    }

    /**
     * Mirror the registry's own timers.
     *
     * @return array{expired: int, redemption: int, deleted: int, skipped: int}
     */
    private function advanceStates(CarbonImmutable $now): array
    {
        $expired = 0;
        $redemption = 0;
        $deleted = 0;
        $skipped = 0;

        $lapsing = Domain::query()
            ->whereIn('state', [
                DomainState::Active->value,
                DomainState::Expired->value,
                DomainState::Grace->value,

                /*
                 * Redemption is in this list and was left out of it once. A
                 * name that reached redemption then stayed there for ever,
                 * because the only sweep that could move it on did not select
                 * it — so the platform went on showing a recoverable name long
                 * after the registry had released it to anybody who wanted it.
                 */
                DomainState::Redemption->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('expires_at')
            ->limit(500)
            ->get();

        foreach ($lapsing as $domain) {
            $tld = DomainTld::query()->where('tld', $domain->tld)->first();

            if (! $tld instanceof DomainTld) {
                $skipped++;

                continue;
            }

            $next = $this->stateFor($domain, $tld, $now);

            if ($next === null || $next === $domain->state) {
                $skipped++;

                continue;
            }

            if (! $domain->state->canBecome($next)) {
                $skipped++;

                continue;
            }

            /*
             * A name whose recovery has been paid for and not yet answered
             * is not marched to deleted on the platform's clock. The
             * registry decides whether it was restored; until it says, the
             * row stays where it is and the operator queue shows why.
             */
            if ($next === DomainState::Deleted && $this->recoveryInFlight($domain)) {
                $skipped++;

                continue;
            }

            $domain->forceFill(['state' => $next])->save();

            match ($next) {
                DomainState::Expired => $expired++,
                DomainState::Redemption => $redemption++,
                DomainState::Deleted => $deleted++,
                default => $skipped++,
            };
        }

        return ['expired' => $expired, 'redemption' => $redemption, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    private function recoveryInFlight(Domain $domain): bool
    {
        return DomainOperation::query()
            ->where('domain_id', $domain->getKey())
            ->where('kind', DomainOperationKind::Redeem->value)
            ->whereIn('state', [
                DomainOperationState::Requested->value,
                DomainOperationState::Queued->value,
                DomainOperationState::Running->value,
                DomainOperationState::AwaitingRegistry->value,
                DomainOperationState::Indeterminate->value,
            ])
            ->exists();
    }

    /**
     * Where a lapsed name should be now, by the registry's clock.
     *
     * A TLD whose windows this platform has not been told keeps its names in
     * `expired` rather than being marched through invented deadlines. Deleting
     * a domain on a guessed schedule is the one mistake in this file that
     * cannot be undone.
     */
    private function stateFor(Domain $domain, DomainTld $tld, CarbonImmutable $now): ?DomainState
    {
        $expiry = $domain->expires_at;

        if ($expiry === null) {
            return null;
        }

        $expiry = CarbonImmutable::instance($expiry);

        /*
         * A namespace whose windows the platform has not been told keeps its
         * names in `expired`, which is the honest answer: past its date, and
         * this platform does not know what the registry does next.
         */
        if ($tld->grace_days === null || $tld->redemption_days === null) {
            return DomainState::Expired;
        }

        $graceEnds = $expiry->addDays($tld->grace_days);
        $redemptionEnds = $graceEnds->addDays($tld->redemption_days);

        return match (true) {
            $now->greaterThan($redemptionEnds) => DomainState::Deleted,
            $now->greaterThan($graceEnds) => DomainState::Redemption,

            /*
             * Inside the registry's grace window, which is a different thing
             * from merely expired: the name still resolves at most registries,
             * it can still be renewed at the ordinary price, and the customer
             * has not lost anything yet. Telling them it is "expired" full
             * stop would be telling them it is gone.
             */
            default => DomainState::Grace,
        };
    }
}
