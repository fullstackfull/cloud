<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\Queries;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Activity\Application\DTOs\ActivityItem;
use Lynomia\Modules\Activity\Domain\Enums\ActorType;
use Lynomia\Modules\Provisioning\Infrastructure\Queries\ServiceIdentities;

/**
 * The names on a page of activity, resolved a page at a time.
 *
 * Two things a feed row needs that its source table does not hold: the name
 * the customer knows the resource by, and the name of the person who asked.
 * Resolving either per row is the N+1 that makes a feed unusable at fifty rows
 * and invisible at five — which is why this class takes the whole page.
 *
 * The cost is a fixed handful of queries: one per resource family actually
 * present on the page, and one for the users actually referenced. A page of
 * twenty-five backups costs the same as a page of one.
 *
 * Service-backed rows go through `ServiceIdentities` — Wave 3's own resolver,
 * which already turns a set of service ids into `{kind, id, identity}` in one
 * query per fulfilling table. Reusing it means the feed's idea of what a
 * machine is called cannot disagree with the services index's, and it is why
 * the rows in `ActivitySources` publish a `service_id` and leave the kind null:
 * a service id is not a machine id, and only that resolver knows which table
 * fulfils which service.
 */
final readonly class ActivityIdentities
{
    public function __construct(
        private ServiceIdentities $services,
    ) {}

    /**
     * @param  list<ActivityItem>  $items
     * @return list<ActivityItem>
     */
    public function hydrate(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $handles = $this->serviceHandles($items);
        $names = $this->resourceNames($items);
        $actors = $this->actorNames($items);

        return array_map(
            function (ActivityItem $item) use ($handles, $names, $actors): ActivityItem {
                $kind = $item->resourceKind;
                $id = $item->resourceId;
                $identity = $item->resourceIdentity;

                /*
                 * A row that named a service rather than a resource. The
                 * handle decides both what it is and what it is called, so a
                 * feed row links the machine rather than the service.
                 */
                if ($kind === null && $id !== null && isset($handles[$id])) {
                    $kind = $handles[$id]['kind'];
                    $identity = $handles[$id]['identity'];
                    $id = $handles[$id]['id'];
                }

                /*
                 * A row that named its resource but not its name. The backups
                 * branch is the interesting case: it holds a service id, so
                 * the handle above has already replaced it with the machine —
                 * and the `vps` kind it declares is what stops a backup on a
                 * dedicated machine from being linked as a cloud server.
                 */
                if ($identity === null && $kind !== null && $id !== null) {
                    $identity = $names[$kind][$id] ?? null;
                }

                return new ActivityItem(
                    id: $item->id,
                    occurredAt: $item->occurredAt,
                    category: $item->category,
                    messageCode: $item->messageCode,
                    state: $item->state,
                    actorType: $item->actorType,
                    actorName: $item->actorType === ActorType::CustomerUser
                        ? ($actors[$item->actorUserId ?? ''] ?? null)
                        : null,
                    actorUserId: $item->actorUserId,
                    resourceKind: $kind,
                    resourceId: $id,
                    resourceIdentity: $identity,
                    reference: $item->reference,
                );
            },
            $items,
        );
    }

    /**
     * Machine, account, site and name handles for the service-backed rows.
     *
     * @param  list<ActivityItem>  $items
     * @return array<string, array{kind: string, id: string, identity: ?string}>
     */
    private function serviceHandles(array $items): array
    {
        $serviceIds = [];

        foreach ($items as $item) {
            // A null kind beside a non-null id is the branch saying "this is a
            // service; resolve what fulfils it".
            if ($item->resourceKind === null && $item->resourceId !== null) {
                $serviceIds[] = $item->resourceId;
            }
        }

        if ($serviceIds === []) {
            return [];
        }

        return $this->services->handlesFor(array_values(array_unique($serviceIds)));
    }

    /**
     * Names for rows that already know their resource but not its identity.
     *
     * One query per family present, and only for the families present. The
     * tables are read through the query builder for two named columns rather
     * than through models, for the same reason `ActivitySources` does: a model
     * drags a module's casts and relations behind it, and none of that is
     * wanted to print a hostname.
     *
     * @param  list<ActivityItem>  $items
     * @return array<string, array<string, string>>
     */
    private function resourceNames(array $items): array
    {
        $wanted = [];

        foreach ($items as $item) {
            if ($item->resourceIdentity === null && $item->resourceKind !== null && $item->resourceId !== null) {
                $wanted[$item->resourceKind][$item->resourceId] = true;
            }
        }

        $names = [];

        foreach ($wanted as $kind => $ids) {
            $table = match ($kind) {
                'vps' => ['virtual_machines', 'hostname'],
                'dedicated' => ['dedicated_servers', 'serial'],
                'hosting' => ['hosting_accounts', 'primary_domain'],
                'wordpress' => ['wordpress_sites', 'domain'],
                'domain' => ['domains', 'name'],
                'dns' => ['dns_zones', 'name'],
                'order' => ['orders', 'reference'],
                'invoice' => ['invoices', 'number'],
                // A kind with no name column of its own — a support request is
                // identified by the subject its own branch already published.
                default => null,
            };

            if ($table === null) {
                continue;
            }

            [$from, $column] = $table;

            /** @var array<string, string> $resolved */
            $resolved = DB::table($from)
                ->whereIn('id', array_keys($ids))
                ->pluck($column, 'id')
                ->all();

            $names[$kind] = $resolved;
        }

        return $names;
    }

    /**
     * Display names for the users named on this page.
     *
     * Keyed by user id. Only users referenced by a row on the page are read,
     * and only their name — an activity feed has no business holding an email
     * address.
     *
     * @param  list<ActivityItem>  $items
     * @return array<string, string>
     */
    private function actorNames(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->actorType === ActorType::CustomerUser && $item->actorUserId !== null) {
                $ids[] = $item->actorUserId;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = DB::table('users')
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->all();

        return $names;
    }
}
