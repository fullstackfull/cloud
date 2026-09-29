<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncHostingNodeHealth;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F-14, end to end: a DirectAdmin node whose licence answer cannot be read is
 * not recorded licensed, and so is not a candidate for a paid order.
 *
 * Driven through the action that writes the row the scheduler reads, on a real
 * `hosting_nodes` row, with the panel's answer faked at the HTTP boundary and
 * nowhere else. Each row is its own test case: one `Http::fake()` per test,
 * because a second fake in the same test never answers.
 */
final class AnUnreadableDirectAdminNodeIsNotSoldTest extends TestCase
{
    use RefreshDatabase;

    private const string SYSTEM_INFO = 'loadavg1=0.20&loadavg5=0.30&loadavg15=0.40&version=1.665';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-06-15 12:00:00');
        $this->app->singleton(HostingProviderFactory::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function answersThatDoNotLicenseANode(): array
    {
        return [
            'nothing a licence answer carries' => ['foo=bar'],
            'no status word' => ['expires=2099-12-31'],
            'the panel says expired' => ['status=expired&expires=2099-12-31'],
            'an expiry already past' => ['status=active&expires=2020-01-01'],
            'an unreadable expiry' => ['status=active&expires=not-a-date-at-all'],
            'a repeated status' => ['status=expired&status=active'],
            'a leading NUL erasing the panel\'s word' => ['%00status=expired&status=active'],
            'relative expiry' => ['status=active&expiry=tomorrow'],
            'an expired licence with a second year after it' => ['status=active&expires=2020-01-01+9999'],
            'a five-digit year behind a zone' => ['status=active&expires=UTC%2B22099-01-01'],
            'a millisecond timestamp' => ['status=active&expires=4102358400000'],
            'an exponent that used to throw out of the sync' => ['status=active&expires=1e15'],
        ];
    }

    #[Test]
    #[DataProvider('answersThatDoNotLicenseANode')]
    public function a_node_whose_licence_answer_cannot_be_trusted_is_recorded_unlicensed(string $licenceBody): void
    {
        $node = $this->directAdminNode();
        $this->fakePanel($licenceBody);

        app(SyncHostingNodeHealth::class)->execute($node);

        $node->refresh();
        $this->assertFalse($node->panel_licensed, 'An untrustworthy licence answer licensed the node.');
        $this->assertNotContains($node->licence_status, ['active', 'valid']);
        // Why, on the row, for the operator who has to act on it.
        $this->assertNotNull($node->last_sync_error);
        $this->assertFalse($node->isLicensed());
    }

    #[Test]
    public function a_node_whose_panel_says_it_is_licensed_stays_licensed(): void
    {
        $node = $this->directAdminNode();
        $this->fakePanel('status=active&expires=2099-12-31');

        $this->assertTrue(app(SyncHostingNodeHealth::class)->execute($node));

        $node->refresh();
        $this->assertTrue($node->panel_licensed);
        $this->assertSame('active', $node->licence_status);
        $this->assertNull($node->last_sync_error);
        $this->assertTrue($node->isLicensed());
    }

    private function fakePanel(string $licenceBody): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), 'CMD_API_LICENSE')
            ? Http::response($licenceBody, 200)
            : Http::response(self::SYSTEM_INFO, 200));
    }

    private function directAdminNode(): HostingNode
    {
        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::DirectAdmin,
            'panel_licensed' => true,
            'licence_status' => 'active',
            'last_sync_error' => null,
        ]);

        config(['hosting.credentials.'.$node->credentials_reference => [
            'username' => 'admin',
            'login_key' => 'da-login-key-FEATURE-TEST',
        ]]);

        return $node;
    }
}
