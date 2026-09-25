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
 *    the verdict. Every response in this class goes through one reader, and
 *    that reader refuses a MUTATION that comes back with no error field at all
 *    — because a create whose success cannot be established is not a success —
 *    and refuses a READ with no error field unless the body names a field the
 *    command it answers is known to return. Reads were once returned as
 *    whatever `parse_str` made of the body, and `licenceStatus()` then read
 *    any body at all as a valid licence: an unreadable node was recorded
 *    licensed and scheduled for paid orders (F-14).
 *
 * Nothing is read from a body the parser would have rewritten. `parse_str`
 * silently drops, renames and overwrites — a repeated key keeps its last
 * value, a key with a leading NUL vanishes with its value, `expire.date`
 * becomes `expire_date`, and everything past `max_input_vars` is cut off — and
 * a rule written over the parsed array cannot see any of it, because the
 * evidence is gone before the rule runs. So the gate is on the transformation,
 * not on the spellings anybody has thought of: see {@see self::parse()}.
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
     * For each read command, the fields its answer is known to carry.
     *
     * A read that comes back without an `error` field must name at least one
     * of these, or it is not an answer to the command that was asked: an
     * unrelated url-encoded page, a proxy's form, or a panel build that
     * answers a different question. A read command with no entry here is not
     * understood, and its answer is refused rather than returned.
     *
     * The CMD_API_SYSTEM_INFO list deliberately duplicates the one in the
     * Providers module's `DirectAdminConnectionTester::SYSTEM_INFO_KEYS`. The
     * two modules may not share infrastructure, and each list is the nominal
     * evidence for its own reader; a change to what DirectAdmin returns has to
     * be made in both.
     *
     * @var array<string, list<string>>
     */
    private const array READ_FIELDS = [
        'CMD_API_LICENSE' => ['status', 'state', 'expires', 'expiry', 'expire_date'],
        'CMD_API_SHOW_USERS' => ['list'],
        'CMD_API_SHOW_USER_CONFIG' => [
            'username', 'name', 'package', 'domain', 'email', 'ip', 'suspended',
            'vdomains', 'nsubdomains', 'mysql', 'nemails', 'quota', 'bandwidth',
        ],
        'CMD_API_SHOW_USER_USAGE' => [
            'quota', 'bandwidth', 'vdomains', 'nsubdomains', 'mysql', 'nemails', 'inode', 'db_quota',
        ],
        'CMD_API_SYSTEM_INFO' => [
            'loadavg', 'loadavg1', 'load1', 'one', 'kernel', 'os', 'uptime', 'version', 'hostname',
        ],
    ];

    /**
     * Fields of a command's READ_FIELDS that do not identify its answer alone.
     *
     * `quota` and `bandwidth` are in both the user-config and the user-usage
     * answers, so a config answer that carries nothing else could be a usage
     * answer. `version` and `hostname` are what almost any appliance says
     * about itself.
     *
     * CMD_API_SHOW_USER_USAGE has NO entry, and that is a known asymmetry
     * rather than an oversight nobody saw: a usage answer carries nothing but
     * counters, so `quota=100` alone is accepted as one. Recorded, not closed:
     * a usage figure is not what F-14 is about, and refusing it would need a
     * field DirectAdmin is not known to send.
     *
     * @var array<string, list<string>>
     */
    private const array GENERIC_READ_FIELDS = [
        'CMD_API_SHOW_USER_CONFIG' => ['quota', 'bandwidth'],
        'CMD_API_SYSTEM_INFO' => ['version', 'hostname'],
    ];

    /**
     * Where a licence answer puts the panel's word for its licence, and where
     * it puts the expiry. EVERY one present is read, not the first: a panel
     * that says `status=active&state=expired` has said "expired", and a
     * first-match read that stops at "active" sells the node.
     *
     * @var list<string>
     */
    private const array LICENCE_STATE_FIELDS = ['status', 'state'];

    /**
     * @var list<string>
     */
    private const array LICENCE_EXPIRY_FIELDS = ['expires', 'expiry', 'expire_date'];

    /**
     * The words this repository reads as "the licence serves".
     *
     * A whitelist, owned as this platform's vocabulary rather than as
     * DirectAdmin's documented one — the vendor's field values are an external
     * contract nobody here has verified. A word outside both lists is an
     * unconfirmed licence, never a serving one: guessing that an unfamiliar
     * word means "fine" is the defect this class once had.
     *
     * @var list<string>
     */
    private const array SERVING_LICENCE_STATES = ['active', 'valid', 'licensed'];

    /**
     * The words recorded verbatim as a licence that does not serve. Each fits
     * the 32-character `licence_status` column; an unknown word does not have
     * to, which is one more reason it is recorded as `unconfirmed` instead.
     *
     * @var list<string>
     */
    private const array NON_SERVING_LICENCE_STATES = [
        'expired', 'suspended', 'invalid', 'inactive', 'revoked', 'cancelled', 'canceled',
        'terminated', 'disabled', 'unlicensed', 'blocked',
    ];

    /**
     * The last year an expiry may name. Applied once, on the single return
     * path of {@see self::parseTimestamp()}, so that it meets every branch —
     * numeric and textual alike. It is a maximum only: a year below zero is
     * refused by the licence being in the past, not by this.
     */
    private const int LATEST_EXPIRY_YEAR = 9999;

    /**
     * A month's name, full or abbreviated, as one lowercase word.
     */
    private const string MONTH_NAME = '^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*$';

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
         * There are three answers, and only one of them sells:
         *
         *  - LICENSED: the panel answered its licence command, every word it
         *    used for the licence is one this repository reads as serving,
         *    and every expiry it gave is readable and in the future;
         *  - INVALID (or the panel's own non-serving word, verbatim): the
         *    panel itself said the licence does not serve, or dated it in the
         *    past. Renewing it is the remedy;
         *  - UNCONFIRMED: anything the platform could not read — no answer, a
         *    body the parser would have rewritten, an answer with no status
         *    word, a word nobody here knows, an expiry that names no day it
         *    can be trusted for. Not the same thing as a lapsed licence, and
         *    not a licence either.
         *
         * A node whose licence has lapsed refuses its API and says so, so a
         * refusal whose text mentions licensing is recorded as an invalid
         * licence rather than as an outage — the two need completely different
         * responses, and only one of them is fixed by waiting. That match is
         * made ONLY against words the panel spoke (`provider_spoke`): a
         * transport failure's message carries the request URL, the URL of
         * this command is `/CMD_API_LICENSE`, and matching "licen" against it
         * recorded every outage as a lapsed licence and sent an operator to
         * the vendor about a network fault.
         */
        try {
            $licence = $this->call($node, 'CMD_API_LICENSE', [], 'licence_status');
        } catch (HostingProviderException $e) {
            $context = $e->context();
            $message = (string) ($context['provider_message'] ?? '');
            $spoke = ($context['provider_spoke'] ?? false) === true;

            return new LicenceStatus(
                valid: false,
                product: self::NAME,
                state: $spoke && str_contains(strtolower($message), 'licen') ? 'invalid' : 'unconfirmed',
                // The exception's own message is composed by this class, so it
                // is safe to show; the panel's words were scrubbed on the way in.
                detail: $message === '' ? $e->getMessage() : $message,
            );
        }

        return $this->readLicence($licence);
    }

    /**
     * The licence, from an answer that has already passed every gate in
     * {@see self::parse()}.
     *
     * @param  array<string, mixed>  $licence
     */
    private function readLicence(array $licence): LicenceStatus
    {
        $raw = $this->redactor->redact($licence);

        $unconfirmed = fn (string $why): LicenceStatus => new LicenceStatus(
            valid: false,
            product: self::NAME,
            state: 'unconfirmed',
            detail: $why,
            raw: $raw,
        );

        $words = self::everyValue($licence, self::LICENCE_STATE_FIELDS);

        if ($words === null) {
            return $unconfirmed('the panel\'s licence answer carries a status field that is empty or not a single word');
        }

        if ($words === []) {
            // Not assumed active. The absence of a word is not the panel
            // saying the licence serves.
            return $unconfirmed('the panel answered its licence command without saying whether the licence serves');
        }

        $words = array_map(static fn (string $word): string => strtolower($word), $words);

        foreach ($words as $word) {
            if (in_array($word, self::NON_SERVING_LICENCE_STATES, true)) {
                return new LicenceStatus(
                    valid: false,
                    product: self::NAME,
                    state: $word,
                    detail: sprintf('the panel reports its licence as "%s"', $word),
                    raw: $raw,
                );
            }
        }

        foreach ($words as $word) {
            if (! in_array($word, self::SERVING_LICENCE_STATES, true)) {
                return $unconfirmed(sprintf(
                    'the panel describes its licence as "%s", which this platform does not read as serving',
                    $this->redactor->redactString(mb_substr($word, 0, 64)),
                ));
            }
        }

        $expiries = self::everyValue($licence, self::LICENCE_EXPIRY_FIELDS);

        if ($expiries === null) {
            return $unconfirmed('the panel\'s licence answer carries an expiry field that is empty or not a single value');
        }

        /*
         * The earliest expiry decides. Two dates in one answer is a panel
         * contradicting itself, and the only reading that cannot sell a lapsed
         * licence is the sooner one.
         */
        $earliest = null;

        foreach ($expiries as $expiry) {
            $at = self::parseTimestamp($expiry);

            if ($at === null) {
                // Refused, not guessed: an invented expiry is worse than none,
                // because it is believed — and "none" here would read as a
                // licence that never lapses.
                return $unconfirmed('the panel dates its licence in a form that names no day it can be trusted for');
            }

            $earliest = $earliest === null || $at->isBefore($earliest) ? $at : $earliest;
        }

        if ($earliest !== null && ! $earliest->isFuture()) {
            return new LicenceStatus(
                valid: false,
                product: self::NAME,
                state: 'expired',
                expiresAt: $earliest,
                detail: sprintf('the panel dates its licence as ending %s', $earliest->toIso8601String()),
                raw: $raw,
            );
        }

        return new LicenceStatus(
            valid: true,
            product: self::NAME,
            state: $words[0],
            expiresAt: $earliest,
            detail: 'the panel answered its licence command and says the licence serves',
            raw: $raw,
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
        $context = ['node' => $node->hostname, 'command' => $command, 'status' => $response->status()];

        /*
         * DirectAdmin answers an authentication failure, and some
         * misconfigurations, with an HTML login page and a 200. parse_str turns
         * that into one meaningless key, so the shape is checked rather than
         * assumed: a body that is not url-encoded key/value pairs has not been
         * understood, and a mutation that was not understood may still have
         * happened.
         */
        if (self::looksLikeHtml($body)) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the body is not a DirectAdmin url-encoded response',
                $context,
                indeterminate: $mutation,
            );
        }

        $loss = self::whatTheParserWouldLose($body);

        if ($loss !== null) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                $loss.', so the body cannot be read as the panel wrote it',
                $context,
                indeterminate: $mutation,
            );
        }

        /** @var array<string, mixed> $fields */
        $fields = [];
        parse_str($body, $fields);

        if ($fields === []) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the body is not a DirectAdmin url-encoded response',
                $context,
                indeterminate: $mutation,
            );
        }

        if (array_key_exists('error', $fields)) {
            $error = is_string($fields['error']) ? trim($fields['error']) : null;

            /*
             * Only a number is a verdict. `(int)` of anything else is 0, so a
             * cast here once read `error=none` — or an array — as the panel
             * saying the call succeeded.
             */
            if ($error === null || ! ctype_digit($error)) {
                throw HostingProviderException::unexpectedResponse(
                    self::NAME,
                    $operation,
                    'the error field is not a number, so the panel never said whether the call succeeded',
                    $context,
                    indeterminate: $mutation,
                );
            }

            if ((int) $error !== 0) {
                throw HostingProviderException::requestFailed(self::NAME, $operation, [
                    ...$context,
                    // The node's own words, scrubbed. DirectAdmin splits them
                    // over "text" (the headline) and "details" (the reason),
                    // and only the pair is useful: "Cannot Create User" alone
                    // does not say whether the name is taken or the package is
                    // missing.
                    'provider_message' => $this->scrub($node, self::messageFrom($fields)),
                    'panel_error' => 1,
                    // The message above is the PANEL speaking, not a transport
                    // failure describing the request. Only words the panel
                    // spoke may be read for what they say about its licence:
                    // see licenceStatus().
                    'provider_spoke' => true,
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

        /*
         * No error field on a READ. This used to return whatever parse_str
         * made of the body, and every reader then treated "no error" as an
         * answer — the licence reader as a valid licence. A read is accepted
         * without a verdict only when the body names a field the command is
         * known to return, and one that identifies it.
         */
        $identifying = array_diff(self::READ_FIELDS[$command] ?? [], self::GENERIC_READ_FIELDS[$command] ?? []);
        $named = array_map(static fn (int|string $key): string => (string) $key, array_keys($fields));

        if (array_intersect($identifying, $named) === []) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the response carries no error field and none of the fields this command returns, so it is not an answer to it',
                $context,
            );
        }

        return $fields;
    }

    /**
     * Why `parse_str` would not return what the panel wrote, or null when it
     * would.
     *
     * This is a gate on the TRANSFORMATION, not on spellings, and that is the
     * point of it. Each of these once let an unlicensed node be recorded
     * licensed, and none of them is visible in the parsed array, because the
     * parse is what destroys the evidence:
     *
     *  - a repeated key keeps only its last value (`status=expired&status=
     *    active`), including a repeat the body never spells as one
     *    (`%73tatus` IS `status`);
     *  - a key with a leading NUL is dropped whole, value and all, so the
     *    panel's "expired" is never seen and nothing says it was there;
     *  - a key is renamed: `expire.date`, `expire date` and `expire+date` all
     *    become `expire_date`, the key the expiry rule reads;
     *  - everything past `max_input_vars` pairs is cut off — and parse_str
     *    warns while doing it, which the framework turns into an exception
     *    that escapes this class. So the count is taken BEFORE parsing.
     *
     * Two checks catch all four without naming any of them: each pair must
     * come out of the parser under the name it went in with, and the parser
     * must keep exactly as many values as the body sent pairs. A whole-pair
     * drop by a spelling nobody here has thought of fails the second.
     *
     * Deliberately NOT caught, and recorded as this reader's owned limits:
     * key case is not normalised (`STATUS=expired&status=active` never reads
     * the uppercase word, because no rule reads that spelling), and a key
     * spelled some way this class does not read is simply unread — its value
     * survives in the redacted `raw`, which is where an operator finds it.
     */
    private static function whatTheParserWouldLose(string $body): ?string
    {
        $pairs = array_values(array_filter(explode('&', $body), static fn (string $pair): bool => $pair !== ''));
        $limit = (int) ini_get('max_input_vars');

        if ($limit > 0 && count($pairs) > $limit) {
            return sprintf('the body carries %d pairs and the parser keeps only the first %d', count($pairs), $limit);
        }

        foreach ($pairs as $pair) {
            $parsed = [];
            parse_str($pair, $parsed);

            $sent = urldecode(explode('=', $pair, 2)[0]);
            $bracket = strpos($sent, '[');
            $sent = $bracket === false ? $sent : substr($sent, 0, $bracket);

            if ($parsed === [] || (string) array_key_first($parsed) !== $sent) {
                return 'a key in the body would be renamed or dropped by the parser';
            }
        }

        $parsed = [];
        parse_str($body, $parsed);

        $kept = 0;
        array_walk_recursive($parsed, static function () use (&$kept): void {
            $kept++;
        });

        if ($kept !== count($pairs)) {
            return sprintf('the body sends %d pairs and the parser keeps %d, so a key is repeated or a pair is lost', count($pairs), $kept);
        }

        return null;
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
     * Every value the answer gives under any of $keys — not the first.
     *
     * Null when one of them is present but is not a single non-empty string
     * (an array from `status[]=…`, or an empty value): a field the panel sent
     * and this class cannot read is not the same as a field it did not send.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $keys
     * @return list<string>|null
     */
    private static function everyValue(array $fields, array $keys): ?array
    {
        $values = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $value = $fields[$key];

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $values[] = trim($value);
        }

        return $values;
    }

    /**
     * The panel's expiry, or null when it names no day it can be trusted for.
     *
     * Null rather than a guess: an invented expiry is worse than no expiry,
     * because it is believed. And the caller reads null as UNCONFIRMED, never
     * as "does not expire".
     *
     * Nothing in here may throw. The value is chosen by the node, and an
     * exception from Carbon once escaped this class, past the sync's handler
     * — which catches only HostingProviderException — on `expires=1e15`.
     *
     * The ceiling is applied here, on the single return path, so that it
     * meets every branch. It once sat inside the numeric branch alone, on the
     * premise that the textual branch already had one; it did not, and
     * `UTC+22099-01-01` licensed a node until the year 22099.
     */
    private static function parseTimestamp(string $value): ?CarbonImmutable
    {
        try {
            $parsed = self::readTimestamp(trim($value));
        } catch (Throwable) {
            return null;
        }

        return $parsed !== null && $parsed->year <= self::LATEST_EXPIRY_YEAR ? $parsed : null;
    }

    /**
     * The rules, in the order they run. Every one refuses; none repairs.
     *
     *  1. **Numbers are unix seconds, written as digits and nothing else.**
     *     `1e15`, `-1` and `4102358400.5` are numeric to PHP and are refused
     *     rather than reinterpreted. A millisecond timestamp is refused,
     *     never divided by a thousand — inventing a millisecond contract this
     *     repository has no evidence for would mis-license real nodes
     *     silently — and it is the year ceiling that refuses it: every value
     *     of thirteen digits or more is past the year 9999 as seconds. Two
     *     readings here are safe BY ACCIDENT rather than by design, and are
     *     labelled together because the class is the warning: `20991231`
     *     (Ymd) is 31 August 1970, and `0` — a common "no expiry" marker —
     *     is 1 January 1970. Both are in the past, so both refuse the node;
     *     neither is read as the date or the absence it means.
     *
     *  2. **`date_parse()` must report no error and no warning.** Its
     *     warnings are the parser saying it changed the value: an invalid
     *     calendar date is rolled forward (`2099-02-31` → 3 March), a
     *     sixtieth second rolls into the next day, and a leading token read
     *     as a zone collides with the real one ("Double timezone
     *     specification" — `V2099-01-01`, and `jan 2099-01-01`, which is a
     *     real date this rule refuses and is one of this method's disclosed
     *     over-refusals).
     *
     *  3. **It must name a year, a month and a day** as integers. The error
     *     count alone is not a readability test: `date_parse('2099.12.31')`
     *     reports no error with `month` and `day` both false. So dotted
     *     year-first (`Y.m.d`) is refused on every day — pre-existing and
     *     disclosed, and a panel that writes it is recorded unconfirmed
     *     always.
     *
     *  4. **No relative part.** `tomorrow` and `+1 year` resolve against the
     *     clock, so they sold the node on every sync, for ever. A weekday is
     *     the one relative part allowed through, and rule 8 decides it.
     *
     *  5. **Nothing before the date but the names of days and months.** A
     *     leading `V`, `UTC+` or `ACDT+` is read as a zone, and moves what
     *     follows.
     *
     *  6. **A numeric date must be one a reader cannot misread.** See
     *     {@see self::isAmbiguousNumericDate()}.
     *
     *  7. **A date names its day, month and year once each.** See
     *     {@see self::namesMoreThanADate()}.
     *
     *  8. **The day stored must be the day the value names.**
     *     `date_parse()` REPORTS a relative part and returns components;
     *     `CarbonImmutable::parse()` APPLIES it. They are different parsers,
     *     and every rule above reasons about the first while the second
     *     produces what is stored — so this rule is stated over the second:
     *     `Fri, 31 Dec 2099` is a Thursday, is stored as 1 January 2100, and
     *     is refused here.
     *
     * The year ceiling is not in this list because it is not in this method:
     * it is on the return path of parseTimestamp(), where every branch meets
     * it.
     *
     * WHAT THIS COSTS, stated because each item takes a working node out of
     * service. Measured by rendering every day from 2020-01-01 to 2100-12-31
     * (29,585 days) in each format and reading it back through
     * parseTimestamp(). Refused on NO day, and read as the day rendered:
     * `Y-m-d`, `Y-m-d H:i:s`, `c`, `r`, `D, d M Y`, `D, d M Y H:i:s O`,
     * `d M Y`, `M d, Y`, `M j Y`, `F j, Y`, `j F Y`, `Y/m/d`, `d-M-Y`,
     * `Y-m-d\TH:i:s\Z`, `Y-m-d\TH:i:s.uP`, `Y-m-d H:i:s \U\T\C` and `U`. The
     * rest:
     *
     *  - `m/d/Y`, `d-m-Y`, `d.m.Y`: refused on 10,692 days each — the days
     *    whose day and month are both 12 or less and differ (rule 6);
     *  - `Y.m.d`: refused on all 29,585 (rule 3);
     *  - `d-M-y`, with or without a clock or an attached `+0000`: the 11,322
     *    days from 2070 are read as 1970–2000, the two-digit-year pivot, and
     *    so are recorded as expired; `D, d M y` refuses the same days,
     *    because the weekday no longer matches after the pivot (rule 8);
     *  - `Ymd` is a number, and reads as 1970 (rule 1);
     *  - a bare `HHMM` clock after a date, and a month name in front of an
     *    ISO date, are refused (see namesMoreThanADate() and rule 2);
     *  - a word for "no expiry" — `never`, `unlimited`, `perpetual`, `none`,
     *    `-` — names no day and is refused, so a panel that writes a
     *    perpetual licence that way is recorded UNCONFIRMED where it was once
     *    recorded licensed. Deliberate: which words mean "never" is a
     *    vendor contract nobody here has evidence for, and the omission of
     *    an expiry field is read as no expiry already.
     */
    private static function readTimestamp(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ctype_digit($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
        }

        $parts = date_parse($value);

        if ($parts['error_count'] > 0 || $parts['warning_count'] > 0) {
            return null;
        }

        if (! is_int($parts['year']) || ! is_int($parts['month']) || ! is_int($parts['day'])) {
            return null;
        }

        if (self::carriesARelativePart($parts)
            || self::hasAPrefixThatIsNotAName($value)
            || self::isAmbiguousNumericDate($value)
            || self::namesMoreThanADate($value)
        ) {
            return null;
        }

        $parsed = CarbonImmutable::parse($value);

        if ($parsed->year !== $parts['year'] || $parsed->month !== $parts['month'] || $parsed->day !== $parts['day']) {
            return null;
        }

        return $parsed;
    }

    /**
     * A relative part other than a bare weekday.
     *
     * @param  array<string, mixed>  $parts  date_parse()'s answer
     */
    private static function carriesARelativePart(array $parts): bool
    {
        $relative = $parts['relative'] ?? null;

        if (! is_array($relative)) {
            return false;
        }

        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $unit) {
            if (($relative[$unit] ?? 0) !== 0) {
                return true;
            }
        }

        // "+2 weekdays", "first day of", "last day of": each moves the date.
        return isset($relative['weekdays'])
            || isset($relative['first_day_of_month'])
            || isset($relative['last_day_of_month']);
    }

    private static function hasAPrefixThatIsNotAName(string $value): bool
    {
        $prefix = strtolower((string) preg_replace('/[0-9].*$/s', '', $value));

        foreach (preg_split('/[^a-z]+/', $prefix, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (preg_match('/^(mon|tue|wed|thu|fri|sat|sun)[a-z]*$|'.self::MONTH_NAME.'/', $word) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A numeric date whose day and month a reader cannot tell apart, or whose
     * year field is not exactly four digits.
     *
     * Applied to the first `n{sep}n{sep}n` in the value, with `/`, `-` or `.`
     * as the separator:
     *
     *  - **day or month first** (the first field is one or two digits): the
     *    year is the LAST field and must be exactly four digits, the middle
     *    field at most two, and when both of the first two are 12 or less and
     *    differ, the value is refused whatever the separator — `12/01/2026`
     *    is 1 December to PHP and 12 January to half the panels that write
     *    it, and the first reading sold an already-expired licence. This is
     *    the one priced over-refusal of the rule: a panel writing `m/d/Y`,
     *    `d-m-Y` or `d.m.Y` is refused on every day whose day is 12 or less
     *    and not equal to its month;
     *
     *  - **year first** (the first field is wider): the year is the FIRST
     *    field and must be exactly four digits, and the other two at most two.
     *    The four-digit test applies to whichever field is the year: a rule
     *    that only tested the last field never reached `99999999/01/31` at
     *    all — it did not fail the test, it never entered it — and that value
     *    licensed a node until 9999. `99999/12/31` is worse in kind: both
     *    parsers agree on 12 September 2031, both wrong, so no rule about the
     *    OUTCOME can see it. `2099/12/99999` and `2099-01-012:45` are the same
     *    rule's other two fields.
     */
    private static function isAmbiguousNumericDate(string $value): bool
    {
        if (preg_match('/^[^0-9]*([0-9]+)([\/.\-])([0-9]+)\2([0-9]+)/', $value, $m) !== 1) {
            return false;
        }

        [, $first, , $second, $third] = $m;

        if (strlen($first) > 2) {
            return strlen($first) !== 4 || strlen($second) > 2 || strlen($third) > 2;
        }

        if (strlen($third) !== 4 || strlen($second) > 2) {
            return true;
        }

        return (int) $first <= 12 && (int) $second <= 12 && (int) $first !== (int) $second;
    }

    /**
     * Whether the value names a number beyond the day, month and year of one
     * date — which is how a second year gets in.
     *
     * timelib reads a bare four-digit token after a date as a clock `HHMM`
     * when it is a legal time and as a YEAR when it is not, and it does so
     * with no error and no warning; both parsers then agree, so rule 8
     * cannot see it. `2020-01-01 9999` recorded a licence that expired in
     * 2020 as running to 9999. The same holds for a two-digit-year date
     * (`01-Jan-20 9999`), for the years glued into one run
     * (`01 Jan 20209999`), and for a number behind a clock
     * (`Jan 1 20 23:59:59T2035`).
     *
     * So the question is not "how many four-digit runs are there" — a
     * two-digit-year date has none of its own, and that rule removed nothing
     * — but "how many numbers does the value name once the clock and the zone
     * are set aside". A date with a month NAME has two (day and year); a
     * numeric date has three. More is a second thing; a number wider than
     * four digits is refused outright, and so is a second number wider than
     * two: only a year is, and `3401 Jan 2020` has the right count and two
     * years (timelib takes `3401` as the year and spends `2020` on a clock).
     *
     * A scope statement, because the rule is easy to over-read: it refuses a
     * value that names TWO years. It has nothing to say about a panel that
     * names one wrong year, and one residue is known and accepted — a digit
     * glued onto a date with no separator makes a different well-formed date
     * (`01-Jan-20` and `58` is `01-Jan-2058`), which only refusing `d-M-y`
     * outright would close.
     *
     * What is set aside, in this order, which matters:
     *
     *  1. an offset SEPARATED by whitespace (`… +0000`, `… -05:00`) whose
     *     hours are at most 14 and minutes at most 59. The whitespace
     *     lookbehind is load-bearing: without it `31-12-1200 9999` has its
     *     own year `-1200` eaten as a zone, leaves one year, and reads as
     *     31 December 9999;
     *  2. an offset ATTACHED to what precedes it (`15-Jun-27+0000`,
     *     `…T23:59:59+00:00`), only when BOTH limbs hold — the offset is a
     *     valid spelling, and everything before it is a complete date on its
     *     own. `31-12-1200` ends in `-1200`, which is a valid offset's
     *     spelling exactly; the second limb is what separates the two (`31-12`
     *     is not a date), and without it the same row licenses a node to
     *     31 December 9999. The trailing `(?![0-9])` stops an offset matching
     *     the front of a longer run: `+868783` is not `+8687` and `83`;
     *  3. a clock, `H:MM`, `H:MM:SS`, with a fraction ONLY after seconds. A
     *     fraction allowed after `HH:MM` would eat `.9999` behind a colonned
     *     offset and leave the date's own year as the only number;
     *
     * Offsets go first so that a clock pattern cannot claim the `00:00` of a
     * `+00:00`.
     *
     * The disclosed cost: a bare `HHMM` clock (`31-Dec-69 2359`) is refused,
     * because it is the same shape as the second year and no test on the
     * token separates them. A panel is not known to write it.
     */
    private static function namesMoreThanADate(string $value): bool
    {
        $rest = (string) preg_replace_callback(
            '/(?<=\s)[+-]([0-9]{2}):?([0-9]{2})(?![0-9])/',
            static fn (array $m): string => self::isAnOffset($m[1], $m[2]) ? ' ' : $m[0],
            $value,
        );

        $rest = (string) preg_replace_callback(
            '/(?<=[0-9A-Za-z])[+-]([0-9]{2}):?([0-9]{2})(?![0-9])/',
            static function (array $m) use ($rest): string {
                [$offset, $at] = $m[0];

                return self::isAnOffset($m[1][0], $m[2][0]) && self::isACompleteDate(substr($rest, 0, $at))
                    ? ' '
                    : $offset;
            },
            $rest,
            flags: PREG_OFFSET_CAPTURE,
        );

        $rest = (string) preg_replace('/(?<![0-9])[0-9]{1,2}:[0-9]{2}(?::[0-9]{2}(?:[.,][0-9]+)?)?(?![0-9:])/', ' ', $rest);

        preg_match_all('/[0-9]+/', $rest, $numbers);

        $wide = 0;

        foreach ($numbers[0] as $number) {
            if (strlen($number) > 4) {
                return true;
            }

            $wide += strlen($number) > 2 ? 1 : 0;
        }

        if ($wide > 1) {
            return true;
        }

        $named = preg_match('/(?<![a-z])(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i', $rest) === 1 ? 2 : 3;

        return count($numbers[0]) !== $named;
    }

    private static function isAnOffset(string $hours, string $minutes): bool
    {
        return (int) $hours <= 14 && (int) $minutes <= 59;
    }

    /**
     * Whether $value, alone, is a date `date_parse()` reads without complaint
     * and with a year, a month and a day. The attached-offset limb of
     * namesMoreThanADate() asks it of whatever precedes the offset.
     */
    private static function isACompleteDate(string $value): bool
    {
        $parts = date_parse($value);

        return $parts['error_count'] === 0
            && $parts['warning_count'] === 0
            && is_int($parts['year'])
            && is_int($parts['month'])
            && is_int($parts['day']);
    }

    /**
     * How long a brokered panel session stays usable.
     */
    private static function ssoLifetimeMinutes(): int
    {
        return max(1, (int) config('hosting.sso.lifetime_minutes', 15));
    }
}
