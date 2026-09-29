<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\SharedHosting\Application\Actions\ReserveHostingNodeCapacity;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingDomainConflictException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\RecordingHostingProvider;
use Tests\TestCase;

/**
 * One live hosting account per name — as a rule about NAMES, held by the
 * column rather than by whichever action happened to write it.
 *
 * Two customers who each buy hosting for `shop.example.test` would each get a
 * panel account serving it; DNS can point at one of them. Only a database
 * constraint survives a concurrent double-submit, so the rule is a partial
 * unique index over the accounts the panel still holds.
 *
 * A unique index on a raw text column is a rule about BYTES, though, and
 * `Shop.Example.Test` and `shop.example.test` are one name. So the column also
 * carries a CHECK that every stored value is already folded: "the action folds
 * it" is a property of the action, and what the index needs is a property of
 * the column.
 */
final class OneLiveHostingAccountPerDomainTest extends TestCase
{
    use RefreshDatabase;

    private const string INDEX = 'hosting_accounts_live_primary_domain_unique';

    private const string CHECK = 'hosting_accounts_primary_domain_canonical';

    #[Test]
    public function two_live_accounts_cannot_serve_one_name(): void
    {
        HostingAccount::factory()->create(['primary_domain' => 'shop.example.test']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => HostingAccount::factory()->create(['primary_domain' => 'shop.example.test']));
    }

    #[Test]
    public function every_status_the_panel_still_holds_takes_the_name_and_no_other_does(): void
    {
        foreach (HostingAccountStatus::cases() as $index => $status) {
            $name = 'status-'.$index.'.example.test';
            HostingAccount::factory()->status($status)->create(['primary_domain' => $name]);

            try {
                // A savepoint, so a refusal leaves the test's transaction usable.
                DB::transaction(fn () => HostingAccount::factory()->create(['primary_domain' => $name]));
                $taken = false;
            } catch (UniqueConstraintViolationException) {
                $taken = true;
            }

            $this->assertSame(
                $status->existsAtPanel(),
                $taken,
                sprintf('a %s account %s its name', $status->value, $taken ? 'held' : 'did not hold'),
            );
        }
    }

    #[Test]
    public function the_index_predicate_is_the_platforms_own_set_of_statuses_the_panel_holds(): void
    {
        /*
         * A partial index cannot call PHP, so the set is spelled out in SQL.
         * This holds the two in step: a status added to the enum that the
         * panel holds, and not to the predicate, is a name two customers can
         * both be sold.
         */
        $definition = (string) DB::scalar('select indexdef from pg_indexes where indexname = ?', [self::INDEX]);

        $this->assertNotSame('', $definition, 'the index does not exist');
        $this->assertStringContainsString('UNIQUE', $definition);

        preg_match_all("/'([a-z_]+)'/", $definition, $matches);
        $inPredicate = $matches[1];
        sort($inPredicate);

        $held = array_values(array_map(
            static fn (HostingAccountStatus $status): string => $status->value,
            array_filter(HostingAccountStatus::cases(), static fn (HostingAccountStatus $s): bool => $s->existsAtPanel()),
        ));
        sort($held);

        $this->assertSame($held, $inPredicate);
    }

    #[Test]
    public function the_column_refuses_a_name_that_is_not_already_folded(): void
    {
        foreach (['Shop.Example.Test', 'shop.example.test.', ' shop.example.test'] as $index => $unfolded) {
            try {
                DB::transaction(fn () => HostingAccount::factory()->create(['primary_domain' => $unfolded]));
                $this->fail(sprintf('the column accepted "%s"', $unfolded));
            } catch (QueryException $e) {
                $this->assertStringContainsString(self::CHECK, $e->getMessage(), (string) $index);
            }
        }
    }

    #[Test]
    public function the_constraint_folds_exactly_as_the_application_does(): void
    {
        /*
         * Read back out of the catalogue and EXECUTED, rather than compared as
         * text: a CHECK whose trim set had drifted from the application's
         * would still read as plausible SQL. Every input the application's
         * fold is pinned against (bar NUL, which a PostgreSQL text value
         * cannot hold) must fold to the same bytes in both places — or the
         * application writes a value its own column refuses.
         */
        $definition = (string) DB::scalar(
            "select pg_get_constraintdef(oid) from pg_constraint where conname = ? and contype = 'c'",
            [self::CHECK],
        );

        $this->assertNotSame('', $definition, 'the CHECK constraint does not exist');

        $fold = $this->rightHandSideOf($definition);

        $inputs = ['example.test', 'EXAMPLE.TEST', 'Shop.Example.Test', 'example.test.', 'example.test..',
            '.example.test', '...example.test', '  example.test  ', "\texample.test\n", "example.test\r",
            "\x0Bexample.test\x0B", ' . example.test . ', 'exa mple.test', 'example..test', '...', " \t\n", '',
            '1.00'];

        foreach ($inputs as $input) {
            $sql = str_replace('(primary_domain)::text', '(?)::text', $fold);
            $count = substr_count($sql, '?');

            $this->assertSame(
                DnsName::canonicalAsSubmitted($input),
                (string) DB::scalar('select '.$sql, array_fill(0, $count, $input)),
                sprintf('the column and the application fold %s differently', json_encode($input)),
            );
        }
    }

