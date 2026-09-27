<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * A deadlock or serialization failure on one node, with no caller's
 * transaction open — as `hosting:reconcile` runs — stops that node and not the
 * sweep.
 *
 * The node's own transaction is then the outermost, so Laravel rolls it back
 * whole and the connection is usable: the failure is recorded on the node like
 * any other. Inside a caller's transaction the same failure is let out as it
 * was thrown ({@see AFailureWhileReconcilingOneNodeStopsOnlyThatNodeTest}).
 * These rows are committed, as they are in production, so the test holds no
 * transaction of its own.
 */
final class AConcurrencyFailureWithNoCallersTransactionStopsOnlyThatNodeTest extends TestCase
{
    use LeavesNothingCommitted;

    protected function tearDown(): void
    {
        DB::unprepared('drop trigger if exists r7_refuse_drift on resource_drifts; drop function if exists r7_refuse_drift();');

        $this->emptyEveryTable();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function concurrencyFailures(): iterable
    {
        yield 'a deadlock' => ['40P01', 'deadlock detected'];
        yield 'a serialization failure' => ['40001', 'could not serialize access due to concurrent update'];
    }

    #[Test]
    #[DataProvider('concurrencyFailures')]
    public function the_failed_node_is_recorded_and_the_node_behind_it_is_compared(string $sqlState, string $message): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'This is the case with no caller\'s transaction.');

        DB::unprepared(<<<SQL
            create function r7_refuse_drift() returns trigger language plpgsql as \$\$
            begin
                if new.provider_reference = 'poison' then
                    raise exception '{$message}' using errcode = '{$sqlState}';
                end if;
                return new;
            end
            \$\$;
            create trigger r7_refuse_drift before insert on resource_drifts
                for each row execute function r7_refuse_drift();
            SQL);

        $failing = $this->node('node-fails', '2026-09-01 00:00:00');
        $fine = $this->node('node-fine', '2026-09-02 00:00:00');
        Http::fake(static fn (Request $request) => Http::response(
            str_contains($request->url(), 'node-fails.') ? 'list[]=poison' : 'list[]=stranger',
            200,
        ));

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['failed']);
        $this->assertSame(1, $outcome['nodes']);
        $this->assertStringContainsString('Reconciliation failed while comparing', (string) $failing->fresh()?->reconcile_error);
        $this->assertNull($fine->fresh()?->reconcile_error);
        $this->assertNotNull($fine->fresh()?->reconciled_at);
        $this->assertSame(['stranger'], ResourceDrift::query()->pluck('provider_reference')->all());
    }

    private function node(string $slug, string $asked): HostingNode
    {
        config(['hosting.credentials.'.$slug => ['username' => 'admin', 'login_key' => 'da-login-key-'.$slug]]);

        return HostingNode::factory()->create([
            'slug' => $slug,
            'hostname' => $slug.'.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://'.$slug.'.lynomia.test:2222',
            'credentials_reference' => $slug,
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
            'reconcile_attempted_at' => $asked,
            'reconciled_at' => $asked,
        ]);
    }
}
