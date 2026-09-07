<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — a node does not get the platform to re-post a customer's
 * password to a host of its choosing.
 *
 * No provider adapter used to disable redirect following, so Guzzle's redirect
 * middleware followed up to five redirects per request to whatever host the
 * answering node named. For 307 and 308 Guzzle preserves BOTH the method and
 * the request body, and the body of CMD_API_USER_PASSWD is the customer's
 * plaintext panel password. (The Authorization header is stripped
 * cross-origin, so the platform's own login key was never the thing at risk —
 * the customer's password was.)
 *
 * A node that is compromised, mis-DNS'd, or simply operated by a reseller
 * could therefore have every panel password the platform sets delivered to it,
 * while the platform recorded an ordinary provider failure.
 */
final class HostingNodeRedirectCannotExfiltratePanelPasswordTest extends TestCase
{
    private const string PANEL_PASSWORD = 'the-customers-plaintext-panel-password';

    private const string ATTACKER = 'https://collector.attacker.test/collect';

    #[Test]
    public function a_307_from_the_node_does_not_repost_the_plaintext_panel_password_to_another_host(): void
    {
        Http::fake([
            'node-b.lynomia.test:2222/*' => Http::response('', 307, ['Location' => self::ATTACKER]),
            'collector.attacker.test/*' => Http::response('error=0&text=ok', 200),
        ]);

        try {
            (new DirectAdminHostingProvider(new SecretRedactor))
                ->changePassword($this->node(), 'acme', self::PANEL_PASSWORD);

            $this->fail('The adapter accepted a redirect as an answer.');
        } catch (HostingProviderException $e) {
            $this->assertStringContainsString('redirect', $e->getMessage());

            // A node that redirected a write may still have applied it, so the
            // failure must not read as a clean refusal a caller may retry.
            $this->assertTrue($e->isIndeterminate());

            // And the password must not be in the exception the platform logs.
            $this->assertStringNotContainsString(self::PANEL_PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(
                self::PANEL_PASSWORD,
                json_encode($e->context(), JSON_THROW_ON_ERROR),
            );
        }

        Http::assertSent(function (Request $request): bool {
            $this->assertStringNotContainsString(
                'collector.attacker.test',
                $request->url(),
                'The adapter followed the node redirect to a host the node chose.',
            );

            return true;
        });

        Http::assertNotSent(fn (Request $request): bool => str_contains(
            (string) $request->body(),
            urlencode(self::PANEL_PASSWORD),
        ) && ! str_contains($request->url(), 'node-b.lynomia.test'));
    }

    private function node(): HostingNode
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
