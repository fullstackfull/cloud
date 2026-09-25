<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A hosting account's primary domain is stored folded, and the column says so.
 *
 * ---------------------------------------------------------------------------
 * Why the index needed this
 * ---------------------------------------------------------------------------
 *
 * `hosting_accounts_live_primary_domain_unique` is a unique index over a raw
 * text column, so on its own it is a rule about bytes: `Example.test` and
 * `example.test` are one name to DNS and two to the index, and both could be
 * live at once. What made it a rule about NAMES was that the build folds the
 * name before it writes — and "the action folds it" is a property of the
 * action. What the index needs is a property of the column, true of every row
 * however it was written. That is this CHECK.
 *
 * The SQL fold is `lower(btrim(value, ' \t\n\r\v.'))`: the application's
 * `DnsName::canonicalAsSubmitted()` less NUL, which a PostgreSQL text value
 * cannot hold. `OneLiveHostingAccountPerDomainTest` reads the constraint back
 * out of `pg_constraint` and executes it beside the application's fold, so a
 * trim set narrowed here fails a test rather than deploying quietly. The two
 * agree for ASCII only (`lower()` follows the database locale; `strtolower`
 * is byte-wise), which is why a writer validates with
 * `DnsName::problemWith()`, refusing non-ASCII, before it folds.
 *
 * ---------------------------------------------------------------------------
 * What it does to rows that already exist
 * ---------------------------------------------------------------------------
 *
 * It folds them where folding is only a spelling correction, and refuses
 * where it would be a decision:
 *
 *  - a row whose name is not folded is rewritten to the folded form. That is
 *    the same name, spelled the way the column now requires;
 *
 *  - two LIVE rows whose names fold onto one name are refused, with the name
 *    in the message, and nothing is changed. Folding them would make the
 *    unique index fail halfway through, and choosing which of two customers'
 *    accounts keeps a name is an operator's decision, not a migration's.
 *
 * A terminated or failed row folding onto a name a live row holds is history,
 * not a collision — the index does not cover it — so it is folded like any
 * other.
 *
 * The scan groups on the FOLDED name. Grouping on the raw value reports zero
 * collisions for `Example.test` beside `example.test`, and would then fold
 * both onto one name under a unique index that refuses the second.
 */
return new class extends Migration
{
    private const string CONSTRAINT = 'hosting_accounts_primary_domain_canonical';

    /** The fold, in SQL. Held in step with DnsName::canonicalAsSubmitted() by a test that executes it. */
    private const string FOLD = "lower(btrim(primary_domain, E' \\t\\n\\r\\x0B.'))";

    public function up(): void
    {
        DB::transaction(function (): void {
            $collisions = DB::table('hosting_accounts')
                ->selectRaw(self::FOLD.' as folded')
                ->whereIn('status', ['pending', 'active', 'suspended'])
                ->groupByRaw(self::FOLD)
                ->havingRaw('count(*) > 1')
                ->pluck('folded')
                ->all();

            if ($collisions !== []) {
                throw new RuntimeException(sprintf(
                    'These names are served by more than one live hosting account once case, whitespace and dots '
                    .'are folded, and one account per name has to be chosen before this migration can run: %s',
                    implode(', ', $collisions),
                ));
            }

            DB::statement(sprintf(
                'update hosting_accounts set primary_domain = %1$s where primary_domain <> %1$s',
                self::FOLD,
            ));

            DB::statement(sprintf(
                'alter table hosting_accounts add constraint %s check (primary_domain = %s)',
                self::CONSTRAINT,
                self::FOLD,
            ));
        });
    }

    public function down(): void
    {
        // The fold is not undone: the rows hold the same names, spelled once.
        DB::statement(sprintf('alter table hosting_accounts drop constraint if exists %s', self::CONSTRAINT));
    }
};
