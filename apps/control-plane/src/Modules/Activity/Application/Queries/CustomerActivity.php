<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Activity\Application\DTOs\ActivityItem;
use Lynomia\Modules\Activity\Application\DTOs\ActivityPage;
use Lynomia\Modules\Activity\Domain\Enums\ActivityCategory;
use Lynomia\Modules\Activity\Domain\Enums\ActorType;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * One account's history, newest first.
 *
 * The shape of the problem: eleven durable tables in nine modules each hold
 * part of what happened to an account, none of them is the authority for the
 * others, and a customer wants them in one list in time order. There were two
 * honest ways to do that — write every event a second time into a feed table,
 * or read the tables that already hold the truth — and this is the second.
 *
 * Why reading wins here:
 *
 *  - It cannot drift. A projection table is right only while every mutation
 *    path remembers to write to it; the first one that forgets produces a
 *    history with a hole in it that nothing detects.
 *  - It cannot break a business operation. There is no write in this path at
 *    all, so no backup, rebuild or renewal can fail because recording it
 *    failed.
 *  - It needs no backfill. Every event that ever happened is already in the
 *    source tables, so the feed is complete on the day it ships rather than
 *    starting from empty.
 *
 * What it costs is this class. The union is wide, and the cost is paid once
 * here rather than spread across every mutation in the platform.
 *
 * ---------------------------------------------------------------------------
 * Boundedness
 * ---------------------------------------------------------------------------
 *
 * A feed over eleven tables is the obvious place for an unbounded read, so:
 *
 *  1. Every branch carries the cursor predicate, its own `ORDER BY` and its
 *     own `LIMIT`. PostgreSQL therefore reads at most one page from each
 *     branch's index, not the branch's whole history, and the merge sees
 *     `branches × perPage` rows at worst.
 *  2. Identities and actor names are resolved for the assembled page in a
 *     fixed number of extra queries — one per resource family present, one for
 *     users — never one per row. That is the N+1 §76 gates against.
 *  3. Filtering by category chooses branches rather than filtering the union,
 *     so asking about domains reads two indexes instead of eleven.
 *
 * The query count for a page is therefore a small constant, and does not grow
 * as an account's history grows. `ActivityQueryBudgetTest` holds that.
 *
 * ---------------------------------------------------------------------------
 * Ordering
 * ---------------------------------------------------------------------------
 *
 * `(occurred_at DESC, id DESC)`, where `id` is `source:rowid` — unique across
 * branches, so the order is total. Ordering on the timestamp alone would let
 * two events written in the same millisecond swap places between pages, which
 * shows a customer a row twice and hides another entirely.
 */
