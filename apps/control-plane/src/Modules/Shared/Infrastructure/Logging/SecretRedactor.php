<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Logging;

use Throwable;

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
 *
 * Throwables are normalised rather than passed through. A logged exception is
 * the single richest source of leaked credentials — a transport library puts
 * the argv, the URI or the request headers into its message — and neither rule
 * above can reach a message that is still wrapped in an object.
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
        // Proxmox API token header — the credential this platform actually
        // sends, on every request: "PVEAPIToken=<user>@<realm>!<id>=<secret>".
        '/\bPVEAPIToken=[^\s"\']+/i',
        // Proxmox authentication tickets and CSRF prevention tokens.
        '/\bPVE(?:API)?[A-Za-z]*:[^\s"\']+/',
        // WHM/cPanel API token header: "whm <user>:<token>". It carries no
        // Bearer/Basic/Token scheme word, so the generic rule cannot see it.
        '/\bwhm\s+[^\s:"\']+:[A-Za-z0-9]{8,}/i',
        // PEM-encoded private keys.
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        // Anything that looks like "password=..." inside a connection string
        // or shell-style argument.
        '/\b(password|passwd|pwd|secret|token|api[_-]?key)\s*[=:]\s*("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i',
        // Credential flags on command lines the platform actually builds:
        // ipmitool -P, sshpass -p and the long --password form. IPMI has no
        // way to pass a password other than argv, so a command echoed into an
        // error message would otherwise leak the BMC credential verbatim.
        // The optional quote after the flag matters: a shell-quoted rendering
        // of the argv — which is what a process exception's message is — reads
        // `'-P' 'secret'`, so the separator is not adjacent to the flag.
        '/(?<![A-Za-z0-9_-])(?:-P|-p|--password|--passwd)["\']?(?:[=\s]+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
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
                $value instanceof Throwable => $this->redactThrowable($value),
                is_array($value) => $this->redact($value, $depth + 1),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $result;
    }

    /**
     * Fails closed.
     *
     * preg_replace() returns null when PCRE gives up — the backtrack or
     * recursion limit, or the JIT stack — and the length of the subject is
     * chosen by whoever produced the string, which for an adapter is a managed
     * device. Skipping the pattern in that case, which is what an `if
     * ($replaced !== null)` does, means the one rule that would have masked
     * the credential silently does not run: a 1 MB string of repeated
     * "-----BEGIN A PRIVATE KEY-----" exhausts the backtrack limit on the PEM
     * rule, and a real key later in the same string then passes through.
     *
     * This class exists so that redaction is not a call-site convention, so a
     * value it could not fully examine is discarded rather than emitted. A
     * missing log line is recoverable; a logged BMC password is not.
     */
    public function redactString(string $value): string
    {
        foreach (self::VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::PLACEHOLDER, $value);

            if ($replaced === null) {
                return self::PLACEHOLDER;
            }

            $value = $replaced;
        }

        return $value;
    }

    /**
     * Flatten a throwable into an array whose every string has been through
     * the redactor — the whole `previous` chain included.
     *
     * The chain is the part that matters. An adapter can take care to keep a
     * credential out of its own message and still hand on the transport
     * exception as `previous`, whose message is the command line or the
     * request. Returning an array rather than the object also stops a
     * formatter from re-reading the original: there is nothing left to read.
     *
     * @return array<string, mixed>
     */
    public function redactThrowable(Throwable $throwable, int $depth = 0): array
    {
        $normalised = [
            'class' => $throwable::class,
            'message' => $this->redactString($throwable->getMessage()),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            // getTraceAsString() renders scalar call arguments unless
            // zend.exception_ignore_args is on, so it is scrubbed too.
            'trace' => $this->redactString($throwable->getTraceAsString()),
        ];

        $previous = $throwable->getPrevious();

        if ($previous instanceof Throwable && $depth < 16) {
            $normalised['previous'] = $this->redactThrowable($previous, $depth + 1);
        }

        return $normalised;
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
