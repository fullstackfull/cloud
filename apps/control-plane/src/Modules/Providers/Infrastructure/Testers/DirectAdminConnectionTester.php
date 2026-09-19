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
 * DirectAdmin, whose API is url-encoded, answers failures with HTTP 200, and
 * answers an unauthenticated request with a web page.
 *
 * ===========================================================================
 * THE WEAKEST IDENTITY SURFACE OF THE REAL DRIVERS, AND WHAT IS DONE ABOUT IT
 * ===========================================================================
 *
 * Proxmox names its version in a documented envelope; WHM echoes the command
 * it ran; Cloudflare has an endpoint whose entire purpose is to verify a
 * token; a Redfish service root declares its own specification version.
 * DirectAdmin does none of that. It returns `key=value&key=value`, which is a
 * shape rather than a name, and `parse_str` will happily turn almost any text
 * into one key.
 *
 * So three things are required together, and each rules out a different
 * impostor:
 *
 *   1. the body is not HTML — which rules out error pages, portals and the
 *      panel's own login screen;
 *   2. it parses as url-encoded pairs with at least one non-empty value —
 *      which rules out plain text, because a body with no `=` in it is not a
 *      DirectAdmin answer;
 *   3. at least one key is one DirectAdmin is documented to return for the
 *      command that was asked — which is the nominal half, and the one that
 *      distinguishes this from any other product that happens to answer in
 *      url-encoded pairs.
 *
 * The login page is handled separately and deliberately: DirectAdmin answers
 * an unauthenticated API request with its own HTML login screen, and that page
 * carries the product's name. So an HTML body naming DirectAdmin is proof of
 * identity *and* proof that the credential was refused — which is exactly
 * {@see IdentityProof::credentialRejected()}, and not a mismatch. An HTML body
 * that does not name DirectAdmin is a mismatch, because the platform does not
 * know whose login page it is looking at.
 *
 * ===========================================================================
 * error=1 WITH HTTP 200
 * ===========================================================================
 *
 * The adapter's own parse() exists for this and says so: DirectAdmin returns a
 * success status line for a refused command and puts the refusal in the body.
 * Every branch below reads the body first and the status code last.
 */
final class DirectAdminConnectionTester extends HttpIdentityTester
{
    /**
     * Keys DirectAdmin returns for CMD_API_SYSTEM_INFO, across the builds this
     * platform has had to accommodate.
     *
     * The alternatives are not hedging: the hosting adapter beside this one
     * reads load average from the first of four spellings that is present,
     * because DirectAdmin has spelled it differently in different versions.
     * One of them being there is the nominal evidence.
     *
     * @var list<string>
     */
    private const array SYSTEM_INFO_KEYS = [
        'loadavg', 'loadavg1', 'load1', 'one', 'kernel', 'os', 'uptime', 'version', 'hostname',
    ];

    public function __construct()
    {
        parent::__construct('directadmin');
    }

    protected function baseUrl(TestTarget $target): string
    {
        // DirectAdmin serves its commands at the root of the same host and
        // port as its web interface: /CMD_API_SYSTEM_INFO and so on.
        return rtrim((string) $target->endpoint, '/');
    }

    protected function identityPath(): string
    {
        return '/CMD_API_SYSTEM_INFO';
    }

    protected function credentialShape(): string
    {
        return 'a DirectAdmin login key, stored as the user name and the key joined by a colon: the user, then a '
            .'colon, then the login key. An account password is not accepted by this platform.';
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseCredential(TestTarget $target): ?array
    {
        if (preg_match('/^(?<user>[A-Za-z0-9._-]{1,64}):(?<key>\S+)$/', (string) $target->secret, $parts) !== 1) {
            return null;
        }

        return ['user' => $parts['user'], 'key' => $parts['key']];
    }

    /**
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function headers(#[SensitiveParameter] array $credential): array
    {
        // HTTP Basic, which is what DirectAdmin's API expects, with the login
        // key standing in for the password.
        return ['Authorization' => 'Basic '.base64_encode($credential['user'].':'.$credential['key'])];
    }

    protected function identify(Response $response): IdentityProof
    {
        $body = $response->body();

        if ($this->looksLikeHtml($body)) {
            return preg_match('/directadmin/i', $body) === 1
                ? IdentityProof::credentialRejected(
                    'the node answered with DirectAdmin\'s own login page, which is what DirectAdmin returns for an '
                    .'API request it will not authenticate.'
                )
                : IdentityProof::notThisProduct(
                    'the endpoint answered with an HTML page that does not name DirectAdmin, so what is at this '
                    .'address cannot be established.'
                );
        }

        $fields = $this->urlEncoded($body);

        if ($fields === null) {
            return IdentityProof::notThisProduct(
                'the endpoint answered, and the answer is not a DirectAdmin response: DirectAdmin answers in '
                .'url-encoded key=value pairs, and this was not in that form.'
            );
        }

        if ($this->refused($fields)) {
            $reason = strtolower((string) ($fields['text'] ?? '').' '.($fields['details'] ?? ''));

            /*
             * A refusal is still an answer from DirectAdmin — it arrived in
             * DirectAdmin's own error form — so the identity is established
             * either way, and only the classification changes. A refusal
             * mentioning authentication is the credential; anything else is
             * left for classify() to read, which is where the licence case
             * lives.
             */
            if (preg_match('/(login|auth|access|password|denied|permission)/', $reason) === 1) {
                return IdentityProof::credentialRejected(
                    'DirectAdmin answered in its own error form and refused the login key.'
                );
            }

            return IdentityProof::of('DirectAdmin answered in its own error form.');
        }

