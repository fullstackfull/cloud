<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;

/**
 * Webhook processing and payment failures.
 *
 * A failed webhook is not a failed payment, and that distinction is the reason
 * this metric exists at all. The customer has been charged; the platform simply
 * does not know it, so the order sits unpaid and nothing is ever provisioned.
 * There is no HTTP error anywhere to notice — the provider got its 200 or is
 * quietly retrying — so without this series the first signal is a support
 * ticket from someone who paid yesterday.
 */
final readonly class PaymentsCollector implements MetricsCollector
{
    public function __construct(
        /*
         * The registry, not a hardcoded list. It already knows every provider
         * the platform can resolve, and a provider added there but forgotten
         * here would be one whose webhook failures are invisible until somebody
         * notices the series is missing — which nobody ever does.
         */
        private PaymentProviderRegistry $providers,
    ) {}

    public function name(): string
    {
        return 'payments';
    }

    public function collect(): array
    {
        return [
            $this->webhookEvents(),
            $this->failedPayments(),
        ];
    }

    private function webhookEvents(): Metric
    {
        $rows = DB::table('webhook_events')
            ->selectRaw('provider, status, count(*) as total')
            ->groupBy('provider', 'status')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            /** @var object{provider: string, status: string, total: int|string} $row */
            $counts[$row->provider.'|'.$row->status] = (int) $row->total;
        }

        $samples = [];
        $emitted = [];

        foreach ($this->providers->names() as $provider) {
            foreach (WebhookEventStatus::cases() as $status) {
                $key = $provider.'|'.$status->value;

                $samples[] = MetricSample::of(
                    ['provider' => $provider, 'status' => $status->value],
                    $counts[$key] ?? 0,
                );

                $emitted[$key] = true;
            }
        }

        /*
         * Rows from a provider the registry no longer knows about — one that
         * has been decommissioned in code while its historical events remain.
         * They are still money that moved, and dropping them would make the
         * webhook totals disagree with the database for no visible reason.
         */
        foreach ($counts as $key => $total) {
            if (isset($emitted[$key])) {
                continue;
            }

            [$provider, $status] = explode('|', (string) $key, 2);

            $samples[] = MetricSample::of(
                ['provider' => $provider, 'status' => $status],
                $total,
            );
        }

        /*
         * A gauge, despite the `_total` suffix the metric contract mandates,
         * for the same reason lynomia_orders_total is one: this counts rows in
         * each state and a row moves between states.
         *
         * WebhookEventStatus::Failed is explicitly not settled — a failed event
         * is retried, and the provider's own redelivery is what retries it — so
         * `{status="failed"}` goes DOWN every time a retry succeeds. Typed as a
         * counter, that decrease reads to Prometheus as a counter reset, and
         * increase() then reports the pre-reset value as brand-new failures:
         * the successful recovery pages the on-call for failures that never
         * happened. Only the total across all statuses is monotonic, because
         * rows are only ever appended.
         *
         * Every rule over this family therefore uses delta(), never
         * increase()/rate().
         */
        return Metric::gauge(
            'lynomia_webhook_events_total',
            'Provider webhook events by provider and processing status. A state count, not a monotonic counter: a failed event that is later retried leaves the failed series. Use delta(), never rate().',
            $samples,
        );
    }

    private function failedPayments(): Metric
    {
        $total = (int) DB::table('payment_attempts')
            ->where('status', PaymentAttemptStatus::Failed->value)
            ->count();

        return Metric::counter(
            'lynomia_failed_payments_total',
            'Payment attempts that failed. Rows are append-only, so this is monotonic.',
            [MetricSample::of([], $total)],
        );
    }
}
