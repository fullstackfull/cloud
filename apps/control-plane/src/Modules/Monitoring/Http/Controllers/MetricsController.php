<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Throwable;

/**
 * Serves the Prometheus exposition.
 *
 * Access control is not here. It is the MetricsTokenGuard middleware, so that
 * the endpoint cannot be reached by a route that forgot to call a check inside
 * the controller — the difference between authorisation you can omit and
 * authorisation you would have to actively remove.
 *
 * The response is cached for a few seconds because more than one thing scrapes
 * it. An HA pair of Prometheus servers doubles the load by design, a retry
 * after a timeout arrives while the first collection is still running, and a
 * human running curl during an incident adds more. With a TTL shorter than the
 * scrape interval the cache never serves a stale-looking graph — consecutive
 * scrapes still see consecutive values — while a burst of simultaneous requests
 * costs one collection instead of five.
 */
final class MetricsController
{
    private const string CACHE_KEY = 'monitoring:metrics:exposition';

    public function __construct(
        private readonly MetricsRegistry $registry,
        private readonly PrometheusTextFormatter $formatter,
    ) {}

    public function __invoke(): Response
    {
        $ttl = (int) config('monitoring.metrics.cache_seconds', 10);

        return response($this->body($ttl), 200, [
            'Content-Type' => PrometheusTextFormatter::CONTENT_TYPE,
            // Nothing between here and Prometheus may keep a copy. A proxy
            // caching this would serve one host's numbers for another's, and
            // would keep serving them after the host stopped answering — which
            // is the one moment the scrape must fail rather than succeed.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * The exposition, cached if the cache is working and collected directly if
     * it is not.
     *
     * The fallback is the whole point of this method. In production the cache
     * store is Redis, which is also the queue backend — so the moment Redis
     * goes down, an uncaught Cache::remember() would turn every scrape into a
     * 500 and the platform would lose ALL of its metrics: orders awaiting
     * provisioning, MRR, IP runway, node capacity, none of which depend on
     * Redis in any way. The collectors are already careful about this one
     * layer down (QueueCollector reports NaN rather than a comfortable zero
     * when it cannot reach Redis), and that care is worthless if the request
     * never gets far enough to run them.
     *
     * A cache that cannot be reached is therefore a slower scrape, not a failed
     * one. The warning is logged so the degradation is visible rather than
     * merely survivable.
     */
    private function body(int $ttl): string
    {
        if ($ttl <= 0) {
            return $this->render();
        }

        try {
            return Cache::remember(self::CACHE_KEY, $ttl, fn (): string => $this->render());
        } catch (Throwable $e) {
            Log::warning('The metrics exposition cache is unavailable; collecting uncached.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->render();
        }
    }

    private function render(): string
    {
        return $this->formatter->render($this->registry->collect());
    }
}
