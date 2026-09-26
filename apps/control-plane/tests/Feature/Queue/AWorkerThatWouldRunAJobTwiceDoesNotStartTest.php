<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Queue\RefuseAWorkerThatWouldRunAJobTwice;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;
use Throwable;

/**
 * The runtime half of F-08, through the real command path: a real
 * `php artisan queue:work` whose `--timeout` outlives its connection's retry
 * clock does not start.
 *
 * {@see RefuseAWorkerThatWouldRunAJobTwice} listens for `CommandStarting`, and
 * Laravel's console kernel raises that event only when it is not running unit
 * tests — that is, when `APP_ENV` is not `testing`. Every other test of the
 * guard calls its `handle()` directly
 * ({@see EveryQueueOutlivesItsLongestJobTest}), so nothing proved the listener
 * is registered and reached by a real worker: the re-audit measured the same
 * invocation starting (exit 0) under `APP_ENV=testing` and refused (exit 1)
 * under `APP_ENV=staging`, and no test run exercised the second.
 *
 * So the worker here is a child process under `APP_ENV=staging`, with
 * everything that could reach real state pointed at this run's own: the test
 * database, this run's Redis index, the array cache, the fake providers the
 * suite already exports, and a queue name nobody else uses. The refused case
 * stops before the worker pops anything. The accepted case — the same command
 * under the shipped clock — is the control that the refusal is the guard's
 * and not a child that could not boot: it starts, finds its private queue
 * empty and stops.
 */
final class AWorkerThatWouldRunAJobTwiceDoesNotStartTest extends TestCase
{
    #[Test]
    public function a_worker_whose_timeout_outlives_the_retry_clock_is_refused_as_it_starts(): void
    {
        $worker = $this->worker(retryAfter: 90, timeout: 120);

        $this->assertSame(1, $worker->getExitCode(), 'The worker started: '.$worker->getOutput().$worker->getErrorOutput());
        $this->assertStringContainsString('could run one job twice', $worker->getOutput().$worker->getErrorOutput());
    }

    #[Test]
    public function the_same_worker_under_the_shipped_clock_starts(): void
    {
        $worker = $this->worker(retryAfter: 180, timeout: 120);

        $this->assertSame(0, $worker->getExitCode(), 'The control worker did not start: '.$worker->getOutput().$worker->getErrorOutput());
        $this->assertStringNotContainsString('could run one job twice', $worker->getOutput().$worker->getErrorOutput());
    }

    private function worker(int $retryAfter, int $timeout): Process
    {
        $this->requireRedis();

        $process = new Process(
            [PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue=f08-probe-'.Str::lower((string) Str::ulid()), '--timeout='.$timeout, '--stop-when-empty', '--no-interaction'],
            base_path(),
            [
                // Not testing, so that the console kernel raises CommandStarting.
                'APP_ENV' => 'staging',
                'REDIS_QUEUE_RETRY_AFTER' => (string) $retryAfter,
                'QUEUE_CONNECTION' => 'redis',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                'REDIS_HOST' => (string) config('database.redis.default.host'),
                'REDIS_PORT' => (string) config('database.redis.default.port'),
                'REDIS_DB' => (string) RedisIndexForThisRun::resolve(),
            ],
            timeout: 60,
        );

        $process->run();

        return $process;
    }

    private function requireRedis(): void
    {
        try {
            config()->set('database.redis.default.database', RedisIndexForThisRun::resolve());
            $this->app->forgetInstance('redis');
            Redis::clearResolvedInstances();
            Redis::connection()->ping();
        } catch (Throwable $e) {
            if (($ci = getenv('CI')) !== false && $ci !== '' && $ci !== 'false' && $ci !== '0') {
                $this->fail('CI must run the worker-refusal proof, and Redis is not reachable: '.$e->getMessage());
            }

            $this->markTestSkipped('This test starts a real worker and needs a real Redis; there is none on this machine.');
        }
    }
}
