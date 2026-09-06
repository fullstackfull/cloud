<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingAccountResult;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodeHealth;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeNotConfiguredException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Throwable;

/**
 * The DirectAdmin API, spoken properly.
 *
 * DirectAdmin differs from WHM in two ways that decide the shape of this
 * class, and getting either wrong produces the same outcome — an account the
 * platform believes it created and the node has never heard of:
 *
 *  - **it does not answer in JSON.** The API replies in url-encoded form
 *    (`error=1&text=Cannot+Create+User&details=...`), so the body is parsed
 *    with parse_str rather than json_decode. Calling ->json() on a DirectAdmin
 *    response returns null, and code that then treats "no error key" as
 *    success reports every failure as a success;
 *
 *  - **it signals failure with `error=1` and HTTP 200.** The status line is not
 *    the verdict. Every response in this class goes through one reader that
 *    refuses to return data unless the panel said `error=0`, and that refuses
 *    outright when a MUTATION comes back with no error field at all — because a
 *    create whose success cannot be established is not a success.
 *
 * Authentication is a login key in an HTTP Basic header, never the admin
 * password. A login key is scoped to a list of commands, restrictable by
 * source address, individually expiring and individually revocable; the admin
 * password also opens the web interface and cannot be rotated without breaking
 * every other integration at the same moment.
 *
 * TLS verification is on unless the node's own row waives it, and no exception
 * from the HTTP client is allowed to escape: a client exception stringifies
 * the request, and every request here carries the login key.
 */
final class DirectAdminHostingProvider implements HostingProvider
{
    public const string NAME = 'directadmin';

    /**
     * Commands whose answer must contain an explicit `error` field.
     *
     * These are the ones that change something. A create, a suspension or a
     * deletion that came back without DirectAdmin saying whether it worked is
     * treated as unknown rather than as done — the same rule that makes WHM's
     * metadata envelope mandatory here.
     *
     * @var list<string>
     */
    private const array MUTATING_COMMANDS = [
        'CMD_API_ACCOUNT_USER',
        'CMD_API_SELECT_USERS',
        'CMD_API_MODIFY_USER',
        'CMD_API_USER_PASSWD',
        'CMD_API_LOGIN_KEYS',
    ];

    /**
     * Connections, memoised per node.
     *
     * @var array<string, DirectAdminConnection>
     */
    private array $connections = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    public function panel(): HostingPanel
    {
        return HostingPanel::DirectAdmin;
    }

    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult
    {
        $fields = $this->call($node, 'CMD_API_ACCOUNT_USER', [
            'action' => 'create',
            // DirectAdmin's API still expects the submit button's value; the
            // command is a no-op without it and answers error=0, which would
            // otherwise be read as a successful create of nothing.
            'add' => 'Submit',
            'username' => $request->username,
            'email' => $request->contactEmail,
            'passwd' => $request->password,
            'passwd2' => $request->password,
            'domain' => $request->primaryDomain,
            'package' => $request->packageName,
            // "shared" unless the caller asked for a dedicated address, which
            // DirectAdmin resolves from the node's free pool.
            'ip' => $request->ipAddress ?? ($request->dedicatedIp ? 'free' : 'shared'),
            // The panel's own welcome mail is suppressed: the platform sends
            // its own, in the customer's language, from an address the customer
            // recognises and with the platform's support details rather than
            // the node's hostname.
            'notify' => 'no',
            ...$request->extra,
        ], 'create_account');

        return new HostingAccountResult(
            // DirectAdmin echoes nothing back but its own status text, so the
            // requested name is the account name. That is safe only because
            // the caller has already truncated to the panel's limit.
            username: $request->username,
            primaryDomain: $request->primaryDomain,
            packageName: $request->packageName,
            ipAddress: $this->stringOrNull($fields['ip'] ?? null),
            metadata: $this->redactor->redact($fields),
        );
    }