final readonly class CustomerActivity
{
    /** A page a screen can render and a phone can afford. */
    public const DEFAULT_PER_PAGE = 25;

    /** Above this, a "page" is a data export with a different set of rules. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private ActivitySources $sources,
        private ActivityProjection $projection,
        private ActivityIdentities $identities,
    ) {}

    /**
     * @param  ?string  $cursor  From a previous page. An unreadable cursor is
     *                           treated as no cursor: a client that mangles it
     *                           gets the newest page rather than an error page,
     *                           and nothing is skipped silently.
     */
    public function page(
        Customer $customer,
        ?ActivityCategory $category = null,
        ?string $cursor = null,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): ActivityPage {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $customerId = (string) $customer->getKey();

        $branches = $category === null
            ? $this->sources->all($customerId)
            : $this->sources->forCategory($customerId, $category);

        $after = $this->decodeCursor($cursor);

        /*
         * One extra row, and it is never rendered: it is how the page knows
         * whether a next one exists without counting the whole history.
         */
        $wanted = $perPage + 1;

        $union = null;

        foreach ($branches as $branch) {
            $bounded = $this->bounded($branch, $after, $wanted);

            $union = $union === null ? $bounded : $union->unionAll($bounded);
        }

        if ($union === null) {
            return new ActivityPage([], null);
        }

        /** @var list<object> $rows */
        $rows = DB::query()
            ->fromSub($union, 'activity')
            ->orderByDesc('occurred_at')
            ->orderByDesc('activity_id')
            ->limit($wanted)
            ->get()
            ->all();

        $more = count($rows) > $perPage;
        $rows = array_slice($rows, 0, $perPage);

        $items = $this->identities->hydrate(array_map(
            fn (object $row): ActivityItem => $this->item($row),
            $rows,
        ));

        $last = $items === [] ? null : $items[count($items) - 1];

        return new ActivityPage(
            $items,
            $more && $last !== null ? $this->encodeCursor($last) : null,
        );
    }

    /**
     * One branch, wrapped so its computed columns can be filtered and ordered.
     *
     * The wrap is what makes the cursor cheap to express: `activity_id` is a
     * concatenation, and SQL cannot reference a select alias in the same
     * query's `WHERE`. Selecting the branch into a subquery names those
     * columns, and the row-value comparison then reads exactly like the
     * ordering it has to agree with.
     *
     * The predicate is `(occurred_at, activity_id) < (?, ?)` rather than a
     * pair of `OR`s for the same reason: written as `occurred_at < ? OR
     * (occurred_at = ? AND id < ?)` it is one missing bracket away from
     * returning the whole table, and that mistake reads as working.
     *
     * @param  ?array{occurred_at: string, id: string}  $after
     */
    private function bounded(QueryBuilder $branch, ?array $after, int $limit): QueryBuilder
    {
        $wrapped = DB::query()->fromSub($branch, 'branch');

        if ($after !== null) {
            $wrapped->whereRaw('(branch.occurred_at, branch.activity_id) < (?, ?)', [
                $after['occurred_at'],
                $after['id'],
            ]);
        }

        return $wrapped
            ->orderByDesc('branch.occurred_at')
            ->orderByDesc('branch.activity_id')
            ->limit($limit);
    }

    /**
     * One union row, translated.
     *
     * The source name is taken from the id's own prefix rather than carried in
     * a twelfth column: the id already has to be unique across branches, which
     * means it already names its branch, and a separate column could disagree
     * with it.
     */
    private function item(object $row): ActivityItem
    {
        /** @var string $id */
        $id = $row->activity_id;
        $source = str_contains($id, ':') ? substr($id, 0, (int) strpos($id, ':')) : $id;

        /** @var string $sourceKind */
        $sourceKind = (string) $row->source_kind;
        /** @var string $sourceState */
        $sourceState = (string) $row->source_state;
        /** @var string $category */
        $category = (string) $row->category;

        return new ActivityItem(
            id: $id,
            occurredAt: CarbonImmutable::parse((string) $row->occurred_at),
            category: $category === ''
                ? $this->projection->category($source, $sourceKind)
                : ActivityCategory::from($category),
            messageCode: $this->projection->messageCode($source, $sourceKind),
            state: $this->projection->state($source, $sourceState),
            actorType: ActorType::from((string) $row->actor_type),
            actorUserId: $this->nullableString($row->actor_user_id),
            resourceKind: $this->nullableString($row->resource_kind),
            resourceId: $this->nullableString($row->resource_id),
            resourceIdentity: $this->nullableString($row->resource_identity),
            reference: $this->nullableString($row->reference),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return ?array{occurred_at: string, id: string}
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        /*
         * Two fields, split on the first separator only: an activity id is
         * `source:rowid` and contains a colon of its own, so splitting on all
         * of them would cut the id in half and page from a row that does not
         * exist.
         */
        $parts = explode('|', $decoded, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($parts[0]);
        } catch (\Throwable) {
            return null;
        }

        return ['occurred_at' => $at->toIso8601String(), 'id' => $parts[1]];
    }

    private function encodeCursor(ActivityItem $last): string
    {
        return rtrim(strtr(base64_encode(
            $last->occurredAt->toIso8601String().'|'.$last->id,
        ), '+/', '-_'), '=');
    }
}
