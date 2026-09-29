<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One live hosting account per primary domain.
 *
 * ---------------------------------------------------------------------------
 * Why a constraint and not a check in the action
 * ---------------------------------------------------------------------------
 *
 * Two customers who each buy hosting for `shop.example.test` would each get a
 * panel account serving it, and DNS can point at only one of them. The build
 * asks whether the name is free before it takes a slot, but two builds racing
 * each other both ask before either writes, and both are told yes. A database
 * constraint is the only answer that survives a concurrent double-submit.
 *
 * ---------------------------------------------------------------------------
 * Why partial
 * ---------------------------------------------------------------------------
 *
 * A terminated account has released everything at the panel, and a failed one
 * never had anything there: the name is free again, and a customer who comes
 * back must be able to buy hosting for it. So the index covers exactly the
 * accounts the panel is expected to hold — `HostingAccountStatus::
 * existsAtPanel()`: pending, active, suspended. The predicate is spelled out
 * in SQL because a partial index cannot call PHP;
 * `OneLiveHostingAccountPerDomainTest` holds the two in step.
 *
 * A unique index over a raw text column is a rule about BYTES, not names:
 * `Shop.Example.Test` and `shop.example.test` would both stand. That is what
 * `2026_04_26_000000_a_hosting_account_serves_a_canonical_name` closes, with a
 * CHECK that every stored value is already folded.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * An existing collision is a data problem an operator has to resolve,
         * and creating the index would fail with a message naming only the
         * duplicate. Reported here first so the failure says what to fix.
         */
        $collisions = DB::table('hosting_accounts')
            ->select('primary_domain')
            ->whereIn('status', ['pending', 'active', 'suspended'])
            ->groupBy('primary_domain')
            ->havingRaw('count(*) > 1')
            ->pluck('primary_domain')
            ->all();

        if ($collisions !== []) {
            throw new RuntimeException(sprintf(
                'These domains are served by more than one live hosting account and have to be resolved before '
                .'this migration can run (terminate the account that should not exist, or correct its name): %s',
                implode(', ', $collisions),
            ));
        }

        DB::statement(
            'create unique index hosting_accounts_live_primary_domain_unique on hosting_accounts (primary_domain) '
            ."where status in ('pending', 'active', 'suspended')",
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists hosting_accounts_live_primary_domain_unique');
    }
};
