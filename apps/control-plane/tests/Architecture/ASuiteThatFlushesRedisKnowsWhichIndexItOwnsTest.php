<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Console\ConsolePermitConcurrencyTest;
use Tests\Feature\Queue\WorkerHarness;
use Tests\Support\RedisIndexForThisRun;

/**
 * A run that names its Redis index unreadably is refused, not moved to 15.
 *
 * The two suites that `flushdb` a whole Redis database used to fold every
 * value of `REDIS_DB` they could not read into index 15 — the same index a run
 * that forgets the variable lands on — so a typo put a run that believed it
 * was isolated on top of every forgetful one, each emptying the other's queue
 * mid-test. {@see RedisIndexForThisRun} holds the rule once; this pins the
 * rule, and pins that both suites use it, by setting the variable the way an
 * exported one arrives and asking each suite which index it would flush.
 */
final class ASuiteThatFlushesRedisKnowsWhichIndexItOwnsTest extends TestCase
{
    /** @var array{server: ?string, env: ?string, getenv: string|false}|null */
    private ?array $saved = null;

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            $this->restore($this->saved);
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        foreach (['foo', '', '3a', 'three', '4.5', '-1', ' 4', '0x1', 'false', 'null'] as $value) {
            yield var_export($value, true) => [$value];
        }
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function an_unreadable_index_is_refused_by_the_rule(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a Redis database index');

        RedisIndexForThisRun::from($value, 15);
    }

    #[Test]
    public function a_whole_number_is_the_index_and_absence_is_the_fallback(): void
    {
        $this->assertSame(0, RedisIndexForThisRun::from('0', 15));
        $this->assertSame(70, RedisIndexForThisRun::from('70', 15));
        $this->assertSame(15, RedisIndexForThisRun::from(null, 15));
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function suitesThatFlush(): iterable
    {
        yield 'the worker harness' => [WorkerHarness::class, 'redisDatabase'];
        yield 'the console permit concurrency proof' => [ConsolePermitConcurrencyTest::class, 'redisDatabase'];
    }

    /**
     * @param  class-string  $suite
     */
    #[Test]
    #[DataProvider('suitesThatFlush')]
    public function both_suites_refuse_an_exported_index_they_cannot_read(string $suite, string $method): void
    {
        $this->export('foo');

        try {
            $index = self::indexOf($suite, $method);
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('is not a Redis database index', $refusal->getMessage());

            return;
        }

        $this->fail("{$suite} would flush Redis index {$index} for REDIS_DB=foo; it must refuse an index it cannot read.");
    }

    /**
     * @param  class-string  $suite
     */
    #[Test]
    #[DataProvider('suitesThatFlush')]
    public function both_suites_use_an_exported_index_they_can_read(string $suite, string $method): void
    {
        $this->export('7');

        $this->assertSame(7, self::indexOf($suite, $method));
    }

    /**
     * @param  class-string  $suite
     */
    #[Test]
    #[DataProvider('suitesThatFlush')]
    public function both_suites_fall_back_to_their_own_index_when_nothing_names_one(string $suite, string $method): void
    {
        $this->export(null);

        $this->assertSame(15, self::indexOf($suite, $method));
    }

    /** @param class-string $suite */
    private static function indexOf(string $suite, string $method): int
    {
        $reflection = new ReflectionMethod($suite, $method);

        /** @var int */
        return $reflection->invoke(null);
    }

    /** Sets REDIS_DB in every place an exported variable arrives, or removes it. */
    private function export(?string $value): void
    {
        $this->saved ??= [
            'server' => $_SERVER[RedisIndexForThisRun::VARIABLE] ?? null,
            'env' => $_ENV[RedisIndexForThisRun::VARIABLE] ?? null,
            'getenv' => getenv(RedisIndexForThisRun::VARIABLE),
        ];

        $this->restore(['server' => $value, 'env' => $value, 'getenv' => $value ?? false]);
    }

    /** @param array{server: ?string, env: ?string, getenv: string|false} $state */
    private function restore(array $state): void
    {
        $name = RedisIndexForThisRun::VARIABLE;

        if ($state['server'] === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $state['server'];
        }

        if ($state['env'] === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $state['env'];
        }

        putenv($state['getenv'] === false ? $name : "{$name}={$state['getenv']}");
    }
}
