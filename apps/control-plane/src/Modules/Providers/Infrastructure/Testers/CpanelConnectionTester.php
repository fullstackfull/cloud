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
 * cPanel/WHM, which has a licence that can lapse and an ACL that can be wrong,
 * and answers both with an HTTP 200.
 *
 * ===========================================================================
 * WHAT COUNTS AS PROOF
 * ===========================================================================
 *
 * WHM's API 1 answers carry a `metadata` object naming the command that was
 * run: asking `version` comes back with `metadata.command` of `version` and a
 * `metadata.result` of 1 or 0. Something echoing the command it was asked for,
 * inside a structure with a documented name, is not something a proxy or a
 * default vhost produces.
 *
 * ===========================================================================
 * THE THREE FAILURES THIS PRODUCT HAS THAT OTHERS DO NOT
 * ===========================================================================
 *
 * **A lapsed licence.** cPanel is a commercial product and an unlicensed node
 * stops serving, answering with its own licence error page rather than with
 * the API. That page is still proof of identity — only cPanel serves it — so
 * it is identified as cPanel and reported as {@see ConnectionState::LicenceMissing}.
 * That distinction is the entire reason the Licence Center exists: nobody can
 * fix this by rotating a token, and an operator sent to do so loses a day.
 *
 * **A token with the wrong ACLs.** WHM tokens carry an access control list,
 * and a token that may read accounts and not create them is a working
 * credential for a node that cannot be sold on. `myprivs` reports the list
 * without exercising any of it.
 *
 * **result: 0 with HTTP 200.** WHM reports a refused command in the body and
 * a success in the status line, so the status code is read last here and never
 * on its own.
 *
 * ===========================================================================
 * WHY ABSENT PRIVILEGES DO NOT BECOME Unsupported
 * ===========================================================================
 *
 * Unlike Proxmox's privilege names, WHM's ACL keys have been renamed and added
 * to across versions. Claiming a capability is Unsupported because a key this
 * platform expects is missing would block a product on a node that serves it
 * perfectly well — so a recognised key makes a capability Supported and an
 * unrecognised one leaves it Unknown. A false Unknown costs a line on a
 * readiness screen; a false Unsupported takes a product off sale.
 */
final class CpanelConnectionTester extends HttpIdentityTester
{
    /**
     * Capability → the WHM ACL that grants it.
     *
     * @var array<string, string>
     */
    private const array ACLS = [
        'create_account' => 'create-acct',
        'terminate' => 'kill-acct',
        'suspend' => 'suspend-acct',
        'unsuspend' => 'suspend-acct',
        'change_package' => 'upgrade-account',
        'usage' => 'list-accts',
    ];

    public function __construct()
    {
        parent::__construct('cpanel');
    }

    protected function baseUrl(TestTarget $target): string
    {
        return rtrim((string) $target->endpoint, '/').'/json-api';
    }

    protected function identityPath(): string
    {
        return '/version';
    }

    /** @return array<string, string|int> */
    protected function identityQuery(): array
    {
        return ['api.version' => 1];
    }

    protected function credentialShape(): string
    {
        return 'a WHM API token, stored as the WHM user name and the token joined by a colon: the user, then a '
            .'colon, then the token. A root password is not accepted by this platform.';
    }

    /**
     * @return array<string, string>|null
     */
    protected function parseCredential(TestTarget $target): ?array
    {
        if (preg_match('/^(?<user>[A-Za-z0-9._-]{1,64}):(?<token>\S+)$/', (string) $target->secret, $parts) !== 1) {
            return null;
        }

        return ['user' => $parts['user'], 'token' => $parts['token']];
    }