        $recognised = array_values(array_intersect(self::SYSTEM_INFO_KEYS, array_keys($fields)));

        if ($recognised === []) {
            return IdentityProof::notThisProduct(
                'the endpoint answered in url-encoded pairs and none of them is a field DirectAdmin returns for its '
                .'system information command. A shape alone is not an identity.'
            );
        }

        return IdentityProof::of(sprintf(
            'DirectAdmin answered its system information command, reporting %s.',
            implode(', ', array_slice($recognised, 0, 3)),
        ));
    }

    protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult
    {
        $identityFields = $this->urlEncoded($identity->body()) ?? [];

        if ($this->refused($identityFields) && $this->mentionsALicence($identityFields)) {
            $probe->failed('licence', 'the node reports no valid DirectAdmin licence, so it is refusing its API.');

            return ConnectionResult::of(
                ConnectionState::LicenceMissing,
                $probe->steps,
                $this->allOf($target, CapabilityState::BlockedLicence),
                'The node is a DirectAdmin node with no valid licence. Renew it with DirectAdmin; nothing in the '
                .'credential centre will make this node serve accounts.',
            );
        }

        $licence = $probe->get('/CMD_API_LICENSE');
        $licenceFields = $this->urlEncoded($licence->body()) ?? [];

        if ($this->refused($licenceFields) && $this->mentionsALicence($licenceFields)) {
            $probe->failed('licence', 'the node\'s licence command reports the licence as invalid.');

            return ConnectionResult::of(
                ConnectionState::LicenceMissing,
                $probe->steps,
                $this->allOf($target, CapabilityState::BlockedLicence),
                'The node reports an invalid DirectAdmin licence.',
            );
        }

        $probe->passed('licence', $licenceFields === []
            ? 'the node answered its API, so its licence is serving. It did not answer the licence command, so the expiry date is unknown.'
            : 'the node answered its own licence command.');

        /*
         * Reading the account list is the one capability a read establishes,
         * and it doubles as the admin check: DirectAdmin refuses this command
         * to a credential that is a reseller or a user rather than an
         * administrator, and this platform places accounts, which only an
         * administrator may do.
         */
        $users = $probe->get('/CMD_API_SHOW_USERS');
        $userFields = $this->urlEncoded($users->body());

        if ($userFields === null || $this->refused($userFields)) {
            $probe->failed('authorise', 'the credential may not list the node\'s accounts, so it is not an administrator on it.');

            return ConnectionResult::of(
                ConnectionState::PermissionInsufficient,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
                'The login key is accepted and may not list accounts, so it is not an administrator on this node. '
                .'This platform creates and terminates accounts, which requires an administrator login key.',
            );
        }

        $probe->passed('authorise', 'the credential may list the node\'s accounts, so it is an administrator on it.');

        /*
         * Everything else this driver does — create, suspend, terminate,
         * change a package, open an SSO session — is a write, and a connection
         * test that performed one to find out whether it could would have
         * created or destroyed a customer's hosting account to answer the
         * question.
         */
        $probe->passed('capabilities', 'the read path works. Every capability that changes an account stays unknown '
            .'until it is used, because establishing it would mean changing one.');

        return ConnectionResult::of(
            ConnectionState::Connected,
            $probe->steps,
            $this->capabilities($target, ['usage' => CapabilityState::Supported]),
        );
    }

    /**
     * A url-encoded DirectAdmin body, parsed, or null when the body is not one.
     *
     * The non-empty-value requirement is what rules out plain text. `parse_str`
     * turns "Not Found" into one key with an empty value and would otherwise
     * look like a successful parse, which is how a 404 from an unrelated web
     * server would have passed for a DirectAdmin answer.
     *
     * @return array<string, string>|null
     */
    private function urlEncoded(string $body): ?array
    {
        $body = trim($body);

        if ($body === '' || ! str_contains($body, '=') || is_array(json_decode($body, true))) {
            return null;
        }

        $parsed = [];
        parse_str($body, $parsed);

        $fields = [];
        $hasValue = false;

        foreach ($parsed as $key => $value) {
            /*
             * Scalar and repeated keys both count towards "this is a
             * DirectAdmin answer", and only the scalars are returned.
             *
             * DirectAdmin's list commands answer `list[]=alice&list[]=bob`,
             * which parse_str turns into a nested array. An earlier version of
             * this method kept only string values and so read a perfectly good
             * account list as an empty body — and then reported an
             * administrator credential as not being one. The test that caught
             * it is the positive case beside the negative matrix, which is
             * what a positive twin is for.
             */
            if (is_array($value)) {
                $hasValue = $hasValue || array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== '') !== [];

                continue;
            }

            $fields[(string) $key] = $value;
            $hasValue = $hasValue || trim($value) !== '';
        }

        return $hasValue ? $fields : null;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function refused(array $fields): bool
    {
        return array_key_exists('error', $fields) && (int) $fields['error'] !== 0;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function mentionsALicence(array $fields): bool
    {
        // The node's own words are matched here and kept nowhere: the text is
        // free-form output from a product this platform does not control, and
        // the detail column it would reach is persisted and audited.
        $said = strtolower(($fields['text'] ?? '').' '.($fields['details'] ?? '').' '.($fields['status'] ?? ''));

        return str_contains($said, 'licen');
    }

    private function looksLikeHtml(string $body): bool
    {
        $head = strtolower(ltrim(mb_substr($body, 0, 256)));

        return str_starts_with($head, '<!doctype') || str_starts_with($head, '<html') || str_contains($head, '<head');
    }
}
