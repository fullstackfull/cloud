<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\SharedHosting\ACpanelListingThatNamesNobodyReadableIsRefusedTest;

/**
 * The sweep raises no alarm about a cPanel node's accounts from a listing that
 * names nobody it can read (re-audit after round six, band C, unnumbered).
 *
 * Each body below used to be read as a node with no accounts, or with one
 * nobody has, and the sweep recorded the node's live account as Critical
 * missing_at_provider drift with nothing on the node to say why. Now the
 * adapter refuses each, the sweep concludes nothing, and the refusal is kept
 * on the node. The adapter's side is held by
 * {@see ACpanelListingThatNamesNobodyReadableIsRefusedTest}.
 */
final class ACpanelListingThatNamesNobodyReadableRaisesNoAlarmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function bodiesNamingNobodyReadable(): iterable
    {
        yield 'no data' => [['metadata' => ['result' => 1]]];
        yield 'data without acct' => [['metadata' => ['result' => 1], 'data' => ['foo' => 'bar']]];
        yield 'rows keyed by name, not user' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['name' => 'liveone']]]]];
        yield 'rows as bare strings' => [['metadata' => ['result' => 1], 'data' => ['acct' => ['liveone']]]];
        yield 'acct as one object' => [['metadata' => ['result' => 1], 'data' => ['acct' => ['user' => 'liveone']]]];
        yield 'acct as rows keyed by name' => [['metadata' => ['result' => 1], 'data' => ['acct' => ['liveone' => ['user' => 'liveone']]]]];
        yield 'a user holding two names' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'liveone,livetwo']]]]];
    }

    #[Test]
    #[DataProvider('bodiesNamingNobodyReadable')]
    public function the_live_account_is_not_reported_missing_and_the_node_says_why(array $body): void
    {
        $node = $this->nodeWithOneLiveAccount();
        Http::fake(['*' => Http::response($body, 200)]);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(0, $outcome['nodes']);
        $this->assertSame(0, ResourceDrift::query()->count(), 'Drift was concluded from a listing that named nobody readable.');
        $read = $node->fresh();
        $this->assertStringContainsString('cannot be read as the accounts on the node', (string) $read?->reconcile_error);
        $this->assertSame('2026-09-10 12:00:00', $read->reconcile_attempted_at?->format('Y-m-d H:i:s'));
        $this->assertNull($read->reconciled_at);
    }

    #[Test]
    public function an_empty_listing_is_still_read_as_a_node_with_no_accounts(): void
    {
        $node = $this->nodeWithOneLiveAccount();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]], 200)]);

        app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(1, ResourceDrift::query()->where('kind', DriftKind::MissingAtProvider->value)->where('provider_reference', 'liveone')->count());
        $this->assertNull($node->fresh()?->reconcile_error);
    }

    #[Test]
    public function a_listing_that_names_the_account_is_read(): void
    {
        $node = $this->nodeWithOneLiveAccount();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'liveone']]]], 200)]);

        $outcome = app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(['nodes' => 1, 'accounts' => 1, 'drifts' => 0], $outcome);
        $this->assertSame('2026-09-10 12:00:00', $node->fresh()?->reconciled_at?->format('Y-m-d H:i:s'));
    }

    private function nodeWithOneLiveAccount(): HostingNode
    {
        config(['hosting.credentials.node-cp' => ['user' => 'root', 'api_token' => 'K7QW3XJ9ZP2LMND4RTVB8HYC6FGA5SEU']]);

        $node = HostingNode::factory()->create([
            'slug' => 'node-cp',
            'hostname' => 'node-cp.lynomia.test',
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => 'https://node-cp.lynomia.test:2087',
            'credentials_reference' => 'node-cp',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 1,
        ]);

        HostingAccount::factory()->named('liveone')->create([
            'hosting_node_id' => $node->getKey(),
            'status' => HostingAccountStatus::Active,
        ]);

        return $node;
    }
}
