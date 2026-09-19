<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing a provider said, and nothing we sent it, survives the test.
 *
 * ===========================================================================
 * THE TWO LEAKS, WHICH ARE DIFFERENT
 * ===========================================================================
 *
 * **What we sent.** The credential is resolved at the last possible moment
 * into a TestTarget that redacts itself, put into a header, and dropped. Every
 * tester in this phase builds its step details from fixed sentences, so there
 * is no path from the secret to a stored string. That half is asserted here by
 * running the whole action — resolver, tester, record, audit — with a
 * recognisable value behind the credential reference and searching everything
 * it wrote.
 *
 * **What they said.** The less obvious half, and the one a reviewer would
 * miss. An upstream error body is a plausible place to find a session cookie,
 * a token echoed back in a message, an internal hostname or another
 * customer's domain — and `connection_tests.detail` is persisted, rendered on
 * a screen and carried into the audit trail. So each driver is given a
 * response whose body contains a sentinel that looks exactly like a
 * credential, and the sentinel must appear nowhere afterwards.
 *
 * A tester that quoted the upstream body into its detail — the most natural
 * thing in the world to write, and what almost every HTTP client library
 * encourages — fails this file on every driver at once.
 *
 * ===========================================================================
 * WHERE IT LOOKS
 * ===========================================================================
 *
 * The connection test row's steps and detail, the provider row's own
 * connection detail, every audit entry's context, and the log. Four places,
 * because a value that reaches any one of them has left the process.
 */