    public function suspendAccount(HostingNode $node, string $username, string $reason): void
    {
        $this->call($node, 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Suspend',
            'select0' => $username,
            /*
             * Only newer DirectAdmin builds record a reason, and one that does
             * not simply ignores the field. The platform's own row is
             * authoritative for why an account was suspended — it has to be,
             * because the reason drives dunning and the retention clock, and
             * neither may depend on what a particular node's build supports.
             */
            'reason' => $reason,
        ], 'suspend_account');
    }

    public function unsuspendAccount(HostingNode $node, string $username): void
    {
        $this->call($node, 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Unsuspend',
            'select0' => $username,
        ], 'unsuspend_account');
    }

    public function terminateAccount(HostingNode $node, string $username): void
    {
        $this->call($node, 'CMD_API_SELECT_USERS', [
            // Both are required, and the pair is the whole safety interlock in
            // DirectAdmin's deletion API: delete=yes without confirmed=Confirm
            // returns a confirmation page rather than deleting, which an
            // adapter that only checked for error=0 would report as a
            // successful termination of an account that is still there and
            // still occupying the node.
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => $username,
        ], 'terminate_account');
    }

    public function changePackage(HostingNode $node, string $username, string $packageName): void
    {
        $this->call($node, 'CMD_API_MODIFY_USER', [
            'action' => 'package',
            'user' => $username,
            'package' => $packageName,
        ], 'change_package');
    }

    public function changePassword(HostingNode $node, string $username, string $password): void
    {
        $this->call($node, 'CMD_API_USER_PASSWD', [
            'username' => $username,
            'passwd' => $password,
            'passwd2' => $password,
        ], 'change_password');
    }

    public function accountUsage(HostingNode $node, string $username): AccountUsage
    {
        // Two calls because DirectAdmin splits the answer: the config carries
        // what the account is ALLOWED, the usage carries what it has SPENT.
        // Reporting one as the other is how a customer at 5% of quota gets a
        // suspension notice.
        $config = $this->call($node, 'CMD_API_SHOW_USER_CONFIG', ['user' => $username], 'account_usage');
        $usage = $this->call($node, 'CMD_API_SHOW_USER_USAGE', ['user' => $username], 'account_usage');

        return new AccountUsage(
            username: $username,
            diskUsedMib: self::toMib($usage['quota'] ?? null),
            diskQuotaMib: self::toMib($config['quota'] ?? null),
            bandwidthUsedMib: self::toMib($usage['bandwidth'] ?? null),
            bandwidthQuotaMib: self::toMib($config['bandwidth'] ?? null),
            addonDomains: self::toIntOrNull($config['vdomains'] ?? null),
            subdomains: self::toIntOrNull($config['nsubdomains'] ?? null),
            databases: self::toIntOrNull($config['mysql'] ?? null),
            emailAccounts: self::toIntOrNull($config['nemails'] ?? null),
            suspended: isset($config['suspended']) ? self::toBool($config['suspended']) : null,
            raw: $this->redactor->redact(['config' => $config, 'usage' => $usage]),
        );
    }

    public function listAccounts(HostingNode $node): array
    {
        $body = $this->call($node, 'CMD_API_SHOW_USERS', [], 'list_accounts');

        /*
         * DirectAdmin answers with names only: list[]=user1&list[]=user2. The
         * domain would be one CMD_API_SHOW_USER_CONFIG per account, which on a
         * node holding several hundred of them is several hundred round trips
         * every reconciliation pass. Reconciliation needs to know which
         * accounts exist, not what they are called on the web, so the domain is
         * left null rather than paid for.
         */
        /** @var list<string> $names */
        $names = array_values(array_filter(
            is_array($body['list'] ?? null) ? $body['list'] : [],
            static fn (mixed $name): bool => is_string($name) && trim($name) !== '',
        ));

        return array_map(
            static fn (string $name): RemoteAccount => new RemoteAccount(
                username: trim($name),
                primaryDomain: null,
            ),
            $names,
        );
    }

    public function nodeHealth(HostingNode $node): NodeHealth
    {
        $info = $this->call($node, 'CMD_API_SYSTEM_INFO', [], 'node_health');

        return new NodeHealth(
            online: true,
            // Several DirectAdmin builds spell these differently, so each is
            // read from the first key that is present. A key nobody matched
            // stays null: a load average of zero would make the busiest node in
            // the fleet look like the best candidate in it.
            loadOne: self::firstFloat($info, ['loadavg1', 'load1', 'one', 'loadavg']),
            loadFive: self::firstFloat($info, ['loadavg5', 'load5', 'five']),
            loadFifteen: self::firstFloat($info, ['loadavg15', 'load15', 'fifteen']),
            panelVersion: $this->stringOrNull($info['version'] ?? null) ?? $node->panel_version,
            raw: $this->redactor->redact($info),
        );
    }

    public function licenceStatus(HostingNode $node): LicenceStatus
    {
        /*
         * Read from the vendor's own product and reported as given. Nothing
         * here extends, emulates or revalidates a licence: DirectAdmin is a
         * commercial product, and the platform's only correct response to an
         * expired licence is to stop placing accounts on the node and tell an
         * operator to renew it.
         *
         * A node whose licence has lapsed refuses its API and says so, so a
         * failure whose text mentions licensing is recorded as an invalid
         * licence rather than as an outage — the two need completely different
         * responses, and only one of them is fixed by waiting.
         */
        try {
            $licence = $this->call($node, 'CMD_API_LICENSE', [], 'licence_status');
        } catch (HostingProviderException $e) {
            $message = (string) ($e->context()['provider_message'] ?? '');

            return new LicenceStatus(
                valid: false,
                product: self::NAME,
                state: str_contains(strtolower($message), 'licen') ? 'invalid' : 'unconfirmed',
                detail: $message === '' ? 'the node gave no detail' : $message,
            );
        }

        $expiry = self::firstString($licence, ['expires', 'expiry', 'expire_date']);

        return new LicenceStatus(
            valid: true,
            product: self::NAME,
            state: self::firstString($licence, ['status', 'state']) ?? 'active',
            expiresAt: $expiry === null ? null : self::parseTimestamp($expiry),
            detail: 'the panel answered its licence command',
            raw: $this->redactor->redact($licence),
        );
    }

    public function createSsoSession(HostingNode $node, string $username): SsoSession
    {
        /*
         * A one-time login URL, created server-side. The customer's own
         * DirectAdmin password is never involved: the platform does not hold
         * it, so there is nothing here that could be replayed into a login
         * form, and the key the panel returns is single-use and short-lived.
         */
        $keyName = 'lynomia-sso-'.Str::lower((string) Str::ulid());
        $expiresAt = CarbonImmutable::now()->addMinutes(self::ssoLifetimeMinutes());

        $response = $this->call($node, 'CMD_API_LOGIN_KEYS', [
            'action' => 'create',
            'type' => 'one_time_url',
            'keyname' => $keyName,
            'user' => $username,
            // One use, and an expiry measured in minutes. A key that outlived
            // the click is a standing credential for the customer's control
            // panel sitting in a browser history and a proxy log.
            'max_uses' => 1,
            'never_expires' => 'no',
            'expiry_timestamp' => $expiresAt->getTimestamp(),
            'select_allow0' => 'ALL',
        ], 'create_sso_session');

        $url = $this->stringOrNull($response['url'] ?? null);

        if ($url !== null) {
            $url = $this->assertSsoUrlBelongsToNode($node, $url, $username);
        }

        if ($url === null) {
            $key = $this->stringOrNull($response['key'] ?? null);

            if ($key === null) {
                throw HostingProviderException::unexpectedResponse(
                    self::NAME,
                    'create_sso_session',
                    'the node accepted the login-key request but returned neither a URL nor a key',
                    ['node' => $node->hostname, 'username' => $username],
                );
            }

            $url = sprintf(
                '%s/?username=%s&key=%s',
                $this->connection($node)->baseUrl(),
                rawurlencode($username),
                rawurlencode($key),
            );
        }

        return new SsoSession(
            url: $url,
            username: $username,
            service: 'directadmin',
            expiresAt: $expiresAt,
        );
    }

    /**
     * The panel does not get to choose where the customer's browser goes.
     *
     * The URL in an SSO answer is response data from a node, and the node is
     * exactly the machine a reseller operates, a customer shares, or an
     * attacker has taken. Returned verbatim it is a link the portal invites
     * the customer to click — a login page on a host of the node's choosing,
     * looking exactly like the panel they expected.
     *
     * The port is deliberately NOT compared — a node may serve its panel on a
     * port other than the one its API answers on. The host is, against both
     * the endpoint the platform dialled and the node's recorded hostname,
     * because an endpoint may be an address while the panel answers with its
     * name.
     *
     * @throws HostingProviderException
     */
    private function assertSsoUrlBelongsToNode(HostingNode $node, string $url, string $username): string
    {
        $parts = parse_url($url);

        $permitted = array_filter([
            is_array($endpoint = parse_url($this->connection($node)->baseUrl())) ? ($endpoint['host'] ?? null) : null,
            $node->hostname,
        ]);

        if (is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array($parts['host'] ?? null, $permitted, true)
        ) {
            return $url;
        }

        throw HostingProviderException::unexpectedResponse(
            self::NAME,
            'create_sso_session',
            'the node returned a session URL pointing somewhere other than itself, and it will not be '
            .'handed to a customer',
            ['node' => $node->hostname, 'username' => $username],
        );
    }

    /**
     * Make one DirectAdmin API call and return its parsed body, or translate
     * the failure.
     *
     * @param  array<string, scalar|null>  $parameters
     * @return array<string, mixed>
     */
    private function call(HostingNode $node, string $command, array $parameters, string $operation): array
    {
        return $this->parse(
            $node,
            $this->send($node, $command, $parameters, $operation),
            $command,
            $operation,
        );
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     */
    private function send(HostingNode $node, string $command, array $parameters, string $operation): Response
    {
        try {
            $request = $this->request($node);

            // Reads go as GET because DirectAdmin's read commands take their
            // parameters in the query string; writes go as POST so the account
            // name and package never appear in the node's access log.
            $response = in_array($command, self::MUTATING_COMMANDS, true)
                ? $request->post('/'.$command, $parameters)
                : $request->get('/'.$command, $parameters);

            $this->assertNotRedirect($node, $response, $operation, ['command' => $command]);

            return $response;
        } catch (ConnectionException $e) {
            /*
             * Indeterminate, deliberately. This is a timeout or a dropped
             * connection: the platform stopped waiting, the node did not stop
             * working. DirectAdmin builds a home directory, a mail store, a
             * database user and a DNS zone before it answers a create, and a
             * create the platform gave up on may well have been accepted.
             * Retrying is how a customer ends up with two accounts.
             */
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'command' => $command,
                'provider_message' => $this->scrub($node, $e->getMessage()),
            ], previous: $e, indeterminate: true);
        } catch (HostingNodeNotConfiguredException $e) {
            /*
             * Raised while building the connection, before a single byte left
             * this process. The outcome at the node is not unknown — nothing
             * was ever addressed to it — so this must NOT be flagged
             * indeterminate. Indeterminate is the platform's most expensive
             * verdict: it quarantines the job, holds the node's slot and sends
             * an operator to reconcile against the panel. A missing config key
             * deserves an ordinary failure and a message naming the key.
             */
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'command' => $command,
                'error_code' => $e->errorCode(),
                'provider_message' => $this->scrub($node, $e->getMessage()),
            ], previous: $e);
        } catch (HostingProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'command' => $command,
                'provider_message' => $this->scrub($node, $e->getMessage()),
            ], previous: $e, indeterminate: true);
        }
    }

    /**
     * A 3xx is not an answer, it is a destination chosen by the node.
     *
     * Redirects are disabled on the client, so one arrives here as an ordinary
     * response. It is refused rather than parsed: DirectAdmin reports failure
     * in the body of a 200, so an unrefused redirect would parse as an empty
     * body with no `error=1` and be read as success. Flagged indeterminate
     * because a node that answered a create with a redirect may still have
     * created the account.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws HostingProviderException
     */
    private function assertNotRedirect(HostingNode $node, Response $response, string $operation, array $context = []): void
    {
        if ($response->status() < 300 || $response->status() > 399) {
            return;
        }

        throw HostingProviderException::unexpectedResponse(
            self::NAME,
            $operation,
            'the node answered with a redirect, which is not followed because the request carries the login '
            .'key and, on a 307 or 308, the account password in its body',
            [...$context, 'node' => $node->hostname, 'status' => $response->status()],
            indeterminate: true,
        );
    }

    private function request(HostingNode $node): PendingRequest
    {
        $connection = $this->connection($node);

        return Http::baseUrl($connection->baseUrl())
            // The login key travels in a Basic header and never in the URL: a
            // credential in a query string is a credential in every reverse
            // proxy's access log between here and the node.
            ->withHeaders(['Authorization' => $connection->authorizationHeader()])
            // Explicit rather than left to the client default, so a future
            // change to that default cannot silently disable certificate
            // verification for every node at once.
            //
            // Redirects are refused too. A Location header is chosen by the
            // node, and on a 307 or 308 the client re-posts the body to it —
            // and the body of CMD_API_USER_PASSWD or CMD_API_ACCOUNT_USER is
            // the customer's plaintext panel password.
            ->withOptions(['verify' => $connection->verifyTls, 'allow_redirects' => false])
            ->timeout($connection->timeoutSeconds)
            ->asForm();
    }

    /**
     * Read a DirectAdmin answer: url-encoded, with `error=1` meaning failure.
     *
     * This method is the whole point of the adapter. DirectAdmin returns HTTP
     * 200 for a refused create and puts the refusal in the body; an adapter
     * that trusts the status code reports accounts as created that do not
     * exist.
     *
     * @return array<string, mixed>
     */
    private function parse(HostingNode $node, Response $response, string $command, string $operation): array
    {
        $mutation = in_array($command, self::MUTATING_COMMANDS, true);

        if ($response->failed()) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'command' => $command,
                'status' => $response->status(),
                'provider_message' => $this->scrub($node, mb_substr(trim($response->body()), 0, 512)),
            ], indeterminate: self::isIndeterminateStatus($response->status()));
        }

        $body = trim($response->body());

        /** @var array<string, mixed> $fields */
        $fields = [];
        parse_str($body, $fields);

        /*
         * DirectAdmin answers an authentication failure, and some
         * misconfigurations, with an HTML login page and a 200. parse_str turns
         * that into one meaningless key, so the shape is checked rather than
         * assumed: a body that is not url-encoded key/value pairs has not been
         * understood, and a mutation that was not understood may still have
         * happened.
         */
        if ($fields === [] || self::looksLikeHtml($body)) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the body is not a DirectAdmin url-encoded response',
                ['node' => $node->hostname, 'command' => $command, 'status' => $response->status()],
                indeterminate: $mutation,
            );
        }

        if (array_key_exists('error', $fields)) {
            if ((int) $fields['error'] !== 0) {
                throw HostingProviderException::requestFailed(self::NAME, $operation, [
                    'node' => $node->hostname,
                    'command' => $command,
                    'status' => $response->status(),
                    // The node's own words, scrubbed. DirectAdmin splits them
                    // over "text" (the headline) and "details" (the reason),
                    // and only the pair is useful: "Cannot Create User" alone
                    // does not say whether the name is taken or the package is
                    // missing.
                    'provider_message' => $this->scrub($node, self::messageFrom($fields)),
                    'panel_error' => 1,
                ]);
            }

            return $fields;
        }

        if ($mutation) {
            /*
             * No error field on a mutation. The panel never said whether it
             * did the thing, so the platform must not record that it did.
             * Marked indeterminate because the account may well exist.
             */
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the response carries no error field, so the panel never said whether the call succeeded',
                ['node' => $node->hostname, 'command' => $command, 'status' => $response->status()],
                indeterminate: true,
            );
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function messageFrom(array $fields): string
    {
        $parts = [];

        foreach (['text', 'details', 'result'] as $key) {
            $value = $fields[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                // DirectAdmin puts HTML lists inside "details"; stripped so a
                // log line stays a log line.
                $parts[] = trim(strip_tags($value));
            }
        }

        return $parts === []
            ? 'the panel rejected the request without giving a reason'
            : implode(': ', $parts);
    }

    private static function looksLikeHtml(string $body): bool
    {
        $head = strtolower(ltrim(substr($body, 0, 64)));

        return str_starts_with($head, '<!doctype') || str_starts_with($head, '<html');
    }

    /**
     * A 502, 503 or 504 comes from a proxy in front of DirectAdmin and says
     * nothing about whether the request reached it. A 4xx is the node
     * answering.
     */
    private static function isIndeterminateStatus(int $status): bool
    {
        return in_array($status, [408, 429, 502, 503, 504], true);
    }

    /**
     * Remove this node's credential, then everything else that looks like one.
     *
     * The explicit replacement comes first: the shared redactor recognises
     * credential shapes, and a DirectAdmin login key is an opaque string that
     * appears in no pattern anybody could write without knowing this
     * deployment's configuration.
     */
    private function scrub(HostingNode $node, string $message): string
    {
        $connection = $this->connections[(string) $node->getKey()] ?? null;

        if ($connection !== null) {
            $message = str_replace(
                [$connection->loginKey, $connection->authorizationHeader(), base64_encode($connection->username.':'.$connection->loginKey)],
                SecretRedactor::PLACEHOLDER,
                $message,
            );
        }

        return $this->redactor->redactString($message);
    }

    private function connection(HostingNode $node): DirectAdminConnection
    {
        return $this->connections[(string) $node->getKey()] ??= DirectAdminConnection::forNode($node);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * DirectAdmin reports quota and bandwidth in megabytes, as bare numbers,
     * and writes an absent limit as "unlimited".
     *
     * Unlimited returns null rather than 0, because a quota of zero means "may
     * store nothing", which is the exact opposite and would have the platform
     * suspending accounts for exceeding a limit they do not have.
     */
    private static function toMib(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = strtolower(trim($value));

        if ($raw === '' || $raw === 'unlimited') {
            return null;
        }

        return is_numeric($raw) ? (int) round((float) $raw) : null;
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && strtolower(trim($value)) === 'unlimited') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * DirectAdmin writes booleans as "yes"/"no" and occasionally "ON"/"OFF".
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) (is_scalar($value) ? $value : ''))), ['yes', 'on', '1', 'true'], true);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $keys
     */
    private static function firstFloat(array $fields, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $fields[$key] ?? null;

            if (is_numeric($value)) {
                return (float) $value;
            }

            // "0.15 0.20 0.30" — one key carrying all three averages, which is
            // how some builds answer.
            if (is_string($value) && preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $value, $matches) === 1) {
                return (float) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $keys
     */
    private static function firstString(array $fields, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $fields[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Null rather than a guess when the vendor's date cannot be read: an
     * invented expiry is worse than no expiry, because it is believed.
     */
    private static function parseTimestamp(string $value): ?CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * How long a brokered panel session stays usable.
     */
    private static function ssoLifetimeMinutes(): int
    {
        return max(1, (int) config('hosting.sso.lifetime_minutes', 15));
    }
}
