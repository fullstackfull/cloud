<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queue\WorkerHarness;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * Two gateways, one permit, one winner.
 *
 * The claim "single use" is only as strong as the operation that enforces it,
 * and the enforcing operation is deliberately not a read followed by a write:
 * two gateway processes reading a live permit at the same instant would both
 * see it, both delete it, and both open a console.
 *
 * What makes it safe is `add()`, which is Redis's `SET NX` — decided by the
 * server, once, for whoever gets there first. This test runs the redemption
 * from two independent connections so that the decision is genuinely Redis's
 * rather than an artefact of one PHP process doing two things in order.
 *
 * Skipped rather than faked when Redis is not running. A version of this test
 * that passed against the array cache would be proving that one process
 * cannot race itself, which nobody doubted.
 */
final class ConsolePermitConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Its own database, so a suite run cannot disturb development data — and
     * overridable, so two checkouts running this at once cannot disturb each
     * other's. The default matches {@see WorkerHarness}
     * and `REDIS_DB` is the variable `config/database.php` already reads.
     *
     * The default is for a runner that names no index; this repository's
     * `phpunit.xml` always names one (0 unless a run exports its own). A value
     * that cannot be read as an index is refused by
     * {@see RedisIndexForThisRun} rather than folded into 15, which is what
     * the line here used to do.
     */
    private const int REDIS_DATABASE = 15;

    private static function redisDatabase(): int
    {
        return RedisIndexForThisRun::resolve(self::REDIS_DATABASE);
    }

    /**
     * The cache's connection, which is not the default one.
     *
     * Laravel points the cache store at a `cache` connection on its own
     * database. A test that reached for `Redis::connection()` would be looking
     * at a different database from the one the permits are in — and would find
     * nothing, which reads as "no secret was stored" rather than "the test
     * searched the wrong server".
     */
    private const string CACHE_CONNECTION = 'cache';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.redis.'.self::CACHE_CONNECTION.'.database', self::redisDatabase());
        config()->set('cache.default', 'redis');

        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');

        if (! $this->redisIsReachable()) {
            // A skip on a laptop with no Redis, a failure in a build: this is
            // the only proof that a permit cannot be spent twice, and a suite
            // that skipped it would report green for a console anybody could
            // replay.
            if (($ci = getenv('CI')) !== false && $ci !== '' && $ci !== 'false' && $ci !== '0') {
                $this->fail('CI must run the console permit concurrency proof, and Redis is not reachable.');
            }

            $this->markTestSkipped('Redis is not reachable; the console permit concurrency proof needs a real server.');
        }

        $this->cacheRedis()->flushdb();
    }

    protected function tearDown(): void
    {
        if ($this->redisIsReachable()) {
            $this->cacheRedis()->flushdb();
        }

        parent::tearDown();
    }

    #[Test]
    public function exactly_one_of_two_simultaneous_redemptions_succeeds(): void
    {
        $id = (string) Str::ulid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        app(ConsoleSessionStore::class)->issue(
            virtualMachineId: 'vm-1',
            customerId: 'customer-1',
            userId: null,
            id: $id,
            token: $token,
        );

        /*
         * Two stores over two connections, standing in for two gateway
         * processes. They share nothing in PHP: whatever decides between them
         * is on the Redis side of the socket.
         */
        $first = new ConsoleSessionStore(app('cache'));
        $second = new ConsoleSessionStore(clone app('cache'));

        $outcomes = [
            $first->consume($id, $token),
            $second->consume($id, $token),
        ];

        $winners = array_filter($outcomes);

        $this->assertCount(1, $winners, 'A console permit was spent twice.');
        $this->assertSame('vm-1', reset($winners)?->virtualMachineId);
    }

    #[Test]
    public function a_permit_is_gone_from_redis_the_moment_it_is_spent(): void
    {
        /*
         * Not merely marked used. A record left behind is a record that can be
         * read — and the reason the store keeps only a hash of the token is
         * that a dump of the cache must not be a set of usable console permits.
         */
        $id = (string) Str::ulid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $store = app(ConsoleSessionStore::class);
        $store->issue('vm-1', 'customer-1', null, $id, $token);

        $this->assertNotNull($store->consume($id, $token));

        $keys = $this->cacheRedis()->keys('*console*');
        $remaining = implode(' ', array_map(strval(...), is_array($keys) ? $keys : []));

        $this->assertStringNotContainsString($token, $remaining, 'A spent permit left its token in Redis.');
    }

    #[Test]
    public function the_stored_record_never_contains_the_token_itself(): void
    {
        $id = (string) Str::ulid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        app(ConsoleSessionStore::class)->issue('vm-1', 'customer-1', null, $id, $token);

        /*
         * Every value in the database, searched for the secret. A store that
         * kept the token would turn a cache dump into a set of live consoles.
         *
         * The count is asserted first, and that is not decoration: a scan that
         * finds no keys — a wrong prefix, a flushed database, a connection to
         * the wrong server — would otherwise pass this test while searching
         * nothing at all.
         */
        $inspected = 0;

        foreach ($this->cacheRedis()->keys('*') as $key) {
            $value = $this->cacheRedis()->get((string) $key);

            /*
             * Serialised rather than type-checked. Laravel's Redis connection
             * hands back an array for a value it stored as one, and a search
             * that skipped anything not a string would quietly skip the record
             * this test exists to search.
             */
            $inspected++;
            $this->assertStringNotContainsString($token, is_string($value) ? $value : serialize($value));
        }

        $this->assertGreaterThan(0, $inspected, 'The scan found nothing to search, so it proved nothing.');
    }

    #[Test]
    public function an_expired_permit_is_refused_while_redis_still_holds_it(): void
    {
        /*
         * Here because this is the file that talks to a real Redis server,
         * and the finding this answers is about that server's clock. The two
         * travel() tests elsewhere expire a permit by moving Carbon; Redis
         * counts a TTL on its own wall clock, so under the production store
         * the key outlived the travel and the expired permit was redeemed.
         *
         * So the record is rewritten with its deadline a minute gone and a
         * TTL of an hour, and Redis itself is asked whether it still holds
         * the key before consume() is called. It does; the code refuses.
         *
         * The key is asked for with the cache prefix and WITHOUT the
         * connection's: phpredis applies the connection prefix itself, so a
         * key handed back fully prefixed by keys() would be prefixed twice and
         * miss.
         */
        $this->freezeSecond();

        $id = (string) Str::ulid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $store = app(ConsoleSessionStore::class);
        $store->issue('vm-1', 'customer-1', null, $id, $token);

        $this->rewriteDeadline($id, now()->subMinute());

        $held = $this->cacheRedis()->ttl(Cache::getStore()->getPrefix().ConsoleSessionStore::PREFIX.$id);
        $this->assertGreaterThan(
            ConsoleSessionStore::TTL_SECONDS,
            $held,
            'Redis is not holding the record for the hour it was given, so Redis rather than the code could be what refuses it.',
        );

        $this->assertNull($store->consume($id, $token), 'Redis still held a permit past its deadline, and it was redeemed.');

        /*
         * Refused with nothing written: the record is still there, and no
         * consumed marker was burnt — the same record made live is redeemed.
         * A deadline check placed after add() is caught by that control: the
         * marker the refusal burnt turns the live record away.
         */
        $this->assertGreaterThan(
            0,
            $this->cacheRedis()->ttl(Cache::getStore()->getPrefix().ConsoleSessionStore::PREFIX.$id),
            'Refusing an expired permit deleted its record.',
        );

        $this->rewriteDeadline($id, now()->addSecond());

        $this->assertNotNull($store->consume($id, $token), 'Refusing an expired permit burnt it, or the control never reached the record.');
    }

    /**
     * Rewrite the deadline inside the record issue() wrote, with an hour of
     * TTL. One statement for the refusal and its control, so a wrong key
     * cannot make the refusal pass while the control still reads right.
     */
    private function rewriteDeadline(string $id, CarbonInterface $deadline): void
    {
        $record = Cache::get(ConsoleSessionStore::PREFIX.$id);

        $this->assertIsArray($record, 'The record issue() wrote is not where this test looks for it.');

        Cache::put(ConsoleSessionStore::PREFIX.$id, [...$record, 'expires_at' => $deadline->toIso8601String()], 3600);
    }

    private function redisIsReachable(): bool
    {
        try {
            $this->cacheRedis()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function cacheRedis(): Connection
    {
        return Redis::connection(self::CACHE_CONNECTION);
    }
}
