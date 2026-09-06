<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — the panel does not choose where the customer's browser goes.
 *
 * createSsoSession() used to take the `url` field straight out of the panel's
 * answer and return it as SsoSession::url with no check that it pointed at the
 * node the platform asked, and no scheme check. A hostile, mis-DNS'd or
 * reseller-operated node could answer create_user_session or
 * CMD_API_LOGIN_KEYS with a URL of its own choosing, and the portal's "open
 * control panel" button would deliver the customer to a login page built to
 * look exactly like their panel.
 *
 * The host is now checked against the endpoint the platform dialled and the
 * node's recorded hostname; the port is deliberately not, because WHM answers
 * on 2087 while the session URL legitimately lands on the panel's own port.
 */
final class PanelSuppliedSsoUrlIsValidatedTest extends TestCase
{
    #[Test]
    public function whm_may_not_name_another_host_in_a_session_url(): void
    {
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 1],
            'data' => ['url' => 'https://cpanel-login.attacker.test/cpsess1234/', 'expires' => 1893456000],
        ], 200)]);

        $this->expectException(HostingProviderException::class);

        (new CpanelHostingProvider(new SecretRedactor))->createSsoSession($this->cpanelNode(), 'acme');
    }

    #[Test]
    public function whm_may_still_answer_on_the_panel_port_of_its_own_host(): void
    {
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 1],
            'data' => ['url' => 'https://node-a.lynomia.test:2083/cpsess1234/', 'expires' => 1893456000],
        ], 200)]);

        $session = (new CpanelHostingProvider(new SecretRedactor))
            ->createSsoSession($this->cpanelNode(), 'acme');

        $this->assertSame('https://node-a.lynomia.test:2083/cpsess1234/', $session->url);
    }

    #[Test]
    public function directadmin_may_not_name_another_host_in_a_session_url(): void
    {
        Http::fake(['*' => Http::response(
            'error=0&url='.urlencode('https://da-login.attacker.test/?key=abc'),
            200,
        )]);

        $this->expectException(HostingProviderException::class);

        (new DirectAdminHostingProvider(new SecretRedactor))->createSsoSession($this->directAdminNode(), 'acme');
    }

    private function cpanelNode(): HostingNode
    {
        config(['hosting.credentials.node-a' => ['user' => 'root', 'api_token' => 'a-whm-api-token-value']]);

        return (new HostingNode)->forceFill([
            'id' => '01JBHOSTINGNODE000000000A',
            'slug' => 'node-a',
            'hostname' => 'node-a.lynomia.test',
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => 'https://node-a.lynomia.test:2087',
            'credentials_reference' => 'node-a',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }

    private function directAdminNode(): HostingNode
    {
        config(['hosting.credentials.node-b' => ['username' => 'admin', 'login_key' => 'da-login-key-value']]);

        return (new HostingNode)->forceFill([
            'id' => '01JBHOSTINGNODE000000000B',
            'slug' => 'node-b',
            'hostname' => 'node-b.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://node-b.lynomia.test:2222',
            'credentials_reference' => 'node-b',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }
}
