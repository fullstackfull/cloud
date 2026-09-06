<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
 * The WHM API v1, spoken properly.
 *
 * Four decisions define this adapter, and each of them rules out something the
 * internet is full of examples of:
 *
 *  - **metadata.result decides, not the HTTP status.** WHM answers a rejected
 *    createacct with HTTP 200 and a body saying `"result": 0` plus a reason.
 *    An adapter that checks only the status reports the account as created,
 *    provisioning marks the service active, welcome mail goes out with login
 *    details, and the customer discovers over the following days that nothing
 *    they were sold exists. This is the classic WHM integration bug, and every
 *    single response in this class goes through one envelope reader that
 *    refuses to return data unless the panel said the call succeeded;
 *
 *  - **authentication is an API token in a header, and only that.** WHM also
 *    accepts the root password; that is remote root, over SSH as well as over
 *    the API, on a machine holding several hundred customers' websites,
 *    databases and mail, and it cannot be rotated without locking every other
 *    integration out at the same instant. A token is scoped to a set of WHM
 *    functions and revocable on its own. There is no code path here that sends
 *    a password;
 *
 *  - **TLS verification is on** unless a specific node row turns it off, and it
 *    can only be turned off for that node. The usual shortcut — a global
 *    "verify: false" for the lab — is how a production node ends up handing its
 *    root API token to whoever answers the TCP connection;
 *
 *  - **no exception from the HTTP client is ever allowed to escape.** A client
 *    exception stringifies the request that caused it, and every request this
 *    class makes carries the API token in its Authorization header. Failures
 *    are translated here, with the token removed by name before the shared
 *    redactor's generic patterns get a look in — the redactor cannot be
 *    expected to recognise a secret whose shape only this object knows.
 *
 * A timeout is reported as indeterminate rather than as a failure. createacct
 * builds a home directory, a mail store, a MySQL user and a DNS zone before
 * WHM answers, and a busy node takes far longer over that than the platform
 * waits. The account may well exist; retrying is how a customer ends up with
 * two.
 */
final class CpanelHostingProvider implements HostingProvider
{
    public const string NAME = 'cpanel';

    /**
     * The cPanel service an SSO session is opened against. "cpaneld" is the
     * customer's own control panel; "whostmgrd" would be the reseller/root
     * interface and must never be brokered for a hosting customer.
     */
    private const string SSO_SERVICE = 'cpaneld';

    /**
     * Connections, memoised per node.
     *
     * A usage sweep makes one call per account against the same node, and
     * rebuilding the credential lookup for each would be pure waste.
     *
     * @var array<string, WhmConnection>
     */
    private array $connections = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    public function panel(): HostingPanel
    {
        return HostingPanel::Cpanel;
    }

    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult
    {
        $data = $this->call($node, 'createacct', [
            'username' => $request->username,
            'domain' => $request->primaryDomain,
            'password' => $request->password,
            'plan' => $request->packageName,
            'contactemail' => $request->contactEmail,
            // WHM's own spelling of the flag: "y" allocates a dedicated
            // address, "n" puts the account on the node's shared one. Asking
            // for a dedicated address on a node with none free is a refusal
            // rather than a silent downgrade, which is what the platform wants
            // — a customer who paid for a dedicated IP and quietly got a shared
            // one has a mail deliverability problem nobody will connect to this.
            'ip' => $request->dedicatedIp ? 'y' : 'n',
            // No shell. A shared hosting account with shell access can read
            // the node's process list and, on a node without CloudLinux, other
            // customers' files.
            'hasshell' => 0,
            'cgi' => 1,
            ...($request->locale === null ? [] : ['locale' => $request->locale]),
            ...$request->extra,
        ], 'create_account');

        $fields = $this->asArray($data);

        /*
         * The account name is taken from WHM's answer when it gives one.
         * cPanel truncates a username it considers too long, and every later
         * call — suspend, terminate, usage, SSO — is addressed by name. A
         * platform holding the name it asked for rather than the one that
         * exists would be unable to touch the account it just sold.
         *
         * Not every WHM build echoes it, so the requested name is the
         * fallback. That is safe here only because the caller has already
         * truncated to the panel's limit; it is not a licence to skip that.
         */
        $username = $this->stringOrNull($fields['user'] ?? $fields['username'] ?? null) ?? $request->username;

        return new HostingAccountResult(
            username: $username,
            primaryDomain: $request->primaryDomain,
            packageName: $request->packageName,
            ipAddress: $this->stringOrNull($fields['ip'] ?? null),
            nameserver: $this->stringOrNull($fields['nameserver'] ?? null),
            metadata: $this->redactor->redact($fields),
        );
    }

