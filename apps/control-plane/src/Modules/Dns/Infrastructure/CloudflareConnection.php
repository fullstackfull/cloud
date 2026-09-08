<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;

/**
 * Everything needed to talk to Cloudflare, and nothing that talks to it.
 *
 * Separated from the adapters because two of them need it — forward DNS in this
 * module and reverse DNS in IPAM — and a token read in two places is a token
 * configured two ways. It is also the one class that holds the credential, so
 * it is the one class to audit for whether the credential can escape: it is
 * never returned by a getter, never put in an exception, and never included in
 * anything that reaches a log, because the only thing that reads it is the
 * request builder below.
 */
final readonly class CloudflareConnection
{
    public const string CONFIGURATION_KEY = 'services.cloudflare';

    private function __construct(
        private string $baseUrl,
        private string $apiToken,
        private ?string $accountId,
        private int $timeoutSeconds,
        private bool $verifyTls,
    ) {}

    /**
     * @throws DnsNotConfiguredException
     */
    public static function fromConfig(string $providerName): self
    {
        /** @var array<string, mixed> $settings */
        $settings = config(self::CONFIGURATION_KEY, []);

        $token = trim((string) ($settings['api_token'] ?? ''));

        if ($token === '') {
            throw DnsNotConfiguredException::missingCredentials(
                $providerName,
                self::CONFIGURATION_KEY.'.api_token',
            );
        }

        $accountId = trim((string) ($settings['account_id'] ?? ''));

        return new self(
            baseUrl: rtrim((string) ($settings['base_url'] ?? 'https://api.cloudflare.com/client/v4'), '/'),
            apiToken: $token,
            accountId: $accountId === '' ? null : $accountId,
            timeoutSeconds: max(1, (int) ($settings['timeout'] ?? 10)),
            verifyTls: (bool) ($settings['verify_tls'] ?? true),
        );
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * A request already carrying the credential.
     *
     * TLS verification and redirect handling are set explicitly rather than
     * left to the client default, for the reason every adapter here does it: a
     * future change to that default must not be able to disable certificate
     * verification, and a Location header is chosen by the far end — following
     * one would re-send the Authorization header to whatever host it names.
     */
    public function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiToken)
            ->withOptions(['verify' => $this->verifyTls, 'allow_redirects' => false])
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }
}
