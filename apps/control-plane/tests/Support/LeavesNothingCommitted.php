<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Puts the database back for tests that commit.
 *
 * ---------------------------------------------------------------------------
 * Why a bespoke clean-up list is not enough
 * ---------------------------------------------------------------------------
 *
 * A genuine race needs genuine concurrency, and a second connection cannot see
 * a row that has never been committed — so the handful of tests in this suite
 * that race real workers switch RefreshDatabase's transaction off and commit
 * for real. Everything they write then survives them.
 *
 * Each such test used to name the tables it expected to have touched. That
 * list is wrong the moment the code under test writes one more row: a service,
 * an audit entry, a notification, an address pool a factory created three
 * levels down. What follows is not a failure in the test that leaked — it is a
 * failure two hundred tests later, in an unrelated module, whose `sole()` finds
 * two rows and whose count is off by one. That is a genuinely expensive kind of
 * failure to read, because nothing in the message points at the cause.
 *
 * So nothing is named. Every table the migrations created is emptied, which is
 * exactly the state RefreshDatabase would have left behind, and a new table
 * added next year is covered without anybody remembering to add it here.
 *
 * `migrations` itself is kept: dropping it would make the next test run
 * re-migrate a database that is already migrated.
 */
trait LeavesNothingCommitted
{
    protected function tearDown(): void
    {
        $this->emptyEveryTable();

        parent::tearDown();
    }

    protected function emptyEveryTable(): void
    {
        /** @var list<string> $tables */
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->whereNotIn('table_name', ['migrations'])
            ->pluck('table_name')
            ->all();

        if ($tables === []) {
            return;
        }

        $quoted = implode(', ', array_map(static fn (string $t): string => '"'.$t.'"', $tables));

        // One statement, so no foreign key is momentarily unsatisfied, and
        // CASCADE so the order the tables come back in does not matter.
        DB::statement('TRUNCATE '.$quoted.' RESTART IDENTITY CASCADE');
    }
}
