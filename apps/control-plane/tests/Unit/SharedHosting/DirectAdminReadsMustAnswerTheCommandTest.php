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
