<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressInstaller;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressStagingProvider;
use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingAccountResult;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodeHealth;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressCopyRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallation;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressPushRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\SslStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Services\FakeHostingProviderGuard;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A control panel that creates nothing and reaches no network.
 *
 * Its behaviour is a pure function of the request, which is the property that
 * makes the failure paths testable: a username carrying a marker selects an
 * outcome, so a test asks for "web-provider-fail" to exercise the refusal
 * branch and "web-timeout" to exercise the one where the platform stops
 * waiting and the account may or may not exist. No fixtures, no HTTP stub and
 * no randomness.
 *
 * Three decisions are worth stating because they look like over-engineering
 * until the alternative bites:
 *
 *  - usage is derived from the username rather than stored, so a worker that
 *    never saw the create still reports the same figures. The NO_USAGE marker
 *    exists so that the one case the sync must refuse to write — a panel that
 *    answers with no numbers at all — can be reproduced deterministically;
 *
 *  - the licence answer is read from the node row rather than always being
 *    "valid". An unlicensed node is a first-class state, and a fake that
 *    always reported a valid licence would leave the entire licensing path
 *    untested;
 *
 *  - it refuses to exist in production. The fake reports accounts as created
 *    without creating them, so in production it would mark services active and
 *    send login details for hosting that is not there.
 */
final class FakeHostingProvider implements HostingProvider, WordPressInstaller, WordPressStagingProvider
{
    /** A copy whose target name carries this stops answering after the copy exists. */
    public const string COPY_TIMEOUT_MARKER = 'copy-timeout';

    public const string COPY_REFUSED_MARKER = 'copy-refused';

    /** A push over a production name carrying this stops answering halfway. */
    public const string PUSH_TIMEOUT_MARKER = 'push-timeout';

    public const string PUSH_REFUSED_MARKER = 'push-refused';

    public const string NAME = 'fake';

    /** A username carrying this is refused outright, as a node with no room would refuse it. */
    public const string PROVIDER_FAILURE_MARKER = 'provider-fail';

    /**
     * A username carrying this times out: the call fails with the outcome on
     * the node unknown, which is the state the platform must never resolve by
     * retrying. Nothing is recorded as created, deliberately — that is what
     * makes the marker useful, because the caller cannot tell and has to
     * behave correctly anyway.
     */
    public const string TIMEOUT_MARKER = 'timeout';

    /**
     * A domain carrying this installs WordPress and then fails to answer.
     *
     * The install marker that matters. An installer that goes quiet may well
     * have written a database and a wp-config, so the platform must not try
     * again over the top of it — and the only way to rehearse that is a fake
     * that really does record the installation before it throws.
     */
    public const string INSTALL_TIMEOUT_MARKER = 'wp-timeout';

    /** A domain carrying this is refused outright, with nothing written. */
    public const string INSTALL_REFUSED_MARKER = 'wp-refused';

    /** A username carrying this reports no measurements at all, as a panel mid-restart does. */
    public const string NO_USAGE_MARKER = 'no-usage';

    /**
     * WordPress installations, keyed by node then domain.
     *
     * @var array<string, array<string, WordPressInstallation>>
     */
    private array $installations = [];

    /**
     * Accounts this instance holds, keyed by node and username.
     *
     * @var array<string, array<string, RemoteAccount>>
     */
    private array $accounts = [];

    public function __construct()
    {
        // Constructed, not resolved, is the moment worth guarding: a binding
        // overridden at runtime or a node row whose panel column says "fake"
        // never passes through config, but neither can avoid this constructor.
        FakeHostingProviderGuard::assertNotProduction(self::NAME);
    }

    public function panel(): HostingPanel
    {
        return HostingPanel::Fake;
    }

    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult
    {
        $this->assertNoMarkers($node, $request->username, 'create_account');

        $key = $this->nodeKey($node);

        if (isset($this->accounts[$key][$request->username])) {
            // The panels behave this way too, and the platform depends on it:
            // a retried create that silently succeeded twice would be two
            // accounts, or one account whose password the customer was never
            // told.
            throw HostingProviderException::requestFailed(self::NAME, 'create_account', [
                'node' => $node->hostname,
                'username' => $request->username,
                'provider_message' => 'an account with this name already exists on the node',
            ]);
        }

        $this->accounts[$key][$request->username] = new RemoteAccount(
            username: $request->username,
            primaryDomain: $request->primaryDomain,
            packageName: $request->packageName,
            suspended: false,
            diskUsedMib: self::derivedDiskMib($request->username),
            ipAddress: $request->ipAddress ?? '203.0.113.10',
        );

        return new HostingAccountResult(
            username: $request->username,
            primaryDomain: $request->primaryDomain,
            packageName: $request->packageName,
            ipAddress: $request->ipAddress ?? '203.0.113.10',
            nameserver: 'ns1.'.$node->hostname,
            metadata: ['fake' => true],
        );
    }

