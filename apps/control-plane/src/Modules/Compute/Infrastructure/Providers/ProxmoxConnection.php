<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Infrastructure\Providers;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * How to reach one Proxmox cluster, credential included.
 *
 * The credential is an API token and nothing else. Proxmox also accepts a
 * username and password, which returns a ticket and a CSRF token that then
 * have to be refreshed, stored and invalidated — and a password that can log
 * into the web UI, reconfigure the cluster and read every customer's console.
 * A token is scoped, revocable without changing anybody's login, and carries
 * no session state, so it is the only mechanism this adapter supports. There
 * is deliberately no code path here that logs in.
 *
 * The object is constructed per request from configuration rather than stored
 * on the cluster row: a token in the database is a token in every backup,
 * every replica and every support export.
 *
 * @immutable
 */
final readonly class ProxmoxConnection
{
    private const int FALLBACK_TIMEOUT_SECONDS = 30;

    /**
     * @param  string  $tokenId  The full token identifier, "user@realm!tokenid".
     * @param  string  $tokenSecret  The token's secret UUID.
     * @param  bool  $verifyTls  Defaults to true everywhere it is constructed. A cluster
     *                           with a self-signed certificate turns it off on its own row,
     *                           which keeps the exception visible and local instead of an
     *                           environment variable that silently disables verification
     *                           for the whole fleet.
     */
    public function __construct(
        public string $endpoint,
        public string $tokenId,
        #[SensitiveParameter]
        public string $tokenSecret,
        public bool $verifyTls = true,
        public int $timeoutSeconds = self::FALLBACK_TIMEOUT_SECONDS,
    ) {
        if (trim($endpoint) === '') {
            throw new InvalidArgumentException('A Proxmox cluster needs an API endpoint.');
        }

        if (! str_contains($tokenId, '!') || trim($tokenSecret) === '') {
            throw new InvalidArgumentException(
                'A Proxmox API token must be given as "user@realm!tokenid" together with its secret. '
                .'Username and password authentication is not supported.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  bool|null  $verifyTls  The cluster's own decision. Null means the cluster has
     *                                expressed none and the fleet default applies.
     */
    public static function fromCredentials(
        string $endpoint,
        #[SensitiveParameter]
        array $credentials,
        ?bool $verifyTls = null,
        ?int $timeoutSeconds = null,
    ): self {
        return new self(
            endpoint: $endpoint,
            tokenId: (string) ($credentials['token_id'] ?? ''),
            tokenSecret: (string) ($credentials['token_secret'] ?? ''),
            /*
             * The cluster row decides, and only for itself. config() supplies
             * the fleet default for a cluster that has expressed no preference
             * and can never override one that has.
             *
             * It was previously combined with the row using AND, which meant
             * PROXMOX_VERIFY_TLS=false switched certificate verification off
             * for every cluster at once — including production clusters whose
             * own row demanded it. That is the single environment variable
             * this class exists to make impossible: an unverified connection
             * hands the API token, and with it every customer's machine on the
             * cluster, to whoever answers the TCP connection.
             */
            verifyTls: $verifyTls ?? (bool) config('compute.proxmox.verify_tls', true),
            timeoutSeconds: $timeoutSeconds
                ?? (int) config('compute.proxmox.timeout_seconds', self::FALLBACK_TIMEOUT_SECONDS),
        );
    }

    /**
     * The API root. Proxmox serves the JSON API under /api2/json on the same
     * host and port as the web UI.
     */
    public function baseUrl(): string
    {
        return rtrim($this->endpoint, '/').'/api2/json';
    }

    /**
     * The one authentication header this adapter ever sends.
     */
    public function authorizationHeader(): string
    {
        return sprintf('PVEAPIToken=%s=%s', $this->tokenId, $this->tokenSecret);
    }
}
