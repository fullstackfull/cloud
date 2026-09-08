<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;

/**
 * Whether the platform's messages are reaching anybody.
 *
 * A notification system fails quietly by construction: nothing errors when a
 * mail server stops accepting, the customer simply hears nothing, and the
 * first signal is a support ticket asking why nobody was told. These are the
 * series that make that visible before the ticket.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Two labels — channel and status — whose value sets are two small enums.
 * Nothing is labelled by customer, service, notification type or email
 * address. A per-type series would grow every time somebody adds a message,
 * and a per-customer one is how a Prometheus falls over; the notification type
 * belongs on the operator screen, where a query can filter it, not in a metric
 * scraped every fifteen seconds for ever.
 */
final readonly class NotificationCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'notifications';
    }

    public function collect(): array
    {
        return [$this->deliveries()];
    }

    private function deliveries(): Metric
    {
        $rows = DB::table('notification_deliveries')
            ->selectRaw('channel, status, count(*) as total')
            ->groupBy('channel', 'status')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            /** @var object{channel: string, status: string, total: int|string} $row */
            $counts[$row->channel.'|'.$row->status] = (int) $row->total;
        }

        $samples = [];

        /*
         * The full cross product of implemented channels and statuses, zeros
         * included. A series that only appears once something has failed is a
         * series nobody can write an alert rule against in advance — which is
         * to say, before the outage.
         */
        foreach (NotificationChannel::implemented() as $channel) {
            foreach (DeliveryStatus::cases() as $status) {
                $samples[] = new MetricSample(
                    ['channel' => $channel->value, 'status' => $status->value],
                    (float) ($counts[$channel->value.'|'.$status->value] ?? 0),
                );
            }
        }

        return Metric::gauge(
            'lynomia_notification_delivery_total',
            'Notification deliveries by channel and status. A rising failed count on email means customers are not being told things the platform believes it told them.',
            $samples,
        );
    }
}