    public function suspendAccount(HostingNode $node, string $username, string $reason): void
    {
        $account = $this->require($node, $username, 'suspend_account');

        // Everything is preserved; only the flag moves. That is what makes the
        // operation reversible, and the reason it is safe to use for a billing
        // dispute that may still end with the customer paying.
        $this->accounts[$this->nodeKey($node)][$username] = new RemoteAccount(
            username: $account->username,
            primaryDomain: $account->primaryDomain,
            packageName: $account->packageName,
            suspended: true,
            diskUsedMib: $account->diskUsedMib,
            ipAddress: $account->ipAddress,
            raw: ['suspension_reason' => $reason],
        );
    }

    public function unsuspendAccount(HostingNode $node, string $username): void
    {
        $account = $this->require($node, $username, 'unsuspend_account');

        $this->accounts[$this->nodeKey($node)][$username] = new RemoteAccount(
            username: $account->username,
            primaryDomain: $account->primaryDomain,
            packageName: $account->packageName,
            suspended: false,
            // The disk figure survives, because the data did.
            diskUsedMib: $account->diskUsedMib,
            ipAddress: $account->ipAddress,
        );
    }

    public function terminateAccount(HostingNode $node, string $username): void
    {
        $this->require($node, $username, 'terminate_account');

        unset($this->accounts[$this->nodeKey($node)][$username]);
    }

    public function changePackage(HostingNode $node, string $username, string $packageName): void
    {
        $account = $this->require($node, $username, 'change_package');

        $this->accounts[$this->nodeKey($node)][$username] = new RemoteAccount(
            username: $account->username,
            primaryDomain: $account->primaryDomain,
            packageName: $packageName,
            suspended: $account->suspended,
            diskUsedMib: $account->diskUsedMib,
            ipAddress: $account->ipAddress,
        );
    }

    public function changePassword(HostingNode $node, string $username, string $password): void
    {
        $this->require($node, $username, 'change_password');

        // Nothing is stored. The fake mirrors the real adapters here on
        // purpose: a fake that kept passwords would make it possible to write
        // a test that depends on the platform holding one.
    }

    public function accountUsage(HostingNode $node, string $username): AccountUsage
    {
        $account = $this->require($node, $username, 'account_usage');

        if (str_contains($username, self::NO_USAGE_MARKER)) {
            // A panel that answered without any numbers. Every measurement is
            // null, and the caller must leave the stored record alone rather
            // than write zeroes over a customer's real figures.
            return new AccountUsage(username: $username);
        }

        return new AccountUsage(
            username: $username,
            diskUsedMib: $account->diskUsedMib,
            diskQuotaMib: 10_240,
            bandwidthUsedMib: self::derivedBandwidthMib($username),
            bandwidthQuotaMib: 512_000,
            addonDomains: 5,
            subdomains: 25,
            databases: 10,
            emailAccounts: 50,
            suspended: $account->suspended,
            sslStatus: SslStatus::Active,
            sslExpiresAt: CarbonImmutable::now()->addDays(60),
            raw: ['fake' => true],
        );
    }

    public function listAccounts(HostingNode $node): array
    {
        /*
         * The markers are read from the node's hostname here, not from a
         * username: listing is the one call that is about the node rather than
         * about an account, and it is the call the reconciler makes. A panel
         * that is not answering has to be reproducible, because "what does the
         * sweep do when the API is down" is the question that decides whether
         * an outage gets recorded as data loss.
         */
        $this->refuseMarkedNode($node, 'list accounts on '.$node->hostname);

        return array_values($this->accounts[$this->nodeKey($node)] ?? []);
    }

    /**
     * @throws HostingProviderException
     */
    private function refuseMarkedNode(HostingNode $node, string $operation): void
    {
        if (str_contains($node->hostname, self::TIMEOUT_MARKER)) {
            throw HostingProviderException::requestFailed(
                self::NAME,
                $operation,
                ['node' => $node->slug],
                indeterminate: true,
            );
        }

        if (str_contains($node->hostname, self::PROVIDER_FAILURE_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, ['node' => $node->slug]);
        }
    }

