<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The domain question Gap 5 wrote down and did not answer: may two hosting
 * nodes share a hostname?
 *
 * The answer is "not while both are serving", and the two halves are equally
 * load-bearing.
 *
 * Two serving nodes under one name are two panels the platform believes are
 * different machines and one name that resolves to one of them. The scheduler
 * places an account on node A, the adapter talks to whichever answers, and the
 * account ends up on a machine the platform's records put somewhere else —
 * which reconciliation then reports as an orphan on one node and a missing
 * account on the other.
 *
 * And a retired node must be allowed to keep the name its replacement now
 * uses, because a chassis swap where the new machine takes the old one's name
 * is ordinary operations. Forcing a rename first would destroy the record of
 * which machine the accounts used to be on.
 *
 * Enforced by a partial unique index rather than by a check in the action,
 * because two operators onboarding the same replacement in the same minute are
 * two connections, and an `exists()` on each of them both answers "no".
 */
final class OneHostnamePerServingNodeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every status that still holds accounts, which is the set the index
     * covers.
     *
     * @return iterable<string, array{0: HostingNodeStatus}>
     */
    public static function servingStatuses(): iterable
    {
        foreach (HostingNodeStatus::cases() as $status) {
            if (! $status->holdsAccounts()) {
                continue;
            }

            yield $status->value => [$status];
        }
    }

    #[Test]
    #[DataProvider('servingStatuses')]
    public function two_serving_nodes_cannot_share_a_hostname(HostingNodeStatus $second): void
    {
        $datacenter = Datacenter::factory()->create();

        HostingNode::factory()->create([
            'datacenter_id' => $datacenter->getKey(),
            'hostname' => 'srv-01.hosting.example',
            'status' => HostingNodeStatus::Active,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        HostingNode::factory()->create([
            'datacenter_id' => $datacenter->getKey(),
            'hostname' => 'srv-01.hosting.example',
            'status' => $second,
        ]);
    }

    #[Test]
    public function a_retired_node_may_keep_the_name_its_replacement_now_uses(): void
    {
        // The positive twin, and the reason the index is partial: a chassis
        // swap where the new machine takes the old name must not require
        // renaming the row that carries the history.
        $datacenter = Datacenter::factory()->create();

        $retired = HostingNode::factory()->create([
            'datacenter_id' => $datacenter->getKey(),
            'hostname' => 'srv-02.hosting.example',
            'status' => HostingNodeStatus::Offline,
        ]);

        $replacement = HostingNode::factory()->create([
            'datacenter_id' => $datacenter->getKey(),
            'hostname' => 'srv-02.hosting.example',
            'status' => HostingNodeStatus::Active,
        ]);

        $this->assertNotSame($retired->getKey(), $replacement->getKey());
        $this->assertSame(
            2,
            HostingNode::query()->where('hostname', 'srv-02.hosting.example')->count(),
        );
    }

    #[Test]
    public function retiring_a_node_frees_its_hostname_and_taking_it_back_is_refused(): void
    {
        /*
         * The transition, which is how the swap actually happens: the old node
         * is marked offline, the new one is recorded under the same name, and
         * the old one cannot be brought back into service without dealing with
         * the collision.
         */
        $datacenter = Datacenter::factory()->create();

        $original = HostingNode::factory()->create([
            'datacenter_id' => $datacenter->getKey(),
            'hostname' => 'srv-03.hosting.example',
            'status' => HostingNodeStatus::Active,
        ]);

        $original->forceFill(['status' => HostingNodeStatus::Offline])->save();

        HostingNode::factory()->create([
            'datacenter_id' => $datacenter->getKey(),
            'hostname' => 'srv-03.hosting.example',
            'status' => HostingNodeStatus::Active,
        ]);

        $this->expectException(QueryException::class);

        $original->forceFill(['status' => HostingNodeStatus::Active])->save();
    }

    #[Test]
    public function the_index_covers_exactly_the_statuses_the_domain_calls_serving(): void
    {
        /*
         * A partial index cannot call PHP, so its predicate is spelled out in
         * SQL and this is what holds the two in step. A status added later
         * that holds accounts and is missing from the index would be a node
         * that could quietly take a serving node's name.
         */
        /** @var list<object{indexdef: string}> $rows */
        $rows = DB::select(
            "select indexdef from pg_indexes where indexname = 'hosting_nodes_serving_hostname_unique'",
        );

        $this->assertCount(1, $rows, 'the partial unique index on hosting node hostnames is missing');

        $definition = $rows[0]->indexdef;

        foreach (HostingNodeStatus::cases() as $status) {
            $status->holdsAccounts()
                ? $this->assertStringContainsString(
                    "'".$status->value."'",
                    $definition,
                    sprintf('%s holds accounts and the hostname index does not cover it.', $status->value),
                )
                : $this->assertStringNotContainsString(
                    "'".$status->value."'",
                    $definition,
                    sprintf('%s holds no accounts and must not be covered by the hostname index.', $status->value),
                );
        }
    }
}
