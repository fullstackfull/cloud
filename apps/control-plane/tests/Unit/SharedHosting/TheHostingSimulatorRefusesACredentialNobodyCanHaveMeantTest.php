<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Closure;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The controlled panel will not report success for an account, a password
 * change or a WordPress site that nobody could ever log in to (F-24).
 *
 * ===========================================================================
 * WHAT WAS WRONG
 * ===========================================================================
 *
 * Neither the panel nor the installer ever looked at the credential it was
 * handed. An account opened with the empty string, or with the ten characters
 * the redactor leaves behind, was reported created, and a site installed with
 * that placeholder as its administrator password was reported installed. Both
 * happened on this platform — the account build read a `password` key nothing
 * wrote, and the install read an `admin_password` the payload's cast had
 * already replaced — and every test that ran those paths was green, because
 * the one component in a position to notice was built not to.
 *
 * ===========================================================================
 * WHAT THIS DOES NOT CLAIM
 * ===========================================================================
 *
 * What a real WHM or DirectAdmin does with an empty password has never been
 * established in this repository, and nothing here says it refuses one. The
 * simulator declines to pretend, which is the same thing it already does for
 * a request no caller can have meant; it is not modelling a panel. And the
 * placeholder arm is weaker in kind than the blank one: `[redacted]` is a
 * perfectly valid ten-character password, refused only because in this
 * platform it is evidence of a credential read back from a redacted column.
 * Against a real panel nothing in this file runs — the guards that hold in
 * production are the handlers' own.
 */
final class TheHostingSimulatorRefusesACredentialNobodyCanHaveMeantTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function credentialsNobodyCanHaveMeant(): array
    {
        return [
            'the empty string' => [''],
            'nothing but whitespace' => ["  \t "],
            "the redactor's placeholder" => [SecretRedactor::PLACEHOLDER],
        ];
    }

    #[Test]
    #[DataProvider('credentialsNobodyCanHaveMeant')]
    public function an_account_opened_with_it_is_refused_and_nothing_is_opened(string $credential): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $this->refused(fn () => $provider->createAccount($node, $this->createRequest('acme', $credential)));

        $this->assertSame([], $provider->listAccounts($node), 'The refused create left an account behind.');
        $this->assertNull($provider->credentialHandedTo($node, 'acme'));
    }

    #[Test]
    #[DataProvider('credentialsNobodyCanHaveMeant')]
    public function a_password_changed_to_it_is_refused_and_the_old_one_stands(string $credential): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest('acme', 'Kq7wR2nZ4pLx9vB3'));

        $this->refused(fn () => $provider->changePassword($node, 'acme', $credential));

        $this->assertSame(
            'Kq7wR2nZ4pLx9vB3',
            $provider->credentialHandedTo($node, 'acme'),
            'A refused change replaced the password the account had.',
        );
    }

    #[Test]
    #[DataProvider('credentialsNobodyCanHaveMeant')]
    public function a_wordpress_site_installed_with_it_is_refused_and_nothing_is_installed(string $credential): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest('acme', 'Kq7wR2nZ4pLx9vB3'));

        $this->refused(fn () => $provider->installWordPress($node, $this->installRequest('acme', 'site.example', $credential)));

        $this->assertFalse(
            $provider->wordPressInstallation($node, 'acme', 'site.example')->exists,
            'A refused install left a site behind.',
        );
    }

    #[Test]
    public function a_credential_anybody_could_have_meant_is_accepted_everywhere(): void
    {
        /*
         * The twin, because a gate that refused every credential would pass
         * every case above. Ten characters, the placeholder's own length, and
         * not the placeholder: refusing on length or shape rather than on the
         * one string that is evidence of a bug would be a rule about what a
         * password must look like, which is a panel's to make and not this
         * simulator's.
         */
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest('acme', 'redacted-0'));
        $provider->changePassword($node, 'acme', '[redacted-'); // Almost, and not.
        $installed = $provider->installWordPress($node, $this->installRequest('acme', 'site.example', 'Wq4nT8zR2x'));

        $this->assertCount(1, $provider->listAccounts($node));
        $this->assertSame('[redacted-', $provider->credentialHandedTo($node, 'acme'));
        $this->assertTrue($installed->exists);
    }

    /**
     * Run a call that must be refused as a known outcome, and fail unless it
     * was.
     *
     * Not indeterminate, on purpose: the simulator wrote nothing, so a caller
     * is entitled to know that — and a refusal that pretended to be a lost
     * answer would send the platform off reconciling an account that was
     * never opened.
     *
     * @param  Closure(): mixed  $call
     */
    private function refused(Closure $call): void
    {
        try {
            $call();
        } catch (HostingProviderException $e) {
            $this->assertFalse($e->isIndeterminate(), 'A refused credential was reported as an unknown outcome.');
            $this->assertStringContainsString('credential', (string) ($e->context()['provider_message'] ?? ''));

            return;
        }

        $this->fail('The simulator reported success with a credential nobody can log in with.');
    }

    private function node(): HostingNode
    {
        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X82',
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

    private function createRequest(string $username, string $password): CreateAccountRequest
    {
        return new CreateAccountRequest(
            username: $username,
            primaryDomain: $username.'.example',
            password: $password,
            packageName: 'starter',
            contactEmail: 'owner@'.$username.'.example',
        );
    }

    private function installRequest(string $username, string $domain, string $adminPassword): WordPressInstallRequest
    {
        return new WordPressInstallRequest(
            username: $username,
            domain: $domain,
            adminUsername: 'owner',
            adminPassword: $adminPassword,
            adminEmail: 'owner@'.$domain,
            siteTitle: 'A site',
        );
    }
}
