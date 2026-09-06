<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Logging;

/**
 * Removes secrets from arbitrary structures before they are logged or stored.
 *
 * Two independent rules apply, because secrets leak in two different shapes:
 *
 *  1. Key-based: a value is dropped when its key matches a configured secret
 *     name, at any depth. This catches `{"password": "..."}`.
 *
 *  2. Pattern-based: recognisable credential formats are masked wherever they
 *     appear inside a string value. This catches a Proxmox ticket embedded in
 *     an error message, or a bearer token inside a serialised HTTP request —
 *     places a key-based rule can never reach.
 */
final class SecretRedactor
{
    public const string PLACEHOLDER = '[redacted]';

    /**
     * Credential shapes that must be masked wherever they occur in free text.
     *
     * @var list<string>
     */
    private const array VALUE_PATTERNS = [
        // Authorization headers of every common scheme.
        '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._\-\/+=]{8,}/i',
        // Stripe secret and restricted keys.
        '/\b(sk|rk)_(live|test)_[A-Za-z0-9]{8,}/',
        // Proxmox authentication tickets and CSRF prevention tokens.
        '/\bPVE(?:API)?[A-Za-z]*:[^\s"\']+/',
        // PEM-encoded private keys.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        // Anything that looks like "password=..." inside a connection string
        // or shell-style argument.
        '/\b(password|passwd|pwd|secret|token|api[_-]?key)\s*[=:]\s*("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
        // Credential flags on command lines the platform actually builds:
        // ipmitool -P, sshpass -p and the long --password form. IPMI has no
        // way to pass a password other than argv, so a command echoed into an
        // error message would otherwise leak the BMC credential verbatim.
        '/(?<![A-Za-z0-9_-])(?:-P|-p|--password|--passwd)(?:[=\s]+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
    ];

    /** @var list<string> */
    private array $secretKeys;

    /**
     * @param  list<string>|null  $secretKeys
     */
    public function __construct(?array $secretKeys = null)
    {
        /** @var list<string> $configured */
        $configured = $secretKeys ?? config('security.redacted_keys', []);

        $this->secretKeys = array_map(
            static fn (string $key): string => self::normaliseKey($key),
            $configured,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data, int $depth = 0): array
    {
        // Bound recursion: a deeply nested or self-referential payload must not
        // be able to stall the logger.
        if ($depth > 16) {
            return [self::PLACEHOLDER];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $result[$key] = self::PLACEHOLDER;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->redact($value, $depth + 1),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $result;
    }

    public function redactString(string $value): string
    {
        foreach (self::VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::PLACEHOLDER, $value);

            if ($replaced !== null) {
                $value = $replaced;
            }
        }

        return $value;
    }

    private function isSecretKey(string $key): bool
    {
        $normalised = self::normaliseKey($key);

        foreach ($this->secretKeys as $secret) {
            // Substring rather than equality so that "stripe_webhook_secret",
            // "x-api-key" and "tokenable_secret" are all caught by the entries
            // "secret", "api_key" and "token" respectively.
            if (str_contains($normalised, $secret)) {
                return true;
            }
        }

        return false;
    }

    private static function normaliseKey(string $key): string
    {
        return str_replace(['-', ' ', '.'], '_', strtolower($key));
    }
}