    public function nodeHealth(HostingNode $node): NodeHealth
    {
        return new NodeHealth(
            online: $node->status->holdsAccounts(),
            loadOne: $node->load_average,
            loadFive: $node->load_average,
            loadFifteen: $node->load_average,
            diskTotalMib: $node->disk_total_mib,
            diskUsedMib: $node->disk_used_mib,
            accountCount: count($this->accounts[$this->nodeKey($node)] ?? []),
            panelVersion: $node->panel_version,
            raw: ['fake' => true],
        );
    }

    public function licenceStatus(HostingNode $node): LicenceStatus
    {
        /*
         * Read from the node row rather than always answering "valid". The
         * unlicensed path is a first-class state the platform has to handle —
         * scheduler exclusion, a permanent failure for that node, an operator
         * alert — and a fake that always reported a valid licence would leave
         * every one of those branches unexecuted by the suite.
         */
        return new LicenceStatus(
            valid: $node->panel_licensed,
            product: self::NAME,
            state: $node->licence_status ?? ($node->panel_licensed ? 'active' : 'expired'),
            expiresAt: $node->panel_licensed ? CarbonImmutable::now()->addYear() : CarbonImmutable::now()->subDay(),
            detail: $node->panel_licensed
                ? 'the fake panel reports a licence because the node row says it has one'
                : 'the node row records no valid licence',
        );
    }

    public function createSsoSession(HostingNode $node, string $username): SsoSession
    {
        $this->require($node, $username, 'create_sso_session');

        return new SsoSession(
            // Shaped like a real one so that any code which accidentally logs
            // or persists it is caught by the same redaction rules.
            url: sprintf(
                'https://%s:2083/cpsess%s/',
                $node->hostname,
                substr(hash('sha256', $node->hostname.'|'.$username), 0, 16),
            ),
            username: $username,
            service: 'fake',
            expiresAt: CarbonImmutable::now()->addMinutes(15),
        );
    }

    /**
     * Markers are checked before anything is recorded, so a refused or
     * timed-out create leaves the fake in exactly the state a real panel would
     * leave a node in — which for a timeout is: unknown, and deliberately not
     * revealed to the caller.
     */
    private function assertNoMarkers(HostingNode $node, string $username, string $operation): void
    {
        if (str_contains($username, self::TIMEOUT_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'username' => $username,
                'provider_message' => 'the fake panel stopped answering by design',
            ], indeterminate: true);
        }