final class AConnectionTestKeepsNeitherTheSecretNorTheAnswerTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_IDENTITY_TESTER_SECRET';

    /** What an upstream response will quote back at us. */
    private const string SENTINEL = 'sk-live-upstream-echoed-this-back-9f3a2b';

    /**
     * Driver → the endpoint it takes, the credential shape it parses, and the
     * category whose capabilities it is asked about.
     *
     * The BMC rows carry a full HTTPS URL rather than a bare address, because
     * this file goes through TestConnection::forProvider and
     * EndpointPolicy::assertProviderEndpoint demands a scheme and a host of a
     * provider row. A managed server's own test takes the bare form, and the
     * negative matrix beside this file exercises that door.
     *
     * @return iterable<string, array{0: string, 1: ?string, 2: string, 3: ProviderCategory}>
     */
    public static function drivers(): iterable
    {
        yield 'proxmox' => ['proxmox', 'https://pve.example.test:8006', 'lynomia@pve!control='.self::SENTINEL, ProviderCategory::Compute];
        yield 'proxmox_backup' => ['proxmox_backup', 'https://pve.example.test:8006', 'lynomia@pve!control='.self::SENTINEL, ProviderCategory::Backup];
        yield 'cpanel' => ['cpanel', 'https://whm.example.test:2087', 'lynomia:'.self::SENTINEL, ProviderCategory::Hosting];
        yield 'directadmin' => ['directadmin', 'https://da.example.test:2222', 'lynomia:'.self::SENTINEL, ProviderCategory::Hosting];
        yield 'cloudflare' => ['cloudflare', null, str_replace('-', '', self::SENTINEL), ProviderCategory::Dns];
        yield 'cloudflare_rdns' => ['cloudflare_rdns', null, str_replace('-', '', self::SENTINEL), ProviderCategory::ReverseDns];
        yield 'redfish' => ['redfish', 'https://10.44.0.7', 'lynomia:'.self::SENTINEL, ProviderCategory::Bmc];
        yield 'ilo' => ['ilo', 'https://10.44.0.8:8443', 'lynomia:'.self::SENTINEL, ProviderCategory::Bmc];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function neither_the_credential_nor_the_upstream_body_is_written_anywhere(
        string $driver,
        ?string $endpoint,
        string $secret,
        ProviderCategory $category,
    ): void {
        $this->seed(RolePermissionSeeder::class);

        putenv(self::VARIABLE.'='.$secret);

        Log::spy();

        /*
         * An upstream that quotes the credential back at us inside an error,
         * which real products do: a panel echoing a failed Authorization
         * header into a message, a gateway including the request in its 502
         * page, an API returning the token it rejected.
         */
        Http::fake(['*' => Http::response(
            sprintf(
                '{"error":"rejected the credential %s","set-cookie":"session=%s","detail":"see %s"}',
                self::SENTINEL,
                self::SENTINEL,
                self::SENTINEL,
            ),
            403,
        )]);

        $provider = $this->provider($driver, $endpoint, $category);

        $record = app(TestConnection::class)->forProvider($provider->fresh());

        /*
         * The result itself must be a refusal, not a pass — otherwise this
         * file would be asserting that nothing leaked out of a test that never
         * concluded anything.
         */
        $this->assertFalse($record->result->usable(), "The {$driver} tester accepted an impostor that echoed a credential back.");

        $haystacks = [
            'the connection test steps' => json_encode($record->steps, JSON_THROW_ON_ERROR),
            'the connection test detail' => (string) $record->detail,
            'the provider row' => json_encode($provider->fresh()?->only(['connection_detail', 'notes']), JSON_THROW_ON_ERROR),
            'the audit trail' => json_encode(AuditEntry::query()->get(['context', 'subject_type', 'subject_id'])->toArray(), JSON_THROW_ON_ERROR),
        ];

        foreach ($haystacks as $where => $haystack) {
            $this->assertStringNotContainsString(
                self::SENTINEL,
                $haystack,
                "The {$driver} tester wrote a credential-shaped value from the wire into {$where}. "
                .'That column is persisted, rendered on a screen and carried into the audit trail.',
            );
        }

        /*
         * And nothing at all was logged. Not "nothing sensitive" — nothing:
         * the testers in this phase have no logging path, so a log line
         * appearing here means one was added, and the next person to add one
         * will interpolate the response.
         */
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');

        putenv(self::VARIABLE);
    }

    #[Test]
    public function a_refused_identity_does_not_mark_the_credential_invalid(): void
    {
        /*
         * The consequence of keeping IdentityMismatch apart from AuthFailed,
         * asserted where it actually matters: on the credential row.
         *
         * A mismatch says nothing about the credential — it was never judged
         * — so marking it Invalid would put a red badge on the credential
         * screen and send an operator to rotate a key that is fine. The action
         * only moves a credential to Invalid for a blocker of
         * blocked_credentials, and a mismatch blocks on configuration.
         */
        $this->seed(RolePermissionSeeder::class);

        putenv(self::VARIABLE.'=lynomia@pve!control=00000000-0000-0000-0000-000000000000');

        Http::fake(['*' => Http::response('OK')]);

        $provider = $this->provider('proxmox', 'https://pve.example.test:8006', ProviderCategory::Compute);

        $record = app(TestConnection::class)->forProvider($provider->fresh());

        $this->assertSame(ConnectionState::IdentityMismatch, $record->result);

        $this->assertSame(
            CredentialState::Configured,
            $provider->credential?->fresh()?->state,
            'An endpoint that could not be identified says nothing about the credential, and marking it invalid '
            .'sends an operator to rotate a key that works.',
        );

        putenv(self::VARIABLE);
    }

    private function provider(string $driver, ?string $endpoint, ProviderCategory $category): ProviderInstance
    {
        $credential = CredentialReference::query()->create([
            'name' => 'identity-tester-'.$driver,
            'purpose' => 'Connection identity test',
            'environment' => DeploymentEnvironment::Staging->value,
            'backend' => 'controller_environment',
            'backend_reference' => self::VARIABLE,
            'state' => CredentialState::Configured->value,
        ]);

        return ProviderInstance::query()->create([
            'name' => 'identity-tester-'.$driver,
            'category' => $category->value,
            'driver' => $driver,
            'environment' => DeploymentEnvironment::Staging->value,
            'state' => ProviderState::Draft->value,
            'endpoint' => $endpoint,
            'credential_reference_id' => $credential->getKey(),
        ]);
    }
}