    public function suspendAccount(HostingNode $node, string $username, string $reason): void
    {
        $this->call($node, 'suspendacct', [
            'user' => $username,
            // Passed through so the customer sees why when they try to log in,
            // and so an operator on the node does not have to guess.
            'reason' => $reason,
        ], 'suspend_account');
    }

    public function unsuspendAccount(HostingNode $node, string $username): void
    {
        $this->call($node, 'unsuspendacct', ['user' => $username], 'unsuspend_account');
    }

    public function terminateAccount(HostingNode $node, string $username): void
    {
        $this->call($node, 'removeacct', [
            'user' => $username,
            // The DNS zone goes too. Leaving it behind means the node keeps
            // answering authoritatively for a domain it no longer hosts, which
            // is how a customer who moved away finds their mail still being
            // delivered here.
            'keepdns' => 0,
        ], 'terminate_account');
    }

    public function changePackage(HostingNode $node, string $username, string $packageName): void
    {
        $this->call($node, 'changepackage', [
            'user' => $username,
            'pkg' => $packageName,
        ], 'change_package');
    }

    public function changePassword(HostingNode $node, string $username, string $password): void
    {
        $data = $this->call($node, 'passwd', [
            'user' => $username,
            'password' => $password,
            // Databases and mail carry their own copies of the account
            // password on cPanel. Without this the panel login changes and the
            // customer's databases keep the old one, which surfaces as "my
            // site broke after a password reset".
            'db_pass_update' => 1,
        ], 'change_password');

        /*
         * WHM's passwd nests a second verdict inside the envelope: the outer
         * metadata says the CALL succeeded, and data.passwd[0].status says
         * whether the PASSWORD was actually changed. A caller that stops at the
         * envelope reports success for a password the node rejected, and the
         * customer is handed credentials that do not work.
         */
        $inner = $this->asArray($data)['passwd'][0] ?? null;

        if (is_array($inner) && isset($inner['status']) && (int) $inner['status'] !== 1) {
            throw HostingProviderException::requestFailed(self::NAME, 'change_password', [
                'node' => $node->hostname,
                'username' => $username,
                'provider_message' => $this->scrub($node, (string) ($inner['statusmsg'] ?? 'the node refused the password change')),
            ]);
        }
    }

    public function accountUsage(HostingNode $node, string $username): AccountUsage
    {
        $data = $this->call($node, 'accountsummary', ['user' => $username], 'account_usage');

        $account = $this->firstAccountRow($data);

        if ($account === null) {
            /*
             * The call succeeded and named no account. Reported as an empty
             * reading rather than as an error, and specifically not as zeroes:
             * the sync refuses to write a record it has no numbers for, which
             * is what stops a panel hiccup from clearing a customer's usage
             * and lifting every quota that depends on it.
             */
            return new AccountUsage(username: $username);
        }

        return new AccountUsage(
            username: $this->stringOrNull($account['user'] ?? null) ?? $username,
            diskUsedMib: self::toMib($account['diskused'] ?? null),
            diskQuotaMib: self::toMib($account['disklimit'] ?? null),
            bandwidthUsedMib: self::toMib($account['totalbytes'] ?? $account['bwusage'] ?? null),
            bandwidthQuotaMib: self::toMib($account['bwlimit'] ?? null),
            addonDomains: self::toIntOrNull($account['maxaddons'] ?? null),
            subdomains: self::toIntOrNull($account['maxsub'] ?? null),
            databases: self::toIntOrNull($account['maxsql'] ?? null),
            emailAccounts: self::toIntOrNull($account['maxpop'] ?? null),
            suspended: isset($account['suspended']) ? (bool) $account['suspended'] : null,
            raw: $this->redactor->redact($account),
        );
    }

