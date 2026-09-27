<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\ListedAccountName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A cPanel listing is read by the rule the DirectAdmin one is (re-audit after
 * round six, band C, unnumbered).
 *
 * `CpanelHostingProvider::listAccounts()` read `data.acct` through `?? []` and
 * skipped every row it could not use. A body with no `data`, a `data` with no
 * `acct`, `acct` as one object, rows keyed by something other than `user`,
 * rows that were bare strings — each was read as a node with no accounts; a
 * row whose `user` held two names joined was read as one account nobody has.
 * The sweep then reported every live account on the node missing: Critical
 * drift and an alert, from a body that named nobody the adapter could read.
 *
 * Now a body that is not a list of rows under `data.acct`, or holds a row that
 * does not name one account ({@see ListedAccountName}),
 * is refused whole. An empty list is a listing, and is read as a node with no
 * accounts. What counts as one name is the rule the DirectAdmin adapter reads
 * by, and the last two tests read the same values through both adapters.
 */
final class ACpanelListingThatNamesNobodyReadableIsRefusedTest extends TestCase
{
    private const string TOKEN = 'K7QW3XJ9ZP2LMND4RTVB8HYC6FGA5SEU';

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'no data at all' => [['metadata' => ['result' => 1]]];
        yield 'data without acct' => [['metadata' => ['result' => 1], 'data' => ['foo' => 'bar']]];
        yield 'data as an empty list' => [['metadata' => ['result' => 1], 'data' => []]];
        yield 'acct as null' => [['metadata' => ['result' => 1], 'data' => ['acct' => null]]];
        yield 'acct as a string' => [['metadata' => ['result' => 1], 'data' => ['acct' => 'liveone']]];
        yield 'acct as one object' => [['metadata' => ['result' => 1], 'data' => ['acct' => ['user' => 'liveone']]]];
        yield 'acct as rows keyed by name' => [['metadata' => ['result' => 1], 'data' => ['acct' => ['liveone' => ['user' => 'liveone']]]]];
        yield 'rows keyed by name, not user' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['name' => 'liveone']]]]];
        yield 'rows as bare strings' => [['metadata' => ['result' => 1], 'data' => ['acct' => ['liveone']]]];
        yield 'a bare string beside a named row' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'liveone'], 'livetwo']]]];
        yield 'a row whose user is null' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => null]]]]];
        yield 'a row whose user is empty' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => ' ']]]]];
        yield 'a row whose user is a list' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => ['liveone']]]]]];
        yield 'a row whose user holds two names' => [['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'liveone,livetwo']]]]];
    }

    #[Test]
    #[DataProvider('unreadableBodies')]
    public function a_body_that_is_not_a_list_of_named_accounts_is_refused(array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);

        try {
            $result = (new CpanelHostingProvider(new SecretRedactor))->listAccounts($this->cpanelNode());
        } catch (HostingProviderException $refusal) {
            $this->assertStringContainsString('cannot be read as the accounts on the node', $refusal->getMessage());

            return;
        }

        $this->fail('An unreadable cPanel listing was read as: '.json_encode(array_map(
            static fn (RemoteAccount $account): string => $account->username,
            $result,
        )));
    }

    #[Test]
    public function an_empty_list_is_a_node_with_no_accounts(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]], 200)]);

        $this->assertSame([], (new CpanelHostingProvider(new SecretRedactor))->listAccounts($this->cpanelNode()));
    }

    /**
     * Values neither adapter may read as one account: each is how two names
     * could be run together. (Bytes that are not UTF-8 are refused by the
     * same rule, but a JSON body cannot carry them to the cPanel adapter;
     * the DirectAdmin tests hold that case.)
     *
     * @return iterable<string, array{string}>
     */
    public static function notOneName(): iterable
    {
        yield 'a comma' => ['bob,alice'];
        yield 'a newline' => ["bob\nalice"];
        yield 'a space' => ['bob alice'];
        yield 'a tab' => ["bob\talice"];
        yield 'a carriage return' => ["bob\ralice"];
        yield 'a semicolon' => ['bob;carol'];
        yield 'a bar' => ['bob|alice'];
        yield 'a control character' => ["bob\x00alice"];
    }

    #[Test]
    #[DataProvider('notOneName')]
    public function both_adapters_refuse_the_same_value_as_not_one_name(string $value): void
    {
        Http::fake([
            'node-cp.*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => $value]]]], 200),
            'node-da.*' => Http::response('list[]='.rawurlencode($value), 200),
        ]);

        foreach ($this->readers() as $panel => $read) {
            try {
                $read();
                $this->fail('The '.$panel.' adapter read '.json_encode($value).' as one account.');
            } catch (HostingProviderException $refusal) {
                $this->assertStringContainsString('cannot be read as the accounts on the node', $refusal->getMessage(), $panel);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusualButOneName(): iterable
    {
        yield 'capital letters' => ['Admin'];
        yield 'an underscore' => ['web_1'];
        yield 'a leading digit' => ['1abc'];
        yield 'a dot' => ['a.b'];
        yield 'a hyphen' => ['a-b'];
        yield 'longer than any column this platform once had' => [str_repeat('a', 300)];
    }

    #[Test]
    #[DataProvider('unusualButOneName')]
    public function both_adapters_read_the_same_unusual_value_as_that_account(string $value): void
    {
        Http::fake([
            'node-cp.*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => $value]]]], 200),
            'node-da.*' => Http::response('list[]='.rawurlencode($value), 200),
        ]);

        foreach ($this->readers() as $panel => $read) {
            $this->assertSame([$value], array_map(
                static fn (RemoteAccount $account): string => $account->username,
                $read(),
            ), $panel);
        }
    }

    /**
     * @return array<string, callable(): list<RemoteAccount>>
     */
    private function readers(): array
    {
        return [
            'cPanel' => fn (): array => (new CpanelHostingProvider(new SecretRedactor))->listAccounts($this->cpanelNode()),
            'DirectAdmin' => fn (): array => (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->directAdminNode()),
        ];
    }

    private function cpanelNode(): HostingNode
    {
        config(['hosting.credentials.node-cp' => ['user' => 'root', 'api_token' => self::TOKEN]]);

        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X7Y',
            'slug' => 'node-cp',
            'hostname' => 'node-cp.lynomia.test',
            'panel' => HostingPanel::Cpanel,
            'api_endpoint' => 'https://node-cp.lynomia.test:2087',
            'credentials_reference' => 'node-cp',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }

    private function directAdminNode(): HostingNode
    {
        config(['hosting.credentials.node-da' => ['username' => 'admin', 'login_key' => 'da-login-key-SHARED-RULE-TEST']]);

        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X7Z',
            'slug' => 'node-da',
            'hostname' => 'node-da.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://node-da.lynomia.test:2222',
            'credentials_reference' => 'node-da',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }
}
