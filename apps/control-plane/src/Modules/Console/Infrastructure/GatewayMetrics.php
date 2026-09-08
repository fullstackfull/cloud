<?php

declare(strict_types=1);

namespace Lynomia\Modules\Console\Infrastructure;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Lynomia\Modules\Monitoring\Application\Collectors\ConsoleCollector;

/**
 * What the console gateway did, in numbers an operator can watch.
 *
 * The gateway is a separate process, so its counters cannot be collected from
 * the API's database the way every other metric in this platform is. They are
 * written to the shared cache — the same Redis the permits live in, which the
 * gateway must already reach — and read back by
 * {@see ConsoleCollector}
 * when the API is scraped.
 *
 * ---------------------------------------------------------------------------
 * Cardinality
 * ---------------------------------------------------------------------------
 *
 * Every label value in here comes from a fixed list. Refusal reasons in
 * particular are checked against {@see REASONS} before being counted, and
 * anything unrecognised is counted as `other` — because a refusal reason is
 * one string away from being attacker-controlled, and a metric labelled with
 * attacker-controlled strings is a way to take a monitoring system down from
 * the outside.
 *
 * Nothing here is labelled by customer, machine, session or address. Those
 * facts belong in the audit trail, which is queryable and retained
 * deliberately, not in a series scraped every fifteen seconds for ever.
 */
final readonly class GatewayMetrics
{
    private const string PREFIX = 'console:gateway:metrics:';

    /** How long a counter survives without being touched. */
    private const int TTL_SECONDS = 604_800;

    /**
     * The refusal reasons that get their own series.
     *
     * @var list<string>
     */
    public const array REASONS = [
        'rate_limited',
        'origin_not_allowed',
        'invalid_permit',
        'machine_gone',
        'machine_mismatch',
        'service_not_active',
        'upstream_unavailable',
        'at_capacity',
        'other',
    ];

    /**
     * The ways a connection ends.
     *
     * @var list<string>
     */
    public const array OUTCOMES = [
        'accepted',
        'opened',
        'closed',
        'upstream_failed',
    ];

    public function __construct(
        private CacheFactory $cache,
    ) {}

    public function accepted(): void
    {
        $this->increment('accepted');
    }

    /** A console that reached a live upstream and started carrying bytes. */
    public function opened(): void
    {
        $this->increment('opened');
    }

    public function closed(string $why): void
    {
        $this->increment('closed');
    }

    public function upstreamFailed(): void
    {
        $this->increment('upstream_failed');
    }

    public function refused(string $reason): void
    {
        $this->increment('refused:'.(in_array($reason, self::REASONS, strict: true) ? $reason : 'other'));
    }

    /**
     * Read a counter back. Used by the collector and by tests.
     */
    public function read(string $key): int
    {
        $value = $this->cache->store()->get(self::PREFIX.$key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function increment(string $key): void
    {
        $store = $this->cache->store();

        /*
         * add() then increment(), rather than increment() alone. Laravel's
         * increment on a missing key is a no-op on some stores, which would
         * silently lose every count until something else created the key —
         * and a metric that is quietly zero is worse than one that is missing.
         */
        $store->add(self::PREFIX.$key, 0, self::TTL_SECONDS);
        $store->increment(self::PREFIX.$key);
    }
}
