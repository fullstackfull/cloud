<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Testers;

use Illuminate\Http\Client\Response;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\IdentityProof;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use SensitiveParameter;

/**
 * Cloudflare, which is the one provider in this platform that will say in
 * plain words whether the credential is any good.
 *
 * ===========================================================================
 * WHAT COUNTS AS PROOF
 * ===========================================================================
 *
 * Every Cloudflare v4 answer, successful or not, is wrapped in the same
 * envelope: a boolean `success`, an `errors` array, a `messages` array and a
 * `result`. Three keys of known types, always present, is a distinctive shape,
 * and it is what separates Cloudflare from anything else that happens to
 * answer at this address — including a corporate TLS-inspecting proxy that
 * intercepts `api.cloudflare.com` and returns its own JSON error.
 *
 * `/user/tokens/verify` is then the ideal identity endpoint, because it is
 * read-only, it is scoped to the token itself rather than to any zone, and its
 * entire purpose is to answer the question a connection test is asking.
 *
 * ===========================================================================
 * AN EXPIRED TOKEN IS NOT AN UNREACHABLE PROVIDER
 * ===========================================================================
 *
 * Cloudflare returns HTTP 200 with `success: true` for a token that verifies
 * and then reports `result.status` as `expired` or `disabled`. An adapter that
 * read the status code would call that Connected; one that read `success`
 * alone would agree. It is an authentication failure, the credential centre
 * is where it gets fixed, and the distinction is worth the extra line.
 *
 * ===========================================================================
 * WHAT A READ CANNOT ESTABLISH
 * ===========================================================================
 *
 * A token that lists zones has proved it can list zones. Cloudflare does not
 * expose a token's own policy list, so whether this token may create a zone or
 * write a record cannot be learned without creating one — and a connection
 * test that created a zone would not be a test. Those capabilities therefore
 * stay Unknown, which is what the readiness ladder is built to carry.
 *
 * One tester, two drivers: forward DNS and reverse DNS are the same account,
 * the same token and the same API, and a second class would have been a copy
 * that drifts.
 */
final class CloudflareConnectionTester extends HttpIdentityTester
{
    private const string API_ROOT = 'https://api.cloudflare.com/client/v4';

    public function __construct(string $driver = 'cloudflare')
    {
        parent::__construct($driver);
    }

    protected function baseUrl(TestTarget $target): string
    {
        /*
         * Cloudflare's address is not a per-instance choice, which is why the
         * catalogue marks this driver as needing no endpoint. A row that
         * carries one anyway is honoured — the endpoint policy has already
         * refused anything private, reserved or non-HTTPS — so that a test
         * environment can point at a recorded API without a code change.
         */
        $endpoint = trim((string) $target->endpoint);

        return $endpoint === '' ? self::API_ROOT : rtrim($endpoint, '/');
    }

    protected function identityPath(): string
    {
        return '/user/tokens/verify';
    }