    public function listAccounts(HostingNode $node): array
    {
        $data = $this->call($node, 'listaccts', [], 'list_accounts');

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->asArray($this->asArray($data)['acct'] ?? []);

        $accounts = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $username = $this->stringOrNull($row['user'] ?? null);

            if ($username === null) {
                continue;
            }

            $accounts[] = new RemoteAccount(
                username: $username,
                primaryDomain: $this->stringOrNull($row['domain'] ?? null),
                packageName: $this->stringOrNull($row['plan'] ?? null),
                suspended: (bool) ($row['suspended'] ?? false),
                diskUsedMib: self::toMib($row['diskused'] ?? null),
                ipAddress: $this->stringOrNull($row['ip'] ?? null),
                raw: $this->redactor->redact($row),
            );
        }

        return $accounts;
    }

    public function nodeHealth(HostingNode $node): NodeHealth
    {
        $load = $this->asArray($this->call($node, 'loadavg', [], 'node_health'));

        return new NodeHealth(
            online: true,
            loadOne: self::toFloatOrNull($load['one'] ?? null),
            loadFive: self::toFloatOrNull($load['five'] ?? null),
            loadFifteen: self::toFloatOrNull($load['fifteen'] ?? null),
            // WHM reports disk per account rather than per filesystem through
            // this API, so fleet disk comes from the node's own agent and is
            // deliberately left unset here. A zero would be far worse than a
            // null: disk is the heaviest term in placement, and a node
            // claiming an empty filesystem is the one the scheduler would fill.
            panelVersion: $node->panel_version,
            raw: $this->redactor->redact($load),
        );
    }

    public function licenceStatus(HostingNode $node): LicenceStatus
    {
        /*
         * The licence is read from the vendor's own product, never inferred
         * around it. An unlicensed WHM refuses its API and says so in the
         * envelope's reason; a licensed one serves. So the check is a cheap
         * authenticated call, and the panel's own words are what get recorded.
         *
         * Nothing here extends, emulates or revalidates a licence. cPanel is a
         * commercial product, and the platform's only correct response to an
         * expired licence is to stop placing accounts on the node and tell an
         * operator to renew it.
         *
         * The honest limit of this check is worth stating: it detects a licence
         * that has ALREADY stopped the panel, not one expiring next week. The
         * authoritative expiry lives in the vendor's licence store, which this
         * platform does not proxy.
         */
        try {
            $this->call($node, 'loadavg', [], 'licence_status');
        } catch (HostingProviderException $e) {
            $message = strtolower((string) ($e->context()['provider_message'] ?? ''));

            $looksLikeLicensing = str_contains($message, 'licen'); // licence / license / licensing

            return new LicenceStatus(
                valid: false,
                product: self::NAME,
                state: $looksLikeLicensing ? 'invalid' : 'unconfirmed',
                detail: $looksLikeLicensing
                    ? (string) ($e->context()['provider_message'] ?? 'the node reported a licensing problem')
                    : 'the node could not be asked: '.(string) ($e->context()['provider_message'] ?? 'no detail'),
            );
        }

        return new LicenceStatus(
            valid: true,
            product: self::NAME,
            state: 'active',
            detail: 'the panel served an authenticated API call',
        );
    }

    public function createSsoSession(HostingNode $node, string $username): SsoSession
    {
        $data = $this->asArray($this->call($node, 'create_user_session', [
            'user' => $username,
            'service' => self::SSO_SERVICE,
        ], 'create_sso_session'));

        $url = $this->stringOrNull($data['url'] ?? null);

        if ($url === null) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                'create_sso_session',
                'the node accepted the session request but returned no URL',
                ['node' => $node->hostname, 'username' => $username],
            );
        }

        return new SsoSession(
            url: $url,
            username: $username,
            service: self::SSO_SERVICE,
            expiresAt: isset($data['expires']) && is_numeric($data['expires'])
                ? CarbonImmutable::createFromTimestampUTC((int) $data['expires'])
                : null,
        );
    }

    /**
     * Make one WHM API v1 call and return its data, or translate the failure.
     *
     * @param  array<string, scalar|null>  $parameters
     */
    private function call(HostingNode $node, string $function, array $parameters, string $operation): mixed
    {
        return $this->dataFrom(
            $node,
            $this->send($node, $function, $parameters, $operation),
            $operation,
            ['node' => $node->hostname, 'function' => $function],
        );
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     */
    private function send(HostingNode $node, string $function, array $parameters, string $operation): Response
    {
        try {
            return $this->request($node)->post($function, [
                // Pinned on every call. Without it WHM answers in whichever
                // format the node's default is, and the older format has no
                // metadata envelope at all — so the success check silently
                // stops running against exactly the nodes most likely to fail.
                'api.version' => 1,
                ...$parameters,
            ]);
        } catch (ConnectionException $e) {
            /*
             * Caught by type and re-thrown as our own. A connection exception
             * from the client carries the full request in its message, and
             * every request this class makes has the API token in its
             * Authorization header.
             *
             * Flagged indeterminate, which is the important part. This branch
             * is a timeout or a dropped connection: the platform stopped
             * waiting, the node did not stop working. A createacct that timed
             * out has very possibly been accepted, and reported as an ordinary
             * failure it would be indistinguishable from a refusal — with a
             * caller retrying it into a second account.
             */
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'function' => $function,
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
                'function' => $function,
                'error_code' => $e->errorCode(),
                'provider_message' => $this->scrub($node, $e->getMessage()),
            ], previous: $e);
        } catch (HostingProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Anything else from the transport is unknown by definition — we
            // do not know whether the request reached the node — so it is
            // treated with the same caution as a timeout.
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'function' => $function,
                'provider_message' => $this->scrub($node, $e->getMessage()),
            ], previous: $e, indeterminate: true);
        }
    }

    private function request(HostingNode $node): PendingRequest
    {
        $connection = $this->connection($node);

        return Http::baseUrl($connection->baseUrl())
            ->withHeaders(['Authorization' => $connection->authorizationHeader()])
            // Explicit rather than left to the client default, so that a future
            // change to that default cannot silently disable certificate
            // verification for every node at once.
            ->withOptions(['verify' => $connection->verifyTls])
            ->timeout($connection->timeoutSeconds)
            ->acceptJson()
            // WHM takes form-encoded parameters on every endpoint.
            ->asForm();
    }

    /**
     * Unwrap WHM's {"metadata": {...}, "data": {...}} envelope.
     *
     * This method is the whole point of the adapter. An HTTP 200 whose
     * metadata says result=0 is a FAILURE — the account was not created, the
     * suspension did not happen, the password did not change — and treating it
     * as success is the classic WHM integration bug. It is checked here, once,
     * so that no call site can forget it.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function dataFrom(HostingNode $node, Response $response, string $operation, array $context = []): mixed
    {
        if ($response->failed()) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                ...$context,
                'status' => $response->status(),
                'provider_message' => $this->errorMessage($node, $response),
            ], indeterminate: self::isIndeterminateStatus($response->status()));
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the body is not a WHM API envelope',
                [...$context, 'status' => $response->status()],
                indeterminate: self::mutates($operation),
            );
        }

        $metadata = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : null;

        if ($metadata === null || ! array_key_exists('result', $metadata)) {
            /*
             * No envelope means the success check cannot run, and a mutation
             * whose success cannot be established is not a success. Refusing
             * here rather than falling back to the HTTP status is deliberate:
             * the fallback is exactly the bug.
             */
            throw HostingProviderException::unexpectedResponse(
                self::NAME,
                $operation,
                'the response carries no metadata.result, so the panel never said whether the call succeeded',
                [...$context, 'status' => $response->status()],
                indeterminate: self::mutates($operation),
            );
        }

        if ((int) $metadata['result'] !== 1) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                ...$context,
                'status' => $response->status(),
                // The node's own words, scrubbed. Kept because "the package
                // does not exist" and "this account already exists" need
                // completely different human responses.
                'provider_message' => $this->scrub($node, self::reasonFrom($metadata)),
                'panel_result' => 0,
            ]);
        }

        return $decoded['data'] ?? [];
    }

    /**
     * Whether this operation creates or changes something at the panel.
     *
     * Used to decide whether an unreadable answer must be treated as "may have
     * happened". An unreadable answer to a read is merely useless; an
     * unreadable answer to a createacct may be an account nobody knows about.
     */
    private static function mutates(string $operation): bool
    {
        return ! in_array($operation, ['account_usage', 'list_accounts', 'node_health', 'licence_status'], true);
    }

    /**
     * Statuses that mean "somebody in the path gave up", not "the node
     * refused".
     *
     * A 502, 503 or 504 comes from a reverse proxy in front of WHM, or from
     * cpsrvd itself under load, and says nothing about whether the request
     * reached the panel or what it did when it got there. A 4xx is the node
     * answering: it read the request and declined it.
     */
    private static function isIndeterminateStatus(int $status): bool
    {
        return in_array($status, [408, 429, 502, 503, 504], true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private static function reasonFrom(array $metadata): string
    {
        $reason = $metadata['reason'] ?? null;

        if (is_string($reason) && trim($reason) !== '') {
            return $reason;
        }

        return 'the panel rejected the request without giving a reason';
    }

    private function errorMessage(HostingNode $node, Response $response): string
    {
        $body = $response->json();

        if (is_array($body)) {
            $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];

            if (isset($metadata['reason']) && is_string($metadata['reason'])) {
                return $this->scrub($node, $metadata['reason']);
            }
        }

        // Truncated: an HTML error page from a reverse proxy in front of the
        // node is megabytes of no use in a log line.
        return $this->scrub($node, mb_substr(trim($response->body()), 0, 512));
    }

    /**
     * Remove this node's credential, then everything else that looks like one.
     *
     * The explicit replacement comes first and is not redundant: the shared
     * redactor recognises credential SHAPES, and a WHM API token is a bare
     * alphanumeric string that appears in no pattern anybody could write
     * without knowing this deployment's configuration. Only this object knows
     * the value, so only this object can guarantee it is gone.
     */
    private function scrub(HostingNode $node, string $message): string
    {
        $connection = $this->connections[(string) $node->getKey()] ?? null;

        if ($connection !== null) {
            $message = str_replace(
                [$connection->apiToken, $connection->authorizationHeader()],
                SecretRedactor::PLACEHOLDER,
                $message,
            );
        }

        return $this->redactor->redactString($message);
    }

    private function connection(HostingNode $node): WhmConnection
    {
        return $this->connections[(string) $node->getKey()] ??= WhmConnection::forNode($node);
    }

    /**
     * The first account row out of an accountsummary answer.
     *
     * @return array<string, mixed>|null
     */
    private function firstAccountRow(mixed $data): ?array
    {
        $rows = $this->asArray($this->asArray($data)['acct'] ?? []);
        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
     * WHM's sizes, in the several shapes it writes them.
     *
     * "1024", "1024M", "10G", "unlimited" and "0" all occur in the same
     * response. Unlimited and unparsable both return null rather than 0,
     * because a quota of zero means "this account may store nothing", which is
     * the exact opposite of what unlimited means and would have the platform
     * suspending accounts for exceeding a quota they do not have.
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

        if ($raw === '' || $raw === 'unlimited' || $raw === 'null') {
            return null;
        }

        if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt]?)b?$/', $raw, $matches)) {
            return null;
        }

        $number = (float) $matches[1];

        return (int) round(match ($matches[2]) {
            'k' => $number / 1024,
            'g' => $number * 1024,
            't' => $number * 1024 * 1024,
            // Bare numbers are megabytes in WHM's account summaries, which is
            // the one unit convention it is consistent about.
            default => $number,
        });
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

    private static function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
