<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Env;
use RuntimeException;

/**
 * Which Redis database a suite that empties one may use, for this run.
 *
 * Two suites empty a whole Redis database with `flushdb` before every test —
 * the worker harness and the console permit concurrency proof — and each used
 * to decide the index with its own copy of one line:
 *
 *     is_numeric($configured) ? (int) $configured : 15;
 *
 * That line folds every value it cannot read into 15. `REDIS_DB=foo`, `''`,
 * `3a`, `three` all landed on index 15 while the run believed it was isolated,
 * and 15 is the one index where a run that forgets the variable also lands, so
 * a typo in one checkout's variable put it on top of every forgetful run and
 * the `flushdb` in each deleted the other's queued messages mid-test. It also
 * read `4.5` as 4, because `is_numeric('4.5')` is true.
 *
 * So the rule lives here, once, and distinguishes the two cases the old line
 * merged:
 *
 *  - **Absent** returns the caller's fallback. A runner that never set the
 *    variable made no claim about isolation; it gets the suite's default.
 *    Under this repository's `phpunit.xml` the variable is never absent — the
 *    file pins `REDIS_DB` as a default an exported value overrides — so the
 *    fallback is reached only by a runner that does not use that file, such as
 *    `vendor/bin/phpunit -c <a copy without the entry>`.
 *  - **Set and not a non-negative integer written in digits** throws. A runner
 *    that set it unreadably made a claim about isolation that is false, and
 *    the place to say so is here, before anything is flushed. That includes
 *    `false`, `true`, `null` and `(null)`, which Laravel's `env()` would have
 *    turned into a boolean or a null and so made indistinguishable from
 *    absent: the raw repository value is read instead.
 */
final class RedisIndexForThisRun
{
    public const string VARIABLE = 'REDIS_DB';

    /** The index this run owns, or `$fallback` when nothing names one. */
    public static function resolve(int $fallback): int
    {
        return self::from(Env::getRepository()->get(self::VARIABLE), $fallback);
    }

    /** The same rule over a value already read, for the tests that pin it. */
    public static function from(?string $configured, int $fallback): int
    {
        if ($configured === null) {
            return $fallback;
        }

        if (preg_match('/\A[0-9]+\z/', $configured) !== 1) {
            throw new RuntimeException(sprintf(
                '%s is set to "%s", which is not a Redis database index. A suite that empties its Redis database refuses to guess which one this run owns: set it to a whole number, or unset it.',
                self::VARIABLE,
                $configured,
            ));
        }

        return (int) $configured;
    }
}
