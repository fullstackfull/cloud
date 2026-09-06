<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Illuminate\Support\Facades\Http;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\FakeHostingProviderInProductionException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Services\FakeHostingProviderGuard;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The panel that creates nothing.
 *
 * The property that matters most is the one it refuses to have: it cannot
 * exist in production. It reports accounts as created without creating them,
 * so in production it would mark services active, send welcome mail with login
 * details, and raise invoices for hosting nobody is serving — with the
 * customer, not the platform, discovering it.
 */
final class FakeHostingProviderTest extends TestCase
{
    #[Test]
    public function the_fake_panel_refuses_to_be_constructed_in_production(): void
    {
        // The guard fires on construction rather than on resolution, which is
        // what catches a node row whose panel column says "fake" and a test
        // double left bound in a service provider.
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(FakeHostingProviderInProductionException::class);

        new FakeHostingProvider;
    }

    #[Test]
    public function the_production_guard_reports_a_stable_error_code(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            FakeHostingProviderGuard::assertNotProduction('fake');

            $this->fail('The guard allowed a fake panel in production.');
        } catch (FakeHostingProviderInProductionException $e) {
            $this->assertSame('hosting.fake_provider_in_production', $e->errorCode());
        }
    }

    #[Test]
    public function it_reaches_no_network_at_all(): void
    {
        Http::preventStrayRequests();

        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest('acme'));
        $provider->accountUsage($node, 'acme');
        $provider->listAccounts($node);
        $provider->nodeHealth($node);
        $provider->licenceStatus($node);
        $provider->createSsoSession($node, 'acme');

        // Http::preventStrayRequests() would have thrown on any real request.
        $this->assertTrue(true);
    }

    #[Test]
    public function usage_is_deterministic_for_the_same_account(): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest('acme'));

        // The same figures in every process that asks, which is what lets a
        // queue worker that never saw the create report correctly.
        $this->assertSame(
            $provider->accountUsage($node, 'acme')->diskUsedMib,
            $provider->accountUsage($node, 'acme')->diskUsedMib,
        );
    }

    #[Test]
    public function the_no_usage_marker_reports_no_measurements_at_all(): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest('acme-no-usage'));

        $usage = $provider->accountUsage($node, 'acme-no-usage');

        // A panel mid-restart. Nothing here may be written over a customer's
        // real figures.
        $this->assertFalse($usage->hasAnyMeasurement());
    }

    #[Test]
    public function the_timeout_marker_produces_an_indeterminate_failure_and_records_nothing(): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        try {
            $provider->createAccount($node, $this->createRequest('acme-timeout'));

            $this->fail('The timeout marker was ignored.');
        } catch (HostingProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }

        // Deliberately nothing recorded: the caller cannot tell whether the
        // account exists, and has to behave correctly anyway.
        $this->assertSame([], $provider->listAccounts($node));
    }

    #[Test]
    public function the_licence_answer_follows_the_node_row(): void
    {
        $provider = new FakeHostingProvider;

        $this->assertTrue($provider->licenceStatus($this->node())->valid);

        $unlicensed = $this->node();
        $unlicensed->panel_licensed = false;
        $unlicensed->licence_status = 'expired';

        // A fake that always reported a valid licence would leave every
        // licensing branch in the platform unexecuted.
        $this->assertFalse($provider->licenceStatus($unlicensed)->valid);
    }

    private function node(): HostingNode
    {
        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X81',
            'slug' => 'node-fake',
            'hostname' => 'node-fake.lynomia.test',
            'panel' => HostingPanel::Fake,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'licence_status' => 'active',
            'account_count' => 0,
            'disk_total_mib' => 1024,
            'disk_used_mib' => 128,
            'load_average' => 1.0,
        ]);
    }

    private function createRequest(string $username): CreateAccountRequest
    {
        return new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example',
            password: 's3cret-panel-password',
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example',
        );
    }
}
