<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Monthly recurring revenue, in minor units, per currency.
 *
 * Minor units, not major, and never a float. A metric named `_minor` cannot be
 * misread the way a bare "mrr" can, and the conversion to dinar happens exactly
 * once — in a Prometheus recording rule — rather than in every panel that
 * displays it. KWD has three decimal places rather than two, which is precisely
 * the sort of thing a per-panel division eventually gets wrong on one dashboard
 * and not the others.
 *
 * Currencies are never mixed. Summing across them would produce a number that
 * is not money in any currency, and the one context where that number gets used
 * is a board slide.
 */
final readonly class RevenueCollector implements MetricsCollector
{
    /**
     * Days used to convert a daily or hourly subscription to a monthly figure.
     *
     * Thirty rather than 30.44: MRR is a run rate, and a run rate that changes
     * with the length of the month tells you about February rather than about
     * the business.
     */
    private const int DAYS_PER_MONTH = 30;

    private const int HOURS_PER_MONTH = 720;

    public function name(): string
    {
        return 'revenue';
    }

    public function collect(): array
    {
        /*
         * Normalisation happens in SQL, in `numeric` — PostgreSQL's exact
         * decimal type, not a float. The division by 12 for an annual plan is
         * the one arithmetic step in this module that can lose a fils, and
         * doing it in binary floating point would lose a different fils on
         * every host, so the two application servers would report different
         * MRR for identical data.
         */
        $sql = sprintf(<<<'SQL'
            currency,
            sum(
                case billing_period
                    when '%s' then recurring_amount_minor::numeric
                    when '%s' then round(recurring_amount_minor::numeric / 12)
                    when '%s' then round(recurring_amount_minor::numeric / 3)
                    when '%s' then round(recurring_amount_minor::numeric * %d)
                    when '%s' then round(recurring_amount_minor::numeric * %d)
                    else 0
                end
            )::bigint as mrr_minor
            SQL,
            BillingPeriod::Monthly->value,
            BillingPeriod::Yearly->value,
            BillingPeriod::Quarterly->value,
            BillingPeriod::Daily->value,
            self::DAYS_PER_MONTH,
            BillingPeriod::Hourly->value,
            self::HOURS_PER_MONTH,
        );

        /** @var array<string, int> $byCurrency */
        $byCurrency = DB::table('subscriptions')
            ->where('status', SubscriptionStatus::Active->value)
            ->selectRaw($sql)
            ->groupBy('currency')
            ->pluck('mrr_minor', 'currency')
            ->map(static fn (mixed $minor): int => (int) $minor)
            ->all();

        // The platform's own currency always has a series, even before the
        // first subscription exists. Otherwise the alert that notices MRR has
        // stopped moving cannot fire on a quiet month, which is the month it
        // would matter.
        $default = strtoupper((string) config('billing.default_currency', 'KWD'));
        $byCurrency[$default] ??= 0;

        $samples = [];

        foreach ($byCurrency as $currency => $minor) {
            $code = strtoupper(trim((string) $currency));

            /*
             * Round-tripped through Money rather than emitted raw. It costs
             * nothing and it means an unknown or malformed currency code in the
             * subscriptions table raises here — where the collector reports
             * itself down — instead of becoming a plausible-looking revenue
             * figure on the business dashboard.
             */
            $amount = Money::ofMinor($minor, $code);

            $samples[] = MetricSample::of(
                ['currency' => $amount->currency()],
                $amount->minorUnits(),
            );
        }

        return [
            Metric::gauge(
                'lynomia_mrr_minor',
                'Monthly recurring revenue from active subscriptions, in minor units, per currency. Never summed across currencies.',
                $samples,
            ),
        ];
    }
}