        if (str_contains($username, self::PROVIDER_FAILURE_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'username' => $username,
                'provider_message' => 'the fake panel refused this account by design',
            ]);
        }
    }

    private function require(HostingNode $node, string $username, string $operation): RemoteAccount
    {
        $this->assertNoMarkers($node, $username, $operation);

        $account = $this->accounts[$this->nodeKey($node)][$username] ?? null;

        if ($account === null) {
            throw HostingProviderException::requestFailed(self::NAME, $operation, [
                'node' => $node->hostname,
                'username' => $username,
                'provider_message' => 'no such account on this node',
            ]);
        }

        return $account;
    }

    public function installWordPress(HostingNode $node, WordPressInstallRequest $request): WordPressInstallation
    {
        $this->assertNoMarkers($node, $request->username, 'install_wordpress');
        $this->require($node, $request->username, 'install_wordpress');

        $key = $this->nodeKey($node);
        $domain = strtolower($request->domain);

        if (str_contains($domain, self::INSTALL_REFUSED_MARKER)) {
            // The panel answered and said no. Nothing was written, so a caller
            // may try again once whatever it objected to is fixed.
            throw HostingProviderException::requestFailed(self::NAME, 'install_wordpress', [
                'node' => $node->hostname,
                'domain' => $domain,
                'provider_message' => 'the toolkit refused this installation',
            ]);
        }

        $installation = new WordPressInstallation(
            domain: $domain,
            siteUrl: 'https://'.$domain,
            adminUrl: 'https://'.$domain.'/wp-admin/',
            version: '6.7.1',
        );

        /*
         * Recorded before the timeout throws, and that ordering is the whole
         * value of this fake. An installer that stopped answering has often
         * finished the work, and a platform that retried would install over a
         * site the customer may already have written a post on.
         */
        $this->installations[$key][$domain] = $installation;

        if (str_contains($domain, self::INSTALL_TIMEOUT_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, 'install_wordpress', [
                'node' => $node->hostname,
                'domain' => $domain,
                'provider_message' => 'the toolkit stopped answering by design',
            ], indeterminate: true);
        }

        return $installation;
    }

    public function copyWordPress(HostingNode $node, WordPressCopyRequest $request): WordPressInstallation
    {
        // Markers only, not the in-memory account: see the note below.
        $this->assertNoMarkers($node, $request->username, 'copy_wordpress');

        $key = $this->nodeKey($node);
        $source = strtolower($request->sourceDomain);
        $target = strtolower($request->targetDomain);

        if (str_contains($target, self::COPY_REFUSED_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, 'copy_wordpress', [
                'node' => $node->hostname,
                'domain' => $target,
                'provider_message' => 'the toolkit refused this copy',
            ]);
        }

        /*
         * The source is not required to be in this process's memory: a real
         * toolkit copies whatever is at the document root, and a fake that
         * only copied what it had itself installed would refuse every copy
         * made from a second PHP process — which is every copy a browser
         * asks for. The markers, not the memory, are what make failure
         * paths reachable.
         */
        $copy = new WordPressInstallation(
            domain: $target,
            siteUrl: 'https://'.$target,
            adminUrl: 'https://'.$target.'/wp-admin/',
            version: $this->installations[$key][$source]->version ?? '6.7.1',
        );

        // Recorded before the timeout, as the install is: a toolkit that
        // stopped answering has usually finished the copy.
        $this->installations[$key][$target] = $copy;

        if (str_contains($target, self::COPY_TIMEOUT_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, 'copy_wordpress', [
                'node' => $node->hostname,
                'domain' => $target,
                'provider_message' => 'the toolkit stopped answering by design',
            ], indeterminate: true);
        }

        return $copy;
    }

    public function pushWordPressToProduction(HostingNode $node, WordPressPushRequest $request): WordPressInstallation
    {
        $this->assertNoMarkers($node, $request->username, 'push_wordpress');

        $key = $this->nodeKey($node);
        $production = strtolower($request->productionDomain);

        if (str_contains($production, self::PUSH_REFUSED_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, 'push_wordpress', [
                'node' => $node->hostname,
                'domain' => $production,
                'provider_message' => 'the toolkit refused this push',
            ]);
        }

        if (str_contains($production, self::PUSH_TIMEOUT_MARKER)) {
            throw HostingProviderException::requestFailed(self::NAME, 'push_wordpress', [
                'node' => $node->hostname,
                'domain' => $production,
                'provider_message' => 'the toolkit stopped answering halfway through the push, by design',
            ], indeterminate: true);
        }

        return $this->installations[$key][$production] ??= new WordPressInstallation(
            domain: $production,
            siteUrl: 'https://'.$production,
            adminUrl: 'https://'.$production.'/wp-admin/',
            version: '6.7.1',
        );
    }

    public function wordPressInstallation(
        HostingNode $node,
        string $username,
        string $domain,
    ): WordPressInstallation {
        $this->assertNoMarkers($node, $username, 'wordpress_installation');

        $domain = strtolower($domain);
        $found = $this->installations[$this->nodeKey($node)][$domain] ?? null;

        if ($found instanceof WordPressInstallation) {
            return $found;
        }

        /*
         * Answered, and the answer is "there is nothing here". Distinct from
         * throwing: a read that fails tells the caller nothing, and a read
         * that says `exists: false` is how reconciliation learns an install
         * the platform believes in did not happen.
         */
        return new WordPressInstallation(
            domain: $domain,
            siteUrl: 'https://'.$domain,
            adminUrl: 'https://'.$domain.'/wp-admin/',
            exists: false,
        );
    }

    private function nodeKey(HostingNode $node): string
    {
        return (string) $node->getKey();
    }

    /**
     * Deterministic, so the same account reports the same usage in every
     * process that asks.
     */
    private static function derivedDiskMib(string $username): int
    {
        return 100 + (int) (hexdec(substr(hash('sha256', $username), 0, 4)) % 4000);
    }

    private static function derivedBandwidthMib(string $username): int
    {
        return 500 + (int) (hexdec(substr(hash('sha256', 'bw'.$username), 0, 4)) % 20000);
    }
}
