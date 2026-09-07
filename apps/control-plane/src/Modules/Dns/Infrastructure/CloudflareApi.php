<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * One request to Cloudflare, and one reading of what came back.
 *
 * Extracted because two adapters need it — forward DNS here, reverse DNS in
 * IPAM — and the interesting part is not the HTTP call but the three ways a
 * request can end, which must be told apart identically in both places:
 *
 *  - **it came back and was fine** — the decoded body,
 *  - **it came back and was no** — refused, with the provider's reason,
 *  - **it did not come back** — indeterminate, and never retried by the caller.
 *
 * The third is the one worth duplicating nothing over. An adapter that treats a
 * timeout as a failure and tries again is how one name ends up with two records.
 */
final readonly class CloudflareApi
{
    public function __construct(
        private CloudflareConnection $connection,
        private SecretRedactor $redactor,
        private string $providerName,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Query string for GET, JSON body otherwise
     * @return array<string, mixed>
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function call(string $method, string $path, array $payload, string $operation): array
    {
        $request = $this->connection->request();

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $payload),
                'DELETE' => $request->delete($path),
                default => $request->send($method, $path, $payload === [] ? [] : ['json' => $payload]),
            };
        } catch (ConnectionException) {
            /*
             * The socket, not the API. Reported as indeterminate for every verb
             * including GET: a read that did not come back tells the caller
             * nothing about the zone, and "I do not know" is the honest answer
             * either way.
             *
             * The exception is not chained. Guzzle's connection message carries
             * the full request URI, and a URI is one query parameter away from
             * carrying something that should not be in a log.
             */
            throw DnsProviderException::timedOut($this->providerName, $operation);
        }

        if ($response->failed()) {
            throw DnsProviderException::refused($this->providerName, $operation, $this->reasonFrom($response));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        // Cloudflare answers 200 with success:false for some refusals, so the
        // status alone is not the answer.
        if (($body['success'] ?? true) === false) {
            throw DnsProviderException::refused($this->providerName, $operation, $this->reasonFromBody($body));
        }

        return $body;
    }

    private function reasonFrom(Response $response): string
    {
        /** @var array<string, mixed>|null $body */
        $body = $response->json();

        if (is_array($body)) {
            return sprintf('HTTP %d, %s', $response->status(), $this->reasonFromBody($body));
        }

        return sprintf('HTTP %d', $response->status());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function reasonFromBody(array $body): string
    {
        /** @var list<array<string, mixed>> $errors */
        $errors = is_array($body['errors'] ?? null) ? array_values($body['errors']) : [];

        $messages = [];

        foreach ($errors as $error) {
            $messages[] = trim(sprintf('%s %s', (string) ($error['code'] ?? ''), (string) ($error['message'] ?? '')));
        }

        $reason = $messages === [] ? 'no reason given' : implode('; ', $messages);

        // Providers quote the request back when they refuse it, headers
        // included. Nothing stores a provider message without redacting it.
        return $this->redactor->redactString($reason);
    }
}
