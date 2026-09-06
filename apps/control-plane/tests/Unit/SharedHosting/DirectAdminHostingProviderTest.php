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
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminConnection;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adapter that talks to a real DirectAdmin node.
 *
 * DirectAdmin fails the two assumptions a JSON API teaches. It answers in
 * url-encoded form, so ->json() returns nothing at all; and it reports a
 * refusal as HTTP 200 with `error=1` in the body, so the status line is not
 * the verdict. An adapter that gets either wrong reports accounts as created
 * that were never created — and the platform then marks the service active,
 * sends the customer login details and bills them for it.
 *
 * The login key is asserted to stay in the Authorization header and out of
 * everything a caller would log.
 */
final class DirectAdminHostingProviderTest extends TestCase
{
    private const string LOGIN_KEY = 'da-login-key-4RTVB8HYC6FGA5SEUK7QW3XJ';

    private const string NODE_ID = '01JBQ8ZK4M3N5P7R9T1V3W5X80';

    #[Test]
    public function a_directadmin_error_one_response_is_treated_as_a_failure(): void
    {
        /*
         * Exactly what DirectAdmin answers when a create is refused: HTTP 200,
         * url-encoded, error=1. Nothing was created.
         */
        Http::fake(['*' => Http::response(
            'error=1&text=Cannot%20Create%20User&details=The%20username%20already%20exists',
            200,
        )]);

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('An HTTP 200 carrying error=1 was treated as a successful account creation.');
        } catch (HostingProviderException $e) {
            $this->assertSame('hosting.provider_request_failed', $e->errorCode());
            $this->assertSame(1, $e->context()['panel_error']);
            // Both halves of the panel's answer: the headline alone does not
            // say whether the name is taken or the package is missing.
            $this->assertSame(
                'Cannot Create User: The username already exists',
                $e->context()['provider_message'],
            );
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function error_one_fails_every_mutation_not_only_create(): void
    {
        Http::fake(['*' => Http::response('error=1&text=User%20not%20found', 200)]);

        $provider = $this->provider();
        $node = $this->node();

        foreach ([
            fn () => $provider->suspendAccount($node, 'acme', 'non-payment'),
            fn () => $provider->unsuspendAccount($node, 'acme'),
            fn () => $provider->terminateAccount($node, 'acme'),
            fn () => $provider->changePackage($node, 'acme', 'starter'),
            fn () => $provider->changePassword($node, 'acme', 'a-very-long-password'),
        ] as $operation) {
            try {
                $operation();

                $this->fail('A rejected DirectAdmin call was reported as a success.');
            } catch (HostingProviderException $e) {
                $this->assertStringContainsString('User not found', (string) $e->context()['provider_message']);
            }
        }
    }

    #[Test]
    public function a_mutation_answered_without_an_error_field_is_refused_rather_than_assumed_done(): void
    {
        // DirectAdmin always states the outcome of a mutation. A body that
        // does not is one the adapter has not understood, and an account that
        // may or may not now exist.
        Http::fake(['*' => Http::response('list[]=someone-else', 200)]);

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('A mutation with no error field was treated as a success.');
        } catch (HostingProviderException $e) {
            $this->assertStringContainsString('never said whether the call succeeded', $e->getMessage());
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function an_html_login_page_is_not_mistaken_for_a_response(): void
    {
        // A login page with a 200 is what an unauthenticated request gets.
        // parse_str would turn it into one meaningless key.
        Http::fake(['*' => Http::response('<!DOCTYPE html><html><body>login</body></html>', 200)]);

        $this->expectException(HostingProviderException::class);

        $this->provider()->createAccount($this->node(), $this->createRequest());
    }

    #[Test]
    public function the_login_key_never_reaches_an_exception_message_or_a_log_line(): void
    {
        Http::fake(['*' => Http::response(
            'error=1&text=Access%20denied&details='.rawurlencode('key '.self::LOGIN_KEY.' rejected'),
            200,
        )]);

        $handler = new TestHandler;
        Log::swap(new Logger(new \Monolog\Logger('testing', [$handler])));

        $redactor = new SecretRedactor;

        try {
            $this->provider($redactor)->createAccount($this->node(), $this->createRequest());

            $this->fail('The adapter accepted a rejected create.');
        } catch (HostingProviderException $e) {
            Log::error($e->getMessage(), $redactor->redact($e->context()));

            $this->assertStringNotContainsString(self::LOGIN_KEY, $e->getMessage());
            $this->assertStringNotContainsString(
                self::LOGIN_KEY,
                (string) json_encode($e->context()),
                'The login key survived into the exception context.',
            );
        }

        $this->assertStringNotContainsString(
            self::LOGIN_KEY,
            (string) json_encode($handler->getRecords()),
            'The login key was written to the log.',
        );

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'Basic '.base64_encode('admin:'.self::LOGIN_KEY),
                $request->header('Authorization')[0] ?? '',
            );
            $this->assertStringNotContainsString(self::LOGIN_KEY, $request->url());
            $this->assertStringNotContainsString(self::LOGIN_KEY, $request->body());

            return true;
        });
    }

    #[Test]
    public function a_successful_create_sends_the_commands_directadmin_actually_requires(): void
    {
        Http::fake(['*' => Http::response('error=0&text=User%20created', 200)]);

        $result = $this->provider()->createAccount($this->node(), $this->createRequest());

        $this->assertSame('acme', $result->username);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringContainsString('/CMD_API_ACCOUNT_USER', $request->url());
            $body = $request->body();

            // action=create alone is a no-op that still answers error=0; the
            // submit value is what makes DirectAdmin do the work.
            $this->assertStringContainsString('action=create', $body);
            $this->assertStringContainsString('add=Submit', $body);

            return true;
        });
    }

    #[Test]
    public function suspension_and_termination_use_the_select_users_command_correctly(): void
    {
        Http::fake(['*' => Http::response('error=0&text=Ok', 200)]);

        $provider = $this->provider();
        $node = $this->node();

        $provider->suspendAccount($node, 'acme', 'non-payment');
        $provider->unsuspendAccount($node, 'acme');
        $provider->terminateAccount($node, 'acme');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'CMD_API_SELECT_USERS')
            && str_contains($request->body(), 'suspend=Suspend'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'suspend=Unsuspend'));

        // Both are required: delete=yes without confirmed=Confirm returns a
        // confirmation page and deletes nothing, which an adapter checking only
        // error=0 would report as a successful termination.
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'delete=yes')
            && str_contains($request->body(), 'confirmed=Confirm'));
    }

    #[Test]
    public function usage_is_read_from_the_url_encoded_body_with_limits_and_consumption_kept_apart(): void
    {
        Http::fake([
            '*CMD_API_SHOW_USER_CONFIG*' => Http::response(
                'name=acme&package=starter&quota=10240&bandwidth=512000&suspended=no&mysql=10&nemails=50',
                200,
            ),
            '*CMD_API_SHOW_USER_USAGE*' => Http::response('quota=2048&bandwidth=91234', 200),
        ]);

        $usage = $this->provider()->accountUsage($this->node(), 'acme');

        // Reporting the limit as the consumption is how a customer at 5% of
        // quota receives a suspension notice.
        $this->assertSame(2048, $usage->diskUsedMib);
        $this->assertSame(10240, $usage->diskQuotaMib);
        $this->assertSame(91234, $usage->bandwidthUsedMib);
        $this->assertSame(512000, $usage->bandwidthQuotaMib);
        $this->assertFalse($usage->suspended);
    }

    #[Test]
    public function an_unlimited_quota_is_read_as_unknown_rather_than_as_zero(): void
    {
        Http::fake([
            '*CMD_API_SHOW_USER_CONFIG*' => Http::response('quota=unlimited&bandwidth=unlimited&suspended=no', 200),
            '*CMD_API_SHOW_USER_USAGE*' => Http::response('quota=2048&bandwidth=100', 200),
        ]);

        $usage = $this->provider()->accountUsage($this->node(), 'acme');

        $this->assertNull($usage->diskQuotaMib);
        $this->assertSame(2048, $usage->diskUsedMib);
    }

    #[Test]
    public function the_account_listing_is_parsed_from_directadmins_list_array(): void
    {
        Http::fake(['*' => Http::response('list[]=acme&list[]=globex&list[]=initech', 200)]);

        $accounts = $this->provider()->listAccounts($this->node());

        $this->assertCount(3, $accounts);
        $this->assertSame(['acme', 'globex', 'initech'], array_map(
            static fn ($account): string => $account->username,
            $accounts,
        ));
        // The listing carries no domain, and inventing one would be worse than
        // recording its absence.
        $this->assertNull($accounts[0]->primaryDomain);
    }

    #[Test]
    public function a_timeout_is_reported_as_indeterminate(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->provider()->createAccount($this->node(), $this->createRequest());

            $this->fail('A timed-out create was reported as a success.');
        } catch (HostingProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }
    }

    #[Test]
    public function an_unlicensed_node_is_reported_as_unlicensed_rather_than_as_an_outage(): void
    {
        // DirectAdmin refuses its API when the licence has lapsed. The two
        // need different responses: one is fixed by waiting, the other by
        // renewing.
        Http::fake(['*' => Http::response('error=1&text=Your%20license%20has%20expired', 200)]);

        $status = $this->provider()->licenceStatus($this->node());

        $this->assertFalse($status->valid);
        $this->assertSame('invalid', $status->state);
    }

    #[Test]
    public function tls_verification_follows_the_node_row_and_defaults_to_on(): void
    {
        $this->assertTrue(DirectAdminConnection::forNode($this->node())->verifyTls);

        $node = $this->node();
        $node->verify_tls = false;

        $this->assertFalse(DirectAdminConnection::forNode($node)->verifyTls);
    }

    #[Test]
    public function a_node_with_no_credentials_fails_determinately_and_sends_nothing(): void
    {
        // Nothing left this process, so the outcome at the node is not
        // unknown. Indeterminate would quarantine the job over a config key.
        Http::fake();

        $node = $this->node();
        $node->credentials_reference = 'nothing-configured-here';

        try {
            $this->provider()->createAccount($node, $this->createRequest());

            $this->fail('An unconfigured node produced a create.');
        } catch (HostingProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
            $this->assertSame('hosting.node_credentials_missing', $e->context()['error_code']);
        }

        Http::assertNothingSent();
    }

    private function provider(?SecretRedactor $redactor = null): DirectAdminHostingProvider
    {
        return new DirectAdminHostingProvider($redactor ?? new SecretRedactor);
    }

    private function node(): HostingNode
    {
        config(['hosting.credentials.node-b' => ['username' => 'admin', 'login_key' => self::LOGIN_KEY]]);

        return (new HostingNode)->forceFill([
            'id' => self::NODE_ID,
            'slug' => 'node-b',
            'hostname' => 'node-b.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://node-b.lynomia.test:2222',
            'credentials_reference' => 'node-b',
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
            packageName: 'starter',
            contactEmail: 'owner@acme.example',
        );
    }
}
