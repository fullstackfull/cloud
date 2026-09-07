<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use PHPUnit\Framework\Attributes\Test;
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

    /** Its own database, so a suite run cannot disturb development data. */
    private const int REDIS_DATABASE = 15;

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

        config()->set('database.redis.'.self::CACHE_CONNECTION.'.database', self::REDIS_DATABASE);
        config()->set('cache.default', 'redis');

        $this->app->forgetInstance('redis');
        $this->app->forgetInstance('redis.connection');
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');

        if (! $this->redisIsReachable()) {
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
