<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Lynomia\Modules\Compute\Infrastructure\Providers\ProxmoxConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\WhmConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SecretFixtures;

final class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new SecretRedactor([
            'password', 'secret', 'token', 'api_key', 'private_key',
            'two_factor_secret', 'bmc_password', 'ipmi_password', 'card_number',
        ]);
    }

    #[Test]
    public function it_redacts_secret_keys_at_the_top_level(): void
    {
        $result = $this->redactor->redact([
            'email' => 'customer@example.com',
            'password' => 'hunter2',
        ]);

        $this->assertSame('customer@example.com', $result['email']);
        $this->assertSame(SecretRedactor::PLACEHOLDER, $result['password']);
    }

    #[Test]
    public function it_redacts_secret_keys_at_any_depth(): void
    {
        $result = $this->redactor->redact([
            'job' => [
                'provider' => 'proxmox',
                'connection' => [
                    'host' => 'pve-01.internal',
                    'api_key' => 'super-secret-value',
                ],
            ],
        ]);

        $this->assertSame('pve-01.internal', $result['job']['connection']['host']);
        $this->assertSame(SecretRedactor::PLACEHOLDER, $result['job']['connection']['api_key']);
    }

    #[Test]
    public function key_matching_ignores_case_and_separators(): void
    {
        $result = $this->redactor->redact([
            'X-API-Key' => 'abc',
            'Two Factor Secret' => 'def',
            'STRIPE_WEBHOOK_SECRET' => 'ghi',
            'bmc-password' => 'jkl',
        ]);

        foreach ($result as $key => $value) {
            $this->assertSame(SecretRedactor::PLACEHOLDER, $value, "Key {$key} was not redacted.");
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function embeddedCredentials(): iterable
    {
        yield 'bearer token in an error message' => [
            'Request failed: Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abcdefgh',
        ];
        yield 'stripe secret key' => [
            'Stripe rejected key '.SecretFixtures::STRIPE_SECRET_KEY,
        ];
        yield 'proxmox ticket' => [
            'auth cookie PVEAuthCookie:PVE:root@pam:5F3A2B::abcdefgh== was rejected',
        ];
        yield 'password in a connection string' => [
            'pgsql://lynomia:password=s3cr3tValue@db.internal:5432/lynomia',
        ];
        yield 'ipmi command line' => [
            'ipmitool -H 10.0.0.5 -U admin -P SuperSecret123 power status',
        ];
        // A process exception stringifies argv shell-quoted, so the separator
        // is not adjacent to the flag: `'-P' 'secret'`, not `-P secret`.
        yield 'shell-quoted ipmi argv from a process exception' => [
            'The process "\'ipmitool\' \'-H\' \'10.0.0.5\' \'-P\' \'SuperSecret123\'" exceeded the timeout',
        ];
    }

    /**
     * The credential shapes the platform's own adapters actually put on the
     * wire, taken from the connection objects rather than hand-written, so a
     * change to a header format cannot silently outrun the pattern list.
     *
     * @return iterable<string, array{string}>
     */
    public static function providerAuthorizationHeaders(): iterable
    {
        yield 'proxmox api token' => [(new ProxmoxConnection(
            endpoint: 'https://pve-01.internal:8006',
            tokenId: 'lynomia@pve!control-plane',
            tokenSecret: '1f2e3d4c-5b6a-7890-abcd-ef0123456789',
        ))->authorizationHeader()];

        yield 'whm api token' => [(new WhmConnection(
            endpoint: 'https://web-01.internal:2087',
            user: 'root',
            apiToken: 'PLQ7XKQ2NRT9ZM4V8W1CJH6YB3GDF5SA',
        ))->authorizationHeader()];
    }

    #[Test]
    #[DataProvider('providerAuthorizationHeaders')]
    public function it_masks_the_authorization_headers_the_adapters_send(string $header): void
    {
        $redacted = $this->redactor->redactString(
            'the node refused the request carrying Authorization: '.$header,
        );

        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $redacted);
        $this->assertStringNotContainsString($header, $redacted);
    }

    #[Test]
    public function it_scrubs_the_whole_previous_chain_of_a_throwable(): void
    {
        $throwable = new \RuntimeException(
            'outer, carrying Authorization: Bearer abcdefghijklmnop',
            0,
            new \RuntimeException('inner: sshpass -p hunter2 ssh root@web-01'),
        );

        $normalised = $this->redactor->redact(['exception' => $throwable]);

        $encoded = json_encode($normalised, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('abcdefghijklmnop', $encoded);
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertSame(\RuntimeException::class, $normalised['exception']['class']);
        $this->assertArrayHasKey('previous', $normalised['exception']);
    }

    #[Test]
    #[DataProvider('embeddedCredentials')]
    public function it_masks_credentials_embedded_in_free_text(string $message): void
    {
        // A key-based rule can never reach a secret that appears inside a
        // sentence, which is exactly how provider errors leak them.
        $redacted = $this->redactor->redactString($message);

        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $redacted);
    }

    #[Test]
    public function it_masks_a_private_key_block(): void
    {
        $message = "failed to parse key:\n".SecretFixtures::PRIVATE_KEY_PEM."\n";

        $redacted = $this->redactor->redactString($message);

        $this->assertStringNotContainsString('BEGIN'.' RSA PRIVATE KEY', $redacted);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $redacted);
    }

    #[Test]
    public function it_leaves_ordinary_operational_data_intact(): void
    {
        $result = $this->redactor->redact([
            'vmid' => 1042,
            'node' => 'pve-01',
            'ipv4' => '203.0.113.42',
            'status' => 'running',
            'message' => 'VM 1042 started on node pve-01 in 4.2s',
        ]);

        $this->assertSame(1042, $result['vmid']);
        $this->assertSame('203.0.113.42', $result['ipv4']);
        $this->assertSame('VM 1042 started on node pve-01 in 4.2s', $result['message']);
    }

    #[Test]
    public function it_bounds_recursion_on_deeply_nested_payloads(): void
    {
        $deep = ['value'];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['nested' => $deep];
        }

        // Must return rather than exhaust the stack: a logger that can be
        // crashed by its own input is a denial-of-service vector.
        $result = $this->redactor->redact($deep);

        $this->assertIsArray($result);
    }
}
