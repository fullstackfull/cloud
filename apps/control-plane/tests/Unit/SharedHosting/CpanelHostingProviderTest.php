<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\WhmConnection;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adapter that talks to a real cPanel/WHM node.
 *
 * Two properties are worth more than all the rest put together and are
 * asserted here against recorded requests rather than trusted:
 *
 *  - an HTTP 200 whose envelope says metadata.result=0 is a FAILURE. This is
 *    the classic WHM integration bug: the status line says the call worked and
 *    the body says the account was not created, and an adapter that believes
 *    the status marks the service active, sends the customer login details, and
 *    bills them for hosting that does not exist;
 *
 *  - the root API token travels in the Authorization header and appears
 *    nowhere else — not in a URL, not in a body, not in an exception message,
 *    not in anything a caller would log. A token in a query string is a token
 *    in the reverse proxy's access log, and a WHM root token is root on a
 *    machine holding several hundred customers' sites, databases and mail.
 */
final class CpanelHostingProviderTest extends TestCase
{
    private const string TOKEN = 'K7QW3XJ9ZP2LMND4RTVB8HYC6FGA5SEU';

    private const string NODE_ID = '01JBQ8ZK4M3N5P7R9T1V3W5X7Y';

    #[Test]
    public function a_whm_200_with_metadata_result_zero_is_treated_as_a_failure(): void
    {
        /*
         * Exactly what WHM answers when the account already exists: HTTP 200,
         * a well-formed envelope, and result=0. Nothing was created.
         */
        Http::fake(['*' => Http::response([
            'metadata' => [
                'version' => 1,
                'command' => 'createacct',
                'result' => 0,
                'reason' => 'The account "acme" already exists.',
            ],
            'data' => [],
        ], 200)]);

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('An HTTP 200 with metadata.result=0 was treated as a successful account creation.');
        } catch (HostingProviderException $e) {
            $this->assertSame('hosting.provider_request_failed', $e->errorCode());
            $this->assertSame(0, $e->context()['panel_result']);
            $this->assertSame('The account "acme" already exists.', $e->context()['provider_message']);

            // The panel answered, so nothing is in flight. A caller may retry
            // this without any risk of creating a second account.
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function a_two_hundred_with_result_zero_fails_every_operation_not_only_create(): void
    {
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 0, 'reason' => 'no such account'],
            'data' => [],
        ], 200)]);

        $provider = $this->provider();
        $node = $this->node();

        foreach ([
            fn () => $provider->suspendAccount($node, 'acme', 'non-payment'),
            fn () => $provider->unsuspendAccount($node, 'acme'),
            fn () => $provider->terminateAccount($node, 'acme'),
            fn () => $provider->changePackage($node, 'acme', 'lyn_starter'),
            fn () => $provider->changePassword($node, 'acme', 'a-very-long-password'),
        ] as $operation) {
            try {
                $operation();

                $this->fail('A rejected WHM call was reported as a success.');
            } catch (HostingProviderException $e) {
                $this->assertSame('no such account', $e->context()['provider_message']);
            }
        }
    }

    #[Test]
    public function a_body_with_no_metadata_envelope_is_refused_rather_than_assumed_successful(): void
    {
        // A reverse proxy, or a node answering with an older format. The
        // success check cannot run, so a mutation must not be recorded as done.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('A body with no envelope was treated as a successful create.');
        } catch (HostingProviderException $e) {
            $this->assertStringContainsString('never said whether the call succeeded', $e->getMessage());
            // The request was accepted by something. The account may exist.
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function the_api_token_never_reaches_an_exception_message_or_a_log_line(): void
    {
        /*
         * A node genuinely does echo the request back in some error bodies.
         * This one is written to be as hostile as possible: the token and the
         * whole Authorization header appear in the reason the adapter is about
         * to translate.
         */
        Http::fake(['*' => Http::response([
            'metadata' => [
                'result' => 0,
                'reason' => 'access denied for request (auth: whm root:'.self::TOKEN.')',
            ],
        ], 200)]);

        $handler = new TestHandler;
        Log::swap(new Logger(new \Monolog\Logger('testing', [$handler])));

        $redactor = new SecretRedactor;

        try {
            $this->provider($redactor)->createAccount($this->node(), $this->createRequest());

            $this->fail('The adapter accepted a rejected create.');
        } catch (HostingProviderException $e) {
            // What a caller logs: the message and the redacted context, which
            // is exactly what CreateHostingAccountHandler puts in a failed
            // provisioning result.
            Log::error($e->getMessage(), $redactor->redact($e->context()));

            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(
                self::TOKEN,
                (string) json_encode($e->context()),
                'The API token survived into the exception context.',
            );
        }

        $this->assertStringNotContainsString(
            self::TOKEN,
            (string) json_encode($handler->getRecords()),
            'The API token was written to the log.',
        );

        // And it is in the header, and only in the header.
        Http::assertSent(function (Request $request): bool {
            $this->assertSame('whm root:'.self::TOKEN, $request->header('Authorization')[0] ?? '');
            $this->assertStringNotContainsString(self::TOKEN, $request->url());
            $this->assertStringNotContainsString(self::TOKEN, $request->body());

            return true;
        });
    }

    #[Test]
    public function a_successful_create_returns_the_account_name_the_panel_reports(): void
    {
        // WHM shortened the name. Every later call is addressed by name, so
        // the platform has to keep the panel's version, not its own.
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 1, 'reason' => 'Account Creation Ok'],
            'data' => ['user' => 'acmelongname12', 'ip' => '203.0.113.9', 'nameserver' => 'ns1.node.test'],
        ], 200)]);

        $result = $this->provider()->createAccount($this->node(), $this->createRequest('acmelongname1234'));

        $this->assertSame('acmelongname12', $result->username);
        $this->assertSame('203.0.113.9', $result->ipAddress);
    }

    #[Test]
    public function the_password_is_never_placed_in_a_url(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => []], 200)]);

        $this->provider()->createAccount($this->node(), $this->createRequest());

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertStringNotContainsString('s3cret-panel-password', $request->url());

            return true;
        });
    }

    #[Test]
    public function a_timeout_is_reported_as_indeterminate_and_never_as_a_plain_failure(): void
    {
        /*
         * createacct builds a home directory, a mail store, a database user and
         * a DNS zone before WHM answers. A request the platform gave up on may
         * well have been accepted, and a caller that retried it would give the
         * customer a second account.
         */
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('A timed-out create was reported as a success.');
        } catch (HostingProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
            $this->assertTrue($e->context()['indeterminate']);
        }
    }

    #[Test]
    public function a_gateway_failure_in_front_of_the_node_is_indeterminate(): void
    {
        // A 504 comes from a proxy or from cpsrvd under load and says nothing
        // about whether the request reached the panel.
        Http::fake(['*' => Http::response('gateway timeout', 504)]);

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('A 504 was treated as a success.');
        } catch (HostingProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function a_refusal_the_node_spoke_out_loud_is_not_indeterminate(): void
    {
        Http::fake(['*' => Http::response('forbidden', 403)]);

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('A 403 was treated as a success.');
        } catch (HostingProviderException $e) {
            // The node read the request and declined it. Nothing was created,
            // so a caller may retry without risking a second account.
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function an_unlimited_quota_is_read_as_unknown_rather_than_as_zero(): void
    {
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 1],
            'data' => ['acct' => [[
                'user' => 'acme',
                'diskused' => '512M',
                'disklimit' => 'unlimited',
                'suspended' => 0,
            ]]],
        ], 200)]);

        $usage = $this->provider()->accountUsage($this->node(), 'acme');

        $this->assertSame(512, $usage->diskUsedMib);
        // Zero would mean "may store nothing", which is the exact opposite of
        // unlimited and would have the platform suspending the account.
        $this->assertNull($usage->diskQuotaMib);
        $this->assertTrue($usage->hasAnyMeasurement());
    }

    #[Test]
    public function an_empty_account_summary_reports_no_measurements_rather_than_zeroes(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]], 200)]);

        $usage = $this->provider()->accountUsage($this->node(), 'acme');

        $this->assertFalse($usage->hasAnyMeasurement());
        $this->assertNull($usage->diskUsedMib);
    }

    #[Test]
    public function an_sso_session_is_created_server_side_and_carries_no_password(): void
    {
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 1],
            'data' => ['url' => 'https://node.test:2083/cpsess1234/', 'expires' => 1893456000],
        ], 200)]);

        $session = $this->provider()->createSsoSession($this->node(), 'acme');

        $this->assertSame('https://node.test:2083/cpsess1234/', $session->url);
        $this->assertStringNotContainsString('password', $session->describe());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'create_user_session')
            && str_contains($request->body(), 'cpaneld'));
    }

    #[Test]
    public function tls_verification_follows_the_node_row_and_defaults_to_on(): void
    {
        $this->assertTrue(WhmConnection::forNode($this->node())->verifyTls);

        $node = $this->node();
        $node->verify_tls = false;

        // A node with a self-signed certificate waives verification for
        // itself alone, which keeps the exception visible and local.
        $this->assertFalse(WhmConnection::forNode($node)->verifyTls);
    }

    #[Test]
    public function every_operation_calls_the_whm_function_it_is_documented_against(): void
    {
        Http::fake(['*' => Http::response([
            'metadata' => ['result' => 1],
            'data' => ['acct' => [['user' => 'acme']], 'one' => '0.10', 'url' => 'https://node.test:2083/x/'],
        ], 200)]);

        $provider = $this->provider();
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest());
        $provider->suspendAccount($node, 'acme', 'non-payment');
        $provider->unsuspendAccount($node, 'acme');
        $provider->changePackage($node, 'acme', 'lyn_starter');
        $provider->changePassword($node, 'acme', 'a-very-long-password');
        $provider->terminateAccount($node, 'acme');
        $provider->accountUsage($node, 'acme');
        $provider->listAccounts($node);
        $provider->nodeHealth($node);
        $provider->createSsoSession($node, 'acme');

        foreach ([
            'createacct', 'suspendacct', 'unsuspendacct', 'changepackage', 'passwd',
            'removeacct', 'accountsummary', 'listaccts', 'loadavg', 'create_user_session',
        ] as $function) {
            Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/json-api/'.$function));
        }

        // api.version=1 is pinned on every call: without it a node answers in
        // its default format, and the older one has no metadata envelope at
        // all — so the success check would silently stop running against
        // exactly the nodes most likely to fail.
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'api.version=1'));
    }

    #[Test]
    public function no_password_authentication_endpoint_is_ever_called(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => []], 200)]);

        $provider = $this->provider();
        $provider->createAccount($this->node(), $this->createRequest());
        $provider->suspendAccount($this->node(), 'acme', 'non-payment');

        // Ticket or session authentication would show up as a call to
        // /login or /session; its absence is the assertion.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/login')
            || str_contains($request->url(), '/session/'));
    }

    #[Test]
    public function a_node_with_no_credentials_fails_determinately_and_sends_nothing(): void
    {
        /*
         * A missing config key is not a timeout. Nothing left this process, so
         * the outcome at the node is not unknown — and flagging it
         * indeterminate would quarantine the job, hold the node's slot and send
         * an operator to reconcile a create that was never made.
         */
        Http::fake();

        $node = $this->node();
        $node->credentials_reference = 'nothing-configured-here';

        try {
            $this->provider()->createAccount($node, $this->createRequest());

            $this->fail('An unconfigured node produced a create.');
        } catch (HostingProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
            $this->assertSame('hosting.node_credentials_missing', $e->context()['error_code']);
            $this->assertStringContainsString('nothing-configured-here', (string) $e->context()['provider_message']);
        }

        Http::assertNothingSent();
    }

    private function provider(?SecretRedactor $redactor = null): CpanelHostingProvider
    {
        return new CpanelHostingProvider($redactor ?? new SecretRedactor);
    }

    private function node(): HostingNode
    {
        config(['hosting.credentials.node-a' => ['user' => 'root', 'api_token' => self::TOKEN]]);

        return (new HostingNode)->forceFill([
            'id' => self::NODE_ID,
            'slug' => 'node-a',
            'hostname' => 'node-a.lynomia.test',
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => 'https://node-a.lynomia.test:2087',
            'credentials_reference' => 'node-a',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }

    private function createRequest(string $username = 'acme'): CreateAccountRequest
    {
        return new CreateAccountRequest(
            username: $username,
            primaryDomain: 'acme.example',
            password: 's3cret-panel-password',
            packageName: 'lyn_starter',
            contactEmail: 'owner@acme.example',
        );
    }
}