    #[Test]
    public function a_build_for_a_name_another_live_account_serves_is_refused_before_the_panel(): void
    {
        [$node, $panel] = $this->nodeWithARecordingPanel();

        HostingAccount::factory()->create(['primary_domain' => 'taken.example.test']);

        $result = app(CreateHostingAccountHandler::class)->execute($this->job('Taken.Example.Test.'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.domain_in_use', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);

        $this->assertSame([], $panel->creates, 'the panel was asked to build a name somebody already serves');
        $this->assertSame(0, $node->fresh()?->account_count, 'a slot was committed to a refused build');
        $this->assertSame(1, HostingAccount::query()->where('primary_domain', 'taken.example.test')->count());

        // No provider reference, so the operator's remedy — correct the name,
        // then retry — is not refused by the retry guard.
        $this->assertNull($result->providerReference);
    }

    #[Test]
    public function a_name_a_terminated_account_once_served_can_be_built_again(): void
    {
        [, $panel] = $this->nodeWithARecordingPanel();

        HostingAccount::factory()->status(HostingAccountStatus::Terminated)->create([
            'primary_domain' => 'reused.example.test',
        ]);

        $result = app(CreateHostingAccountHandler::class)->execute($this->job('reused.example.test'));

        $this->assertTrue($result->successful);
        $this->assertCount(1, $panel->creates);
    }

    #[Test]
    public function the_reservation_folds_the_name_where_it_enters_the_column(): void
    {
        /*
         * The seam where a name enters the index is the reservation, not its
         * callers: any caller that hands it a name as a person typed it gets
         * the folded name written — and refused if the folded name is taken.
         */
        [$node] = $this->nodeWithARecordingPanel();
        $customer = Customer::factory()->create();
        $package = HostingPackage::factory()->create();

        $account = app(ReserveHostingNodeCapacity::class)->execute(
            $node, 'foldedone', " Shop.Example.Test.\n", (string) $customer->getKey(), $package,
        );

        $this->assertSame('shop.example.test', $account->primary_domain);

        $this->expectException(HostingDomainConflictException::class);

        app(ReserveHostingNodeCapacity::class)->execute(
            $node, 'foldedtwo', 'SHOP.example.test', (string) Customer::factory()->create()->getKey(), $package,
        );
    }

    #[Test]
    public function a_held_row_whose_name_equals_the_jobs_only_as_a_number_is_another_name(): void
    {
        /*
         * `1.0` and `1.00` are both names the platform accepts, and PHP 8
         * calls them equal as numeric strings. The stale-name refusal has to
         * compare them as names, byte for byte, or a job renamed from one to
         * the other is built under the row's old name on a job reporting
         * success.
         */
        [$node, $panel] = $this->nodeWithARecordingPanel();
        $job = $this->job('1.0');
        $job->payload = [...$job->payload, 'username' => 'numeric'];
        $job->save();

        HostingAccount::factory()->status(HostingAccountStatus::Pending)->create([
            'hosting_node_id' => $node->getKey(),
            'customer_id' => $job->customer_id,
            'username' => 'numeric',
            'primary_domain' => '1.00',
        ]);
        $node->increment('account_count');

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.account_serves_another_domain', $result->errorCode);
        $this->assertSame([], $panel->creates, 'the panel was handed the row\'s old name');
    }

    #[Test]
    public function a_build_that_loses_the_name_to_a_build_on_another_node_is_refused_by_name(): void
    {
        /*
         * The readable check runs under ONE node's lock, and the name is
         * fleet-wide, so a build on another node can take the name after the
         * check and before this build's own write. That is the case the
         * partial unique index exists for, and its violation has to reach
         * the job as the same readable refusal — Permanent, nothing handed
         * to the panel — not as an unclassified database error.
         *
         * Made deterministic rather than raced: the other build's row is
         * written at the last moment before this build's insert, after the
         * check has passed.
         */
        [$node, $panel] = $this->nodeWithARecordingPanel();
        $this->anotherBuildTakes('raced.example.test', 'creating');

        $result = app(CreateHostingAccountHandler::class)->execute($this->job('raced.example.test'));

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.domain_in_use', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame([], $panel->creates);
        $this->assertSame(0, $node->fresh()?->account_count, 'the loser kept the slot it took');
    }

    #[Test]
    public function a_retry_that_loses_the_name_while_re_arming_its_released_row_is_refused_by_name(): void
    {
        /*
         * The same race on the other write: the job's earlier attempt left a
         * released row, and the retry re-arms it under the name — which a
         * build on another node takes between the check and the re-arm.
         */
        [$node, $panel] = $this->nodeWithARecordingPanel();
        $job = $this->job('raced-again.example.test');
        $job->payload = [...$job->payload, 'username' => 'rearmed'];
        $job->save();

        HostingAccount::factory()->status(HostingAccountStatus::Failed)->create([
            'hosting_node_id' => $node->getKey(),
            'customer_id' => $job->customer_id,
            'username' => 'rearmed',
            'primary_domain' => 'raced-again.example.test',
        ]);

        $this->anotherBuildTakes('raced-again.example.test', 'updating');

        $result = app(CreateHostingAccountHandler::class)->execute($job);

        $this->assertTrue($result->isFailure());
        $this->assertSame('hosting.domain_in_use', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame([], $panel->creates);
        $this->assertSame(0, $node->fresh()?->account_count, 'the loser kept the slot it took');
    }

    // ---- the migration that made it a rule about names --------------------

    #[Test]
    public function the_migration_refuses_two_live_accounts_that_fold_onto_one_name_and_changes_nothing(): void
    {
        $migration = $this->canonicalNameMigration();
        $migration->down();

        HostingAccount::factory()->create(['primary_domain' => 'Example.test']);
        HostingAccount::factory()->create(['primary_domain' => 'example.test']);

        $before = DB::table('hosting_accounts')->orderBy('id')->pluck('primary_domain')->all();

        try {
            $migration->up();
            $this->fail('two live accounts folding onto one name were silently merged');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('example.test', $e->getMessage());
        }

        $this->assertSame($before, DB::table('hosting_accounts')->orderBy('id')->pluck('primary_domain')->all());
        $this->assertFalse($this->checkExists(), 'the constraint was added over a refusal');
    }

    #[Test]
    public function the_migration_folds_a_legacy_spelling_where_that_is_only_a_spelling_correction(): void
    {
        $migration = $this->canonicalNameMigration();
        $migration->down();

        $live = HostingAccount::factory()->create(['primary_domain' => 'Legacy.Example.Test.']);
        // A terminated row folding onto a name a live row holds is history,
        // not a collision: the index does not cover it.
        $gone = HostingAccount::factory()->status(HostingAccountStatus::Terminated)->create([
            'primary_domain' => 'LEGACY.example.test',
        ]);

        $migration->up();

        $this->assertSame('legacy.example.test', $live->fresh()?->primary_domain);
        $this->assertSame('legacy.example.test', $gone->fresh()?->primary_domain);
        $this->assertTrue($this->checkExists());
    }

    #[Test]
    public function the_migration_goes_down_and_up_again(): void
    {
        $migration = $this->canonicalNameMigration();

        $migration->down();
        $this->assertFalse($this->checkExists());

        $migration->up();
        $this->assertTrue($this->checkExists());

        $migration->down();
        $migration->up();
        $this->assertTrue($this->checkExists());
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * The expression on the right of `primary_domain = …`, found by a
     * balanced-parenthesis scan from `lower(` rather than by a regex that
     * would stop at the first closing bracket inside it.
     */
    private function rightHandSideOf(string $definition): string
    {
        $start = strpos($definition, 'lower(');
        $this->assertNotFalse($start, 'the CHECK does not lower-case: '.$definition);

        $depth = 0;
        $inString = false;

        for ($i = $start, $length = strlen($definition); $i < $length; $i++) {
            $char = $definition[$i];

            if ($char === "'") {
                $inString = ! $inString;
            } elseif (! $inString && $char === '(') {
                $depth++;
            } elseif (! $inString && $char === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($definition, $start, $i - $start + 1);
                }
            }
        }

        $this->fail('unbalanced CHECK definition: '.$definition);
    }

    private function checkExists(): bool
    {
        return (bool) DB::scalar("select count(*) from pg_constraint where conname = ? and contype = 'c'", [self::CHECK]);
    }

    private function canonicalNameMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_04_26_000000_a_hosting_account_serves_a_canonical_name.php');

        return $migration;
    }

    /**
     * @return array{0: HostingNode, 1: RecordingHostingProvider}
     */
    private function nodeWithARecordingPanel(): array
    {
        $this->app->singleton(HostingProviderFactory::class);

        $node = HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]);

        $panel = new RecordingHostingProvider(new FakeHostingProvider);
        app(HostingProviderFactory::class)->swap($node, $panel);

        return [$node, $panel];
    }

    /**
     * A live account for this name, written by "another build" on another
     * node at the moment this build's own row is about to be written — once,
     * so the other build's own write does not trigger it again.
     *
     * @param  'creating'|'updating'  $event
     */
    private function anotherBuildTakes(string $domain, string $event): void
    {
        $otherNode = HostingNode::factory()->create();
        $done = false;

        $takeIt = static function (HostingAccount $account) use (&$done, $otherNode, $domain): void {
            if ($done || $account->primary_domain !== $domain) {
                return;
            }

            $done = true;

            HostingAccount::factory()->create([
                'hosting_node_id' => $otherNode->getKey(),
                'primary_domain' => $domain,
                'status' => HostingAccountStatus::Active,
            ]);
        };

        $event === 'creating' ? HostingAccount::creating($takeIt) : HostingAccount::updating($takeIt);
    }

    private function job(string $domain): ProvisioningJob
    {
        return ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'payload' => [
                'hosting_package_id' => (string) HostingPackage::factory()->create()->getKey(),
                'primary_domain' => $domain,
            ],
        ]);
    }
}
