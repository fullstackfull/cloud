<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
            'Stripe rejected key sk_live_51ABCDEfghijkLMNOP',
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
        $message = "failed to parse key:\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow...\n-----END RSA PRIVATE KEY-----\n";

        $redacted = $this->redactor->redactString($message);

        $this->assertStringNotContainsString('BEGIN RSA PRIVATE KEY', $redacted);
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
