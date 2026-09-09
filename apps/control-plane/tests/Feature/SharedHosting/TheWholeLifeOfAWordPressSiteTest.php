<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Application\Actions\VerifyWordPressSites;
use Lynomia\Modules\SharedHosting\Application\Handlers\InstallWordPressHandler;
use Lynomia\Modules\SharedHosting\Domain\Contracts\SiteProbe;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Probes\FakeSiteProbe;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * From "I want a WordPress site" to a site somebody actually looked at.
 *
 * The assertions worth reading are the negative ones. A site does not become
 * ready because an installer said so; it becomes ready because this platform
 * fetched it and WordPress answered. And an install that stopped answering
 * does not get another go, because the second one would write over whatever
 * the customer had already put there.
 */
final class TheWholeLifeOfAWordPressSiteTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private HostingNode $node;

    private FakeHostingProvider $panel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HostingProviderFactory::class);
        $this->app->bind(SiteProbe::class, FakeSiteProbe::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->node = HostingNode::factory()->create(['panel' => HostingPanel::Fake]);

        /** @var FakeHostingProvider $panel */
        $panel = app(HostingProviderFactory::class)->for($this->node);
        $this->panel = $panel;
    }

    private function siteOn(string $domain, string $username = 'sitely'): WordPressSite
    {
        $this->panel->createAccount($this->node, new CreateAccountRequest(
            username: $username,
            primaryDomain: $domain,
            password: 'panel-password-not-stored',
            packageName: 'starter',
            contactEmail: 'owner@'.$domain,
        ));

        $account = HostingAccount::factory()->named($username)->create([
            'customer_id' => $this->customer->getKey(),
            'hosting_node_id' => $this->node->getKey(),
            'primary_domain' => $domain,
        ]);

        return WordPressSite::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'hosting_account_id' => $account->getKey(),
            'domain' => $domain,
            'domain_source' => WordPressDomainSource::Existing,
            'state' => WordPressSiteState::Requested,
            'admin_username' => 'sitemanager',
        ]);
    }

    private function install(WordPressSite $site): void
    {
        $job = ProvisioningJob::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => ProvisioningJobKind::InstallWordPress,
            'payload' => [
                'wordpress_site_id' => (string) $site->getKey(),
                'admin_username' => 'sitemanager',
                'admin_password' => 'generated-and-never-stored',
                'admin_email' => 'owner@'.$site->domain,
                'site_title' => 'A Site',
            ],
        ]);

        app(InstallWordPressHandler::class)->execute($job);
    }

    #[Test]
    public function an_installed_site_is_not_ready_until_the_platform_has_looked_at_it(): void
    {
        $site = $this->siteOn('newsite.test');

        $this->install($site);

        $installed = $site->fresh();
        $this->assertInstanceOf(WordPressSite::class, $installed);

        /*
         * Installed, and deliberately not ready. The installer's success is a
         * claim; installers return success for sites that then serve a
         * database error or the panel's holding page.
         */
        $this->assertTrue($installed->installed);
        $this->assertSame(WordPressSiteState::AwaitingCertificate, $installed->state);
        $this->assertNull($installed->verified_at);

        $outcome = app(VerifyWordPressSites::class)->execute();

        $this->assertSame(1, $outcome['verified']);

        $verified = $site->fresh();
        $this->assertSame(WordPressSiteState::Ready, $verified?->state);
        $this->assertNotNull($verified->verified_at);
        $this->assertSame(SslStatus::Active, $verified->ssl_status);
    }

    #[Test]
    public function a_site_that_answers_and_is_not_wordpress_is_reported_as_still_waiting_on_dns(): void
    {
        $site = $this->siteOn('site-foreign.test');
        $this->install($site);

        app(VerifyWordPressSites::class)->execute();

        /*
         * Somebody else's page, almost always because the name is still
         * pointed at the customer's old host. Calling it a failure sends them
         * to support; calling it a delegation sends them to their registrar,
         * where the fix actually is.
         */
        $this->assertSame(WordPressSiteState::AwaitingDns, $site->fresh()?->state);
        $this->assertFalse($site->fresh()?->dns_ready);
    }

    #[Test]
    public function a_site_that_works_without_a_certificate_gets_its_own_answer(): void
    {
        $site = $this->siteOn('site-insecure.test');
        $this->install($site);

        app(VerifyWordPressSites::class)->execute();

        $checked = $site->fresh();

        // Up and serving, and the padlock is missing. The customer should be
        // told their site works, not that something is broken.
        $this->assertSame(WordPressSiteState::AwaitingCertificate, $checked?->state);
        $this->assertTrue($checked->dns_ready);
        $this->assertTrue($checked->state->isUsable());
    }

    #[Test]
    public function a_site_that_does_not_answer_loses_its_ready_badge(): void
    {
        $site = $this->siteOn('site-down.test');
        $this->install($site);

        // Pretend an earlier sweep had verified it.
        $site->forceFill([
            'state' => WordPressSiteState::Ready,
            'dns_ready' => true,
            'verified_at' => now(),
        ])->save();

        app(VerifyWordPressSites::class)->execute();

        // A green badge over a site nobody can reach is the specific lie this
        // sweep exists to stop telling.
        $this->assertSame(WordPressSiteState::AwaitingDns, $site->fresh()?->state);
        $this->assertNull($site->fresh()?->verified_at);
    }

    #[Test]
    public function an_installation_that_stopped_answering_is_never_installed_over(): void
    {
        $site = $this->siteOn('wp-timeout.test');

        $this->install($site);

        $stuck = $site->fresh();

        /*
         * The fake records the installation and then throws, which is what a
         * toolkit that goes quiet actually does. A second install here would
         * rewrite wp-config and re-seed the database, taking with it anything
         * the customer wrote in the hour they had the site.
         */
        $this->assertSame(WordPressSiteState::Indeterminate, $stuck?->state);
        $this->assertNotNull($stuck->review_reason);
        $this->assertFalse($stuck->state->permitsInstallation());

        // And a redelivered job does nothing.
        $this->install($stuck);
        $this->assertSame(WordPressSiteState::Indeterminate, $site->fresh()?->state);
    }

    #[Test]
    public function an_install_finds_a_site_the_panel_already_has_rather_than_making_a_second(): void
    {
        $site = $this->siteOn('already.test');

        $this->install($site);
        $this->assertTrue($site->fresh()?->installed);

        // Back to a state that permits installation, as a retry after a
        // recorded failure would be.
        $site->forceFill(['state' => WordPressSiteState::Failed, 'installed' => false])->save();

        $this->install($site);

        // The handler asked the panel first and found the installation, rather
        // than laying a second one over the top.
        $fresh = $site->fresh();
        $this->assertTrue($fresh?->installed);
        $this->assertSame(WordPressSiteState::AwaitingCertificate, $fresh->state);
    }

    #[Test]
    public function a_refused_install_leaves_the_hosting_account_working(): void
    {
        $site = $this->siteOn('wp-refused.test');

        $this->install($site);

        $failed = $site->fresh();
        $this->assertSame(WordPressSiteState::Failed, $failed?->state);
        $this->assertNotNull($failed->failure_reason);

        /*
         * The account is untouched. A WordPress order that failed at the
         * install must not take the customer's hosting with it — they have
         * somewhere to put a site, and can try again.
         */
        $this->assertNotNull(HostingAccount::query()->find($failed->hosting_account_id));
    }
}