    protected function credentialShape(): string
    {
        return 'a Cloudflare API token, stored on its own with no prefix and no email address. '
            .'The legacy global API key is not accepted by this platform.';
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseCredential(TestTarget $target): ?array
    {
        $token = trim((string) $target->secret);

        /*
         * A shape check rather than a length check, and the reason is the
         * legacy credential: Cloudflare's global API key is a 37-character hex
         * string used with an email address, carries every permission on the
         * account, and cannot be scoped or revoked without changing the
         * account's own login. It is refused here rather than sent, so a
         * platform-wide credential cannot be onboarded by pasting it into the
         * box meant for a scoped token.
         */
        if (preg_match('/^[A-Za-z0-9_-]{20,}$/', $token) !== 1) {
            return null;
        }

        if (preg_match('/^[0-9a-f]{37}$/', $token) === 1) {
            return null;
        }

        return ['token' => $token];
    }

    /**
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function headers(#[SensitiveParameter] array $credential): array
    {
        return ['Authorization' => 'Bearer '.$credential['token']];
    }

    protected function identify(Response $response): IdentityProof
    {
        $body = $response->json();

        if (! $this->isCloudflareEnvelope($body)) {
            return IdentityProof::notThisProduct(
                'the endpoint answered, and the answer is not a Cloudflare API response: every Cloudflare v4 answer '
                .'carries a boolean "success" with "errors" and "messages" arrays, and this carried none of them.'
            );
        }

        /** @var array<string, mixed> $body */
        if ($body['success'] !== true) {
            return IdentityProof::credentialRejected(
                'the Cloudflare API rejected the token. It is wrong, revoked, or restricted to addresses this '
                .'controller does not use.'
            );
        }

        $status = IdentityProof::token(is_array($body['result'] ?? null) ? ($body['result']['status'] ?? null) : null);

        if ($status !== null && $status !== 'active') {
            return IdentityProof::credentialRejected(sprintf(
                'the Cloudflare API recognised the token and reports it as %s rather than active.',
                $status,
            ));
        }

        return IdentityProof::of('the Cloudflare API verified the token and reports it active.');
    }

    protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult
    {
        /*
         * One zone, not a page of them. What is being established is whether
         * the read path works at all, and asking for a single row makes the
         * test cheap enough to run on a schedule against an account with
         * thousands of zones.
         */
        $zones = $probe->get('/zones', ['per_page' => 1]);

        if ($zones->forbidden() || $zones->unauthorized()) {
            $probe->failed('authorise', 'the token is valid and is not permitted to list zones.');

            return ConnectionResult::of(
                ConnectionState::PermissionInsufficient,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The token verifies and holds no zone permission. Add Zone:Read — and, for the platform to manage '
                .'records, Zone:Edit — to the token\'s policy rather than issuing a new token.',
            );
        }

        if ($zones->serverError()) {
            $probe->failed('authorise', 'Cloudflare answered the zone list with a server error.');

            return ConnectionResult::of(
                ConnectionState::ProviderUnavailable,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'Cloudflare verified the token and is failing its own requests. This one is theirs to recover from.',
            );
        }

        $body = $zones->json();

        if (! $this->isCloudflareEnvelope($body) || $body['success'] !== true) {
            $probe->failed('authorise', 'Cloudflare did not answer the zone list in its own envelope.');

            return ConnectionResult::of(
                ConnectionState::NeedsReview,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The token verified and the zone list did not come back in a shape this platform understands.',
            );
        }

        /** @var array<string, mixed> $body */
        $zoneCount = is_array($body['result'] ?? null) ? count($body['result']) : 0;

        $probe->passed('authorise', 'the token may read this account\'s zones.');

        /*
         * `reconcile` is the platform's read of what the zone actually
         * contains, and it is the only capability here a read establishes.
         * Creating a zone, deleting one and writing a record are writes, and a
         * connection test that performed one to see whether it could would
         * have changed a customer's DNS to find out.
         *
         * The reverse-DNS driver shares this tester and its capability list —
         * set_ptr and clear_ptr — is entirely writes, so it comes back wholly
         * Unknown, correctly.
         */
        $probe->passed('capabilities', sprintf(
            'the read path works%s. Every capability of this driver that changes DNS stays unknown until it is used, '
            .'because establishing it would mean changing a customer\'s records.',
            $zoneCount === 0 ? ', and this account holds no zones yet' : '',
        ));

        return ConnectionResult::of(
            ConnectionState::Connected,
            $probe->steps,
            $this->capabilities($target, ['reconcile' => CapabilityState::Supported]),
        );
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $body
     */
    private function isCloudflareEnvelope(mixed $body): bool
    {
        return is_array($body)
            && array_key_exists('success', $body)
            && is_bool($body['success'])
            && isset($body['errors'], $body['messages'])
            && is_array($body['errors'])
            && is_array($body['messages']);
    }
}
