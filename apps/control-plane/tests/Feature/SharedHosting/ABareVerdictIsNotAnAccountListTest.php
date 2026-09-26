<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Application\Actions\ReconcileHostingNodes;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `error=0` is a verdict, not an answer.
 *
 * ===========================================================================
 * WHAT WAS WRONG (re-audit after round two, band C, unnumbered, by reading)
 * ===========================================================================
 *
 * `DirectAdminHostingProvider::parse()` checks that a READ with no `error`
 * field names a field its command is known to return. A read that DID carry
 * `error=0` skipped that check and was returned as it stood. So a body of
 * `error=0` alone, for CMD_API_SHOW_USERS, was read as a node with no
 * accounts: `listAccounts()` answered [], and `ReconcileHostingNodes` then
 * recorded Critical MissingAtProvider drift for every live account on the
 * node — an operator paged about data loss that had not happened.
 *
 * Now the identifying-field check runs whatever the error field says. A read
 * whose verdict is "no error" and whose body says nothing the command returns
 * is not an answer to it, and the sweep treats that node as one that did not
 * answer: nothing is concluded.
 *
 * What a real DirectAdmin sends for a node with no accounts at all has not
 * been established here. If it were `error=0` and nothing else, that node is
 * now read as unanswering rather than as empty — the safe direction, since
 * "empty" is what raises the alarm.
 */
final class ABareVerdictIsNotAnAccountListTest extends TestCase
{
    use RefreshDatabase;

    private const string LOGIN_KEY = 'da-login-key-for-a-bare-verdict-test';

    /**
     * @return array<string, array{string, string}>
     */
    public static function readsWithOnlyAVerdict(): array
    {
        return [
            'the account list' => ['CMD_API_SHOW_USERS', 'listAccounts'],
            'the node health' => ['CMD_API_SYSTEM_INFO', 'nodeHealth'],
        ];
    }

    #[Test]
    #[DataProvider('readsWithOnlyAVerdict')]
    public function a_read_whose_body_is_only_a_verdict_is_not_an_answer(string $command, string $method): void
    {
        Http::fake(['*/'.$command.'*' => Http::response('error=0', 200)]);

        try {
            (new DirectAdminHostingProvider(new SecretRedactor))->{$method}($this->node());

            $this->fail($command.' answered with error=0 alone was read as an answer.');
        } catch (HostingProviderException $e) {
            $this->assertStringContainsString('none of the fields this command returns', $e->getMessage());
        }
    }

    #[Test]
    public function the_sweep_raises_no_alarm_about_accounts_a_bare_verdict_did_not_list(): void
    {
        $node = $this->node();
        $node->save();

        HostingAccount::factory()->named('liveone')->create([
            'hosting_node_id' => $node->getKey(),
            'status' => HostingAccountStatus::Active,
        ]);

        Http::fake(['*/CMD_API_SHOW_USERS*' => Http::response('error=0', 200)]);

        app(ReconcileHostingNodes::class)->execute();

        $this->assertSame(
            0,
            ResourceDrift::query()->where('kind', DriftKind::MissingAtProvider->value)->count(),
            'A live account was reported missing on the strength of a body that listed nothing.',
        );
    }

    #[Test]
    public function a_list_that_names_its_accounts_is_still_read_whatever_the_verdict(): void
    {
        Http::fake(['*/CMD_API_SHOW_USERS*' => Http::response('error=0&list[]=liveone&list[]=livetwo', 200)]);

        $names = array_map(
            static fn (object $account): string => $account->username,
            (new DirectAdminHostingProvider(new SecretRedactor))->listAccounts($this->node()),
        );

        $this->assertSame(['liveone', 'livetwo'], $names);
    }

    private function node(): HostingNode
    {
        config(['hosting.credentials.node-bare' => ['username' => 'admin', 'login_key' => self::LOGIN_KEY]]);

        return HostingNode::factory()->make([
            'slug' => 'node-bare',
            'hostname' => 'node-bare.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://node-bare.lynomia.test:2222',
            'credentials_reference' => 'node-bare',
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);
    }
}
