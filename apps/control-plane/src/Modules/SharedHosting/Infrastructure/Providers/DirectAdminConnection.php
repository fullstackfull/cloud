<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Providers;

use InvalidArgumentException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeNotConfiguredException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use SensitiveParameter;

/**
 * How to reach one DirectAdmin node, credential included.
 *
 * The credential is a LOGIN KEY, not the admin password. DirectAdmin's login
 * keys are exactly the mechanism this integration wants: each one is scoped to
 * a list of commands, can be restricted by source address, has its own expiry,
 * and is revocable without touching the admin account itself. The admin
 * password, by contrast, also opens the web interface and cannot be rotated
 * without locking out every other integration at the same moment.
 *
 * The key travels in an HTTP Basic header, which is how DirectAdmin's API
 * authenticates. It is never put in the URL and never in the body.
 *
 * @immutable
 */
final readonly class DirectAdminConnection
{
    private const int FALLBACK_TIMEOUT_SECONDS = 60;

    /** The port DirectAdmin serves on when the node row does not spell one out. */
    private const int DEFAULT_PORT = 2222;

    /**
     * @param  bool  $verifyTls  Defaults to true everywhere it is constructed; only a node
     *                           row may waive it, and only for itself.
     */
    public function __construct(
        public string $endpoint,
        public string $username,
        #[SensitiveParameter]
        public string $loginKey,
        public bool $verifyTls = true,
        public int $timeoutSeconds = self::FALLBACK_TIMEOUT_SECONDS,
    ) {
        if (trim($endpoint) === '') {
            throw new InvalidArgumentException('A DirectAdmin node needs an API endpoint.');
        }

        if (trim($username) === '' || trim($loginKey) === '') {
            throw new InvalidArgumentException(
                'A DirectAdmin connection needs an admin username and a login key. '
                .'The account password is not accepted.'
            );
        }
    }

    /**
     * @throws HostingNodeNotConfiguredException
     */
    public static function forNode(HostingNode $node): self
    {
        $endpoint = trim((string) $node->api_endpoint);

        if ($endpoint === '') {
            $endpoint = sprintf('https://%s:%d', $node->hostname, self::DEFAULT_PORT);
        }

        $reference = $node->credentials_reference ?? $node->slug;

        /** @var array<string, mixed> $credentials */
        $credentials = config('hosting.credentials.'.$reference, []);

        $username = (string) ($credentials['username'] ?? '');
        $loginKey = (string) ($credentials['login_key'] ?? '');

        if ($username === '' || $loginKey === '') {
            throw HostingNodeNotConfiguredException::missingCredentials(
                (string) $node->getKey(),
                $node->hostname,
                $reference,
            );
        }

        return new self(
            endpoint: $endpoint,
            username: $username,
            loginKey: $loginKey,
            verifyTls: $node->verify_tls,
            timeoutSeconds: (int) config('hosting.timeout_seconds', self::FALLBACK_TIMEOUT_SECONDS),
        );
    }

    /**
     * DirectAdmin serves its API commands at the root of the same host and
     * port as its web interface: /CMD_API_ACCOUNT_USER and so on.
     */
    public function baseUrl(): string
    {
        return rtrim($this->endpoint, '/');
    }

    /**
     * HTTP Basic, which is what DirectAdmin's API expects, with the login key
     * standing in for the password.
     */
    public function authorizationHeader(): string
    {
        return 'Basic '.base64_encode($this->username.':'.$this->loginKey);
    }
}
