<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Providers;

use InvalidArgumentException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeNotConfiguredException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use SensitiveParameter;

/**
 * How to reach one WHM node, credential included.
 *
 * The credential is an API token and nothing else. WHM also accepts the root
 * account's password — that is remote root on a machine holding several
 * hundred customers' websites, databases and mail, reusable over SSH, and
 * unrevocable without changing the machine's own root password. A token is
 * scoped to a set of WHM functions, revocable on its own, and carries no shell
 * access. There is deliberately no code path here that sends a password.
 *
 * The object is constructed per call from configuration rather than stored on
 * the node row: a token in the database is a token in every backup, every
 * replica and every support export.
 *
 * @immutable
 */
final readonly class WhmConnection
{
    private const int FALLBACK_TIMEOUT_SECONDS = 60;

    /** The port WHM serves its API on when the node row does not spell one out. */
    private const int DEFAULT_PORT = 2087;

    /**
     * @param  string  $user  The WHM account the token belongs to. Almost always root,
     *                        but a reseller token is a legitimate deployment and the
     *                        header carries the name either way.
     * @param  bool  $verifyTls  Defaults to true everywhere it is constructed. A node with a
     *                           self-signed certificate turns it off on its own row, which
     *                           keeps the exception visible and local instead of an
     *                           environment variable that silently disables verification for
     *                           the whole fleet.
     */
    public function __construct(
        public string $endpoint,
        public string $user,
        #[SensitiveParameter]
        public string $apiToken,
        public bool $verifyTls = true,
        public int $timeoutSeconds = self::FALLBACK_TIMEOUT_SECONDS,
    ) {
        if (trim($endpoint) === '') {
            throw new InvalidArgumentException('A WHM node needs an API endpoint.');
        }

        if (trim($user) === '' || trim($apiToken) === '') {
            throw new InvalidArgumentException(
                'A WHM connection needs an account name and an API token. '
                .'Password authentication is not supported.'
            );
        }
    }

    /**
     * Build the connection for a node from configuration.
     *
     * @throws HostingNodeNotConfiguredException
     */
    public static function forNode(HostingNode $node): self
    {
        $endpoint = trim((string) $node->api_endpoint);

        if ($endpoint === '') {
            // Derived rather than guessed only when the row says nothing at
            // all; a row that names an endpoint is always obeyed, because a
            // node behind a management VPN is not reachable on its public
            // hostname.
            $endpoint = sprintf('https://%s:%d', $node->hostname, self::DEFAULT_PORT);
        }

        $reference = $node->credentials_reference ?? $node->slug;

        /** @var array<string, mixed> $credentials */
        $credentials = config('hosting.credentials.'.$reference, []);

        $token = (string) ($credentials['api_token'] ?? '');

        if ($token === '') {
            throw HostingNodeNotConfiguredException::missingCredentials(
                (string) $node->getKey(),
                $node->hostname,
                $reference,
            );
        }

        return new self(
            endpoint: $endpoint,
            user: (string) ($credentials['user'] ?? 'root'),
            apiToken: $token,
            // The node row decides, and only for itself. config() supplies the
            // fleet default for a node that has expressed no preference and can
            // never override one that has: an unverified connection hands the
            // root API token, and with it every customer's site on the node, to
            // whoever answers the TCP connection.
            verifyTls: $node->verify_tls,
            timeoutSeconds: (int) config('hosting.timeout_seconds', self::FALLBACK_TIMEOUT_SECONDS),
        );
    }

    /**
     * The API root. WHM serves API v1 under /json-api on the same host and
     * port as its web interface.
     */
    public function baseUrl(): string
    {
        return rtrim($this->endpoint, '/').'/json-api';
    }

    /**
     * The one authentication header this adapter ever sends.
     *
     * The format is WHM's own: "whm <user>:<token>", with no scheme prefix and
     * no base64. It goes in a header and never in a query string — a token in
     * a URL is a token in every reverse proxy's access log between here and the
     * node.
     */
    public function authorizationHeader(): string
    {
        return sprintf('whm %s:%s', $this->user, $this->apiToken);
    }
}
