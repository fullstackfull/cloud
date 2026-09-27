<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Closure;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A DirectAdmin read with no `error` field is an answer only when it names a
 * field that command returns, and one that identifies it (F-14's read half).
 *
 * The control is one check in `DirectAdminHostingProvider::parse()`, and every
 * read goes through it. It used to be pinned by a single test on one reader
 * (`DirectAdminLicenceAnswerTest::a_read_whose_body_names_nothing_the_command_returns_is_refused`,
 * on the system-info read): removing the check turned exactly that one test
 * red. These pin it through each reader, in the direction that matters for
 * that reader, and with a positive control each, so that a check which
 * refused everything would fail here too:
 *
 *  - the account listing, where an unrelated body read without the check is
 *    an EMPTY node — every account on it missing to reconciliation;
 *  - the account configuration, where a body carrying only the fields it
 *    shares with the usage answer (`quota`, `bandwidth`) could be the usage
 *    answer, and reporting spent as allowed is how a customer at 5% gets a
 *    suspension notice;
 *  - the system information, where `version` and `hostname` alone are what
 *    almost any appliance says about itself.
 *
 * Each row is its own test with its own `Http::fake()` (see
 * DirectAdminLicenceAnswerTest for why a second fake in one test lies).
 */
final class DirectAdminReadsMustAnswerTheCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, Closure(DirectAdminHostingProvider, HostingNode): mixed}>
     */
    public static function unanswered(): iterable
    {
        $list = static fn (DirectAdminHostingProvider $p, HostingNode $n): mixed => $p->listAccounts($n);
        $usage = static fn (DirectAdminHostingProvider $p, HostingNode $n): mixed => $p->accountUsage($n, 'alice');
        $health = static fn (DirectAdminHostingProvider $p, HostingNode $n): mixed => $p->nodeHealth($n);

        yield 'a listing that names nothing a listing returns' => [['CMD_API_SHOW_USERS' => 'foo=bar'], $list];
        yield 'a listing that is some other command\'s answer' => [['CMD_API_SHOW_USERS' => 'loadavg1=0.1&version=1.665'], $list];
        yield 'an account config that names nothing a config returns' => [
            ['CMD_API_SHOW_USER_CONFIG' => 'foo=bar', 'CMD_API_SHOW_USER_USAGE' => 'quota=10&bandwidth=20'],
            $usage,
        ];
        yield 'an account config carrying only the fields it shares with usage' => [
            ['CMD_API_SHOW_USER_CONFIG' => 'quota=100&bandwidth=500', 'CMD_API_SHOW_USER_USAGE' => 'quota=10&bandwidth=20'],
            $usage,
        ];
        yield 'system information that names nothing it returns' => [['CMD_API_SYSTEM_INFO' => 'foo=bar'], $health];
        yield 'system information carrying only what any appliance says' => [['CMD_API_SYSTEM_INFO' => 'version=1.665&hostname=node-b'], $health];
    }

    /**
     * @param  array<string, string>  $bodies  command → body
     * @param  Closure(DirectAdminHostingProvider, HostingNode): mixed  $read
     */
    #[Test]
    #[DataProvider('unanswered')]
    public function a_read_that_does_not_answer_its_command_is_refused(array $bodies, Closure $read): void
    {
        $this->fakeBodies($bodies);

        try {
            $result = $read(new DirectAdminHostingProvider(new SecretRedactor), $this->node());
        } catch (HostingProviderException $refusal) {
            $this->assertStringContainsString('none of the fields this command returns', $refusal->getMessage());

            return;
        }

        $this->fail('A body that is not an answer to the command was read as one: '.json_encode($result));
    }

    #[Test]
    public function a_listing_that_names_its_accounts_is_read(): void
    {
        $this->fakeBodies(['CMD_API_SHOW_USERS' => 'list[]=alice&list[]=bob']);

        $accounts = (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node());

        $this->assertSame(['alice', 'bob'], array_map(static fn ($a): string => $a->username, $accounts));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listingsNamedAsOneValue(): iterable
    {
        yield 'one name, not as a list element' => ['list=bob'];
        yield 'two names in one value' => ['list=alice,bob'];
        yield 'one name beside a verdict' => ['error=0&list=bob'];
    }

    /**
     * `list` as a single value that says something is not the list form
     * (`list[]=…`), and what it means has not been established. It used to be
     * read as no accounts at all — an empty node, which is Critical
     * MissingAtProvider drift and an alert for every live account on it — when
     * the one thing the body plainly does is name an account.
     */
    #[Test]
    #[DataProvider('listingsNamedAsOneValue')]
    public function a_listing_that_names_accounts_as_one_value_is_refused(string $body): void
    {
        $this->fakeBodies(['CMD_API_SHOW_USERS' => $body]);

        try {
            $result = (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node());
        } catch (HostingProviderException $refusal) {
            $this->assertStringContainsString('not in the list form', $refusal->getMessage());

            return;
        }

        $this->fail('A listing that names an account as one value was read as: '.json_encode($result));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listingsWhoseElementsAreNotNames(): iterable
    {
        yield 'a list nested in the list' => ['list[][]=bob'];
        yield 'a keyed field nested in the list' => ['list[0][name]=bob'];
        yield 'a nested element beside a name' => ['list[]=alice&list[][]=bob'];
        yield 'two names joined by a comma in one element' => ['list[]=bob,alice'];
        yield 'two names joined by a newline in one element' => ['list[]=bob%0Aalice'];
        yield 'two names joined by a space in one element' => ['list[]=bob%20alice'];
        yield 'two names joined by a tab in one element' => ['list[]=bob%09alice'];
        yield 'two names joined by a carriage return in one element' => ['list[]=bob%0Dalice'];
        yield 'two names joined by a semicolon in one element' => ['list[]=alice&list[]=bob;carol'];
        yield 'two names joined by a bar in one element' => ['list[]=bob%7Calice'];
        yield 'a control character inside a name' => ['list[]=bob%00alice'];
        yield 'an invalid UTF-8 byte inside a name' => ['list[]=bob%FFalice'];
        yield 'a name that is only an invalid UTF-8 sequence' => ['list[]=%C3%28'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusualButSingleNames(): iterable
    {
        yield 'capital letters' => ['Admin', 'Admin'];
        yield 'an underscore' => ['web_1', 'web_1'];
        yield 'a leading digit' => ['1abc', '1abc'];
        yield 'a dot' => ['a.b', 'a.b'];
        yield 'a hyphen' => ['a-b', 'a-b'];
        yield 'longer than any panel limit the platform names' => [str_repeat('a', 40), str_repeat('a', 40)];
        yield 'surrounding whitespace, trimmed' => ['%20bob%0A', 'bob'];
    }

    /**
     * The other side of the refusal: what is refused is what is ambiguous — an
     * element that is not a string, or a name holding a separator, whitespace
     * or a control character, which could be two names read as one. A single
     * token is one name, however unusual, and is read as that account: if
     * the platform does not know it, reconciliation shows it as drift, which
     * an operator sees. Refusing it instead used to make the whole node's
     * listing unreadable over one oddly named account.
     */
    #[Test]
    #[DataProvider('unusualButSingleNames')]
    public function a_single_name_however_unusual_is_read_as_that_account(string $encoded, string $name): void
    {
        $this->fakeBodies(['CMD_API_SHOW_USERS' => 'list[]=alice&list[]='.$encoded]);

        $accounts = (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node());

        $this->assertSame(['alice', $name], array_map(static fn ($a): string => $a->username, $accounts));
    }

    /**
     * An element of the listing is an account only when it is one name. One
     * that is not a string used to be filtered out without a word — so
     * `list[][]=bob` was an empty node, and every live account on it missing
     * to reconciliation — and one that is two names joined by a comma or a
     * newline was read as a single account whose name no account has, so
     * both real ones were missing and a stranger was reported in their
     * place. Either way the listing is not read; it is refused, as any other
     * unreadable read is.
     */
    #[Test]
    #[DataProvider('listingsWhoseElementsAreNotNames')]
    public function a_listing_with_an_element_that_is_not_one_account_name_is_refused(string $body): void
    {
        $this->fakeBodies(['CMD_API_SHOW_USERS' => $body]);

        try {
            $result = (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node());
        } catch (HostingProviderException $refusal) {
            $this->assertStringContainsString('is not one account name', $refusal->getMessage());

            return;
        }

        $this->fail('A listing with an element that is not one account name was read as: '.json_encode($result));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyListings(): iterable
    {
        yield 'an empty value' => ['list='];
        yield 'an empty list element' => ['list[]='];
        yield 'the bare key' => ['list'];
        yield 'an empty value beside a verdict' => ['error=0&list='];
    }

    /**
     * The positive control for the refusal above: a listing that says, in any
     * of these forms, that there is nothing on the node is still an empty
     * node, not an unreadable one.
     */
    #[Test]
    #[DataProvider('emptyListings')]
    public function a_listing_that_names_nothing_in_the_list_is_an_empty_node(string $body): void
    {
        $this->fakeBodies(['CMD_API_SHOW_USERS' => $body]);

        $this->assertSame([], (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node()));
    }

    #[Test]
    public function a_bare_verdict_is_not_read_as_an_empty_node(): void
    {
        // What a real panel sends for a node with no accounts has not been
        // established. A bare `error=0` names no field of the listing, so it is
        // not read as one: an empty listing is what raises MissingAtProvider
        // drift for every live account, so the safe reading is "did not answer"
        // (ABareVerdictIsNotAnAccountListTest measures the sweep's side).
        $this->fakeBodies(['CMD_API_SHOW_USERS' => 'error=0']);

        try {
            $result = (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node());
        } catch (HostingProviderException $refusal) {
            $this->assertStringContainsString('none of the fields this command returns', $refusal->getMessage());

            return;
        }

        $this->fail('A bare verdict was read as an account listing: '.json_encode($result));
    }

    #[Test]
    public function a_config_that_identifies_itself_is_read_as_the_allowance(): void
    {
        $this->fakeBodies([
            'CMD_API_SHOW_USER_CONFIG' => 'username=alice&quota=100&bandwidth=500',
            'CMD_API_SHOW_USER_USAGE' => 'quota=10&bandwidth=20',
        ]);

        $usage = (new DirectAdminHostingProvider(new SecretRedactor))->accountUsage($this->node(), 'alice');

        $this->assertSame(100, $usage->diskQuotaMib);
        $this->assertSame(10, $usage->diskUsedMib);
    }

    #[Test]
    public function system_information_that_carries_a_load_is_read(): void
    {
        $this->fakeBodies(['CMD_API_SYSTEM_INFO' => 'loadavg1=0.25&version=1.665']);

        $health = (new DirectAdminHostingProvider(new SecretRedactor))->nodeHealth($this->node());

        $this->assertSame(0.25, $health->loadOne);
    }

    /** @param array<string, string> $bodies command → body */
    private function fakeBodies(array $bodies): void
    {
        $stubs = [];

        foreach ($bodies as $command => $body) {
            $stubs['*'.$command.'*'] = Http::response($body, 200);
        }

        Http::fake($stubs);
    }

    private function node(): HostingNode
    {
        config(['hosting.credentials.node-b' => ['username' => 'admin', 'login_key' => 'da-login-key-READS-TEST']]);

        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X82',
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
}
