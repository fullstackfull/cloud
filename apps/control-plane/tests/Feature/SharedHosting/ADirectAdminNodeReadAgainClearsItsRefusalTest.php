<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A DirectAdmin node whose listing was refused, and is read on a later sweep,
 * no longer says it could not be compared (a residue the round-six verifiers
 * recorded: the success half of the reconciliation's record was unpinned).
 *
 * The refusal is kept on the node (`reconcile_error`) and shown on the
 * operator's node list as "Accounts not compared". A sweep that reads the
 * listing clears it and stamps the attempt as well as the comparison; left
 * behind, the node would go on reading as uncompared, and an attempt stamp
 * left old would put a node that answers at the front of every sweep.
 */
final class ADirectAdminNodeReadAgainClearsItsRefusalTest extends TestCase
{
    use RefreshDatabase;

    private string $listing = 'list[]=bob,alice';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-06-15 12:00:00');
        $this->app->singleton(HostingProviderFactory::class);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'CMD_API_SHOW_USERS')
            ? Http::response($this->listing, 200)
            : Http::response('error=1&text=not+faked', 200));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function a_listing_read_after_a_refusal_clears_the_refusal_and_stamps_the_attempt(): void
    {
        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::DirectAdmin,
            'status' => HostingNodeStatus::Active,
            'account_count' => 0,
        ]);
        config(['hosting.credentials.'.$node->credentials_reference => [
            'username' => 'admin',
            'login_key' => 'da-login-key-FEATURE-TEST',
        ]]);

        // Two names run together in one element: refused, and recorded.
        app(ReconcileHostingNodes::class)->execute();

        $refused = $node->fresh();
        $this->assertNotNull($refused?->reconcile_error, 'The refusal was not recorded, so nothing is measured.');
        $this->assertNull($refused->reconciled_at);

        // An hour later the panel answers with a listing it can be read from.
        CarbonImmutable::setTestNow('2026-06-15 13:00:00');
        $this->listing = 'list[]=bob';

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, $outcome['nodes']);
        $read = $node->fresh();
        $this->assertNull($read?->reconcile_error, 'A node that was read still says it could not be compared.');
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $read->reconcile_attempted_at?->getTimestamp(), 'The read did not stamp the attempt.');
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $read->reconciled_at?->getTimestamp());
    }
}
