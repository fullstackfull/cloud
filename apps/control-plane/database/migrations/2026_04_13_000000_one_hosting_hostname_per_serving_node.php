<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A hostname names one serving hosting node, and answers the question Gap 5
 * left open.
 *
 * Gap 5 recorded `hosting_nodes.hostname` as "not unique" and said why: "Stated
 * as not unique rather than assumed unique; whether two rows should ever share
 * a hostname is a domain question nobody has answered." This answers it.
 *
 * ---------------------------------------------------------------------------
 * Why it cannot be unique outright
 * ---------------------------------------------------------------------------
 *
 * A chassis is replaced and the new machine takes the old one's name. That is
 * ordinary operations, and a blanket unique index would force an operator to
 * rename the retired row before they could record its replacement — a rename
 * that destroys the history of which machine the accounts used to be on.
 *
 * ---------------------------------------------------------------------------
 * And why it cannot be left alone
 * ---------------------------------------------------------------------------
 *
 * Two nodes that are both serving under one hostname are two panels the
 * platform believes are different machines and one name that resolves to one
 * of them. The scheduler places an account on node A, the adapter talks to
 * whichever the name answers with, and the account exists on a machine the
 * platform's own records say is somewhere else — which then fails
 * reconciliation as an orphan on one node and a missing account on the other.
 *
 * ---------------------------------------------------------------------------
 * So: unique among the nodes that still hold accounts
 * ---------------------------------------------------------------------------
 *
 * `HostingNodeStatus::holdsAccounts()` is the platform's own name for that set
 * — everything except `offline`. The predicate is spelled out in SQL rather
 * than derived, because a partial index cannot call PHP; the architecture test
 * beside it holds the two in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Any existing collision is a data problem an operator has to resolve,
         * and creating the index would fail with a message naming the
         * duplicate. Reported here first so the failure says what to fix
         * rather than only that something is duplicated.
         */
        $collisions = DB::table('hosting_nodes')
            ->select('hostname')
            ->whereIn('status', ['active', 'draining', 'maintenance'])
            ->groupBy('hostname')
            ->havingRaw('count(*) > 1')
            ->pluck('hostname')
            ->all();

        if ($collisions !== []) {
            throw new RuntimeException(sprintf(
                'These hostnames are held by more than one serving hosting node and have to be resolved before this '
                .'migration can run (retire the node that is gone, or correct the name): %s',
                implode(', ', $collisions),
            ));
        }

        DB::statement(
            'create unique index hosting_nodes_serving_hostname_unique on hosting_nodes (hostname) '
            ."where status in ('active', 'draining', 'maintenance')",
        );
    }

    public function down(): void
    {
        Schema::table('hosting_nodes', function (): void {
            DB::statement('drop index if exists hosting_nodes_serving_hostname_unique');
        });
    }
};