    /**
     * @param  array<string, string>  $credential
     * @return array<string, string>
     */
    protected function headers(#[SensitiveParameter] array $credential): array
    {
        /*
         * WHM's own header format: "whm <user>:<token>", with no scheme prefix
         * and no base64, matching WhmConnection. It goes in a header and never
         * in the query string — a token in a URL is a token in the access log
         * of every reverse proxy between here and the node.
         */
        return ['Authorization' => sprintf('whm %s:%s', $credential['user'], $credential['token'])];
    }

    protected function identify(Response $response): IdentityProof
    {
        $body = $response->json();

        if (is_array($body) && $this->answeredCommand($body, 'version')) {
            $version = IdentityProof::token(is_array($body['data'] ?? null) ? ($body['data']['version'] ?? null) : null);

            if ($this->refusedFor($body, 'access')) {
                return IdentityProof::credentialRejected('WHM answered its own version command and refused the token.');
            }

            return IdentityProof::of($version === null
                ? 'WHM answered its own version command.'
                : sprintf('WHM answered its own version command and reported version %s.', $version));
        }

        if (is_array($body) && array_key_exists('statusmsg', $body)) {
            /*
             * cPanel's older error shape, which is what an unauthenticated or
             * ACL-refused request gets on some versions. The key name is
             * specific to the product, so it identifies it; what it says
             * decides whether this is a refusal or something else.
             */
            return $response->unauthorized() || $response->forbidden()
                ? IdentityProof::credentialRejected('a cPanel error response refused the token.')
                : IdentityProof::of('a cPanel error response answered, so the product is cPanel.');
        }

        if ($this->looksLikeALicenceError($response)) {
            return IdentityProof::of('the node answered with cPanel\'s own licence error page.');
        }

        return IdentityProof::notThisProduct(
            'the endpoint answered, and the answer is not a WHM API response: WHM names the command it ran in a '
            .'"metadata" object, and this had none.'
        );
    }

    protected function classify(Probe $probe, TestTarget $target, IdentityProof $proof, Response $identity): ConnectionResult
    {
        if ($this->looksLikeALicenceError($identity) || $this->refusedFor((array) $identity->json(), 'licen')) {
            $probe->failed('licence', 'the node reports no valid cPanel licence, so it is not serving its API.');

            return ConnectionResult::of(
                ConnectionState::LicenceMissing,
                $probe->steps,
                $this->allOf($target, CapabilityState::BlockedLicence),
                'The node is a cPanel node with no valid licence. Renew it with cPanel; no credential change will '
                .'make this node serve accounts.',
            );
        }

        $probe->passed('licence', 'the node answered its API, so its licence is serving.');

        $privileges = $probe->get('/myprivs', ['api.version' => 1]);

        // Normalised once. A node that answered with something that is not a
        // JSON object has told us nothing about the access list, and an empty
        // array carries that through the two questions below without either of
        // them having to ask again what shape the body was.
        $body = is_array($privileges->json()) ? (array) $privileges->json() : [];
        $acls = is_array($body['data'] ?? null) ? $body['data'] : null;

        if ($acls === null || ! $this->answeredCommand($body, 'myprivs')) {
            $probe->passed('capabilities', 'the node did not report this token\'s access list, so what it may do is unknown until an account operation is attempted.');

            return ConnectionResult::of(
                ConnectionState::Connected,
                $probe->steps,
                $this->allOf($target, CapabilityState::Unknown),
            );
        }

        if ((bool) ($acls['all'] ?? false)) {
            $probe->passed('capabilities', 'the token holds the root access list, so every account operation is permitted.');

            return ConnectionResult::of(
                ConnectionState::Connected,
                $probe->steps,
                $this->allOf($target, CapabilityState::Supported),
            );
        }

        $states = [];

        foreach (self::ACLS as $capability => $acl) {
            if ((bool) ($acls[$acl] ?? false)) {
                $states[$capability] = CapabilityState::Supported;
            }
        }

        $writes = array_diff_key($states, ['usage' => true]);

        $probe->passed('capabilities', sprintf(
            'the token holds %d of the access-list entries this platform uses. Entries it does not hold are left '
            .'unknown rather than refused, because WHM has renamed ACL keys between versions.',
            count($states),
        ));

        return ConnectionResult::of(
            $writes === [] ? ConnectionState::ConnectedReadOnly : ConnectionState::Connected,
            $probe->steps,
            $this->capabilities($target, $states),
            $writes === []
                ? 'The token is accepted and holds no access-list entry that changes an account. Correct for a node '
                    .'being onboarded read-only.'
                : null,
        );
    }

    /**
     * Did WHM say it ran the command we asked for?
     *
     * @param  array<array-key, mixed>  $body
     */
    private function answeredCommand(array $body, string $command): bool
    {
        $metadata = $body['metadata'] ?? null;

        return is_array($metadata)
            && array_key_exists('result', $metadata)
            && ($metadata['command'] ?? null) === $command;
    }

    /**
     * A refusal whose reason mentions something, with the reason then thrown
     * away.
     *
     * The node's own words are matched and never kept: WHM's `reason` is
     * free text from a product this platform does not control, and the
     * `detail` column it would land in is persisted, rendered and audited.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function refusedFor(array $body, string $marker): bool
    {
        $metadata = $body['metadata'] ?? null;

        if (! is_array($metadata) || (int) ($metadata['result'] ?? 1) !== 0) {
            return false;
        }

        return str_contains(strtolower((string) ($metadata['reason'] ?? '')), $marker);
    }

    /**
     * cPanel's licence error, which arrives as a web page rather than as API.
     *
     * Both markers are required. A page mentioning a licence could be
     * anything; a page mentioning a licence *and* cPanel is cPanel's, and the
     * pair is what makes this identification rather than guessing.
     */
    private function looksLikeALicenceError(Response $response): bool
    {
        $body = $response->body();

        if (is_array($response->json())) {
            return false;
        }

        return preg_match('/licen[sc]e/i', $body) === 1 && preg_match('/cpanel/i', $body) === 1;
    }
}
