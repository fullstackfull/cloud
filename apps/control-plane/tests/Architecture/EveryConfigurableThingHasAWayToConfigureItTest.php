<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Configuration is data, and data needs a way in.
 *
 * ---------------------------------------------------------------------------
 * The failure this exists to prevent coming back
 * ---------------------------------------------------------------------------
 *
 * The platform could read an estate in detail and could not be given one. Four
 * of these objects had a list endpoint and no writer; three writers existed
 * and each began by finding a parent that had no writer of its own; two had
 * nothing at all. A fresh deployment could therefore only be configured with a
 * SQL client, an edited seeder, or the reference topology — which is a model
 * of an estate and says so in its own banner.
 *
 * None of that was visible from any single file. It was visible only by
 * following the chain, which is what this test does.
 *
 * ---------------------------------------------------------------------------
 * Why a registry and not a count
 * ---------------------------------------------------------------------------
 *
 * The obvious version of this test — "the routes file has N POSTs under
 * /infrastructure" — would pass while pointing at the wrong things and would
 * fail every time somebody added a legitimate endpoint. So the subject is the
 * list of real domain objects an operator has to be able to create, named
 * here, each with the route that creates it. Adding an inventory model that
 * an operator must configure means adding a line here, which is the decision
 * being forced: either it has a supported write path or somebody says out loud
 * that it does not.
 *
 * The parent column is the other half. A writer whose parent has no writer is
 * exactly what was wrong, and it is invisible unless the chain is asserted.
 */
final class EveryConfigurableThingHasAWayToConfigureItTest extends TestCase
{
    /**
     * Object => [route name that creates it, the class whose row it needs
     * first, or null for the root of the chain].
     *
     * @var array<class-string, array{0: string, 1: class-string|null}>
     */
    private const array INVENTORY = [
        Region::class => ['api.admin.infrastructure.regions.store', null],
        Datacenter::class => ['api.admin.infrastructure.datacenters.store', Region::class],
        Rack::class => ['api.admin.infrastructure.racks.store', Datacenter::class],
        ComputeCluster::class => ['api.admin.infrastructure.clusters.store', Datacenter::class],
        VmTemplate::class => ['api.admin.infrastructure.templates.store', ComputeCluster::class],
        Network::class => ['api.admin.infrastructure.networks.store', Datacenter::class],
        IpPool::class => ['api.admin.infrastructure.ip_pools.store', Datacenter::class],
        Subnet::class => ['api.admin.infrastructure.subnets.store', IpPool::class],
        HostingNode::class => ['api.admin.infrastructure.hosting_nodes.store', Datacenter::class],
        DedicatedServer::class => ['api.admin.infrastructure.dedicated.store', Datacenter::class],
        BmcEndpoint::class => ['api.admin.infrastructure.dedicated.bmc.store', DedicatedServer::class],
    ];

    #[Test]
    public function every_inventory_object_an_operator_must_create_has_a_route_that_creates_it(): void
    {
        $missing = [];

        foreach (self::INVENTORY as $model => [$routeName, $_parent]) {
            $route = Route::getRoutes()->getByName($routeName);

            if (! $route instanceof RoutingRoute) {
                $missing[] = sprintf('%s has no writer (%s)', class_basename($model), $routeName);

                continue;
            }

            if (! in_array('POST', $route->methods(), true)) {
                $missing[] = sprintf('%s: %s is not a POST', class_basename($model), $routeName);
            }
        }

        $this->assertSame([], $missing, sprintf(
            "An operator cannot configure these without a SQL client:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    #[Test]
    public function the_whole_parent_chain_is_creatable_and_not_just_the_leaves(): void
    {
        /*
         * The original defect in one assertion. Every writer above named a
         * parent; the question is whether that parent is itself in the list
         * with a route of its own, all the way up to the one object that has
         * no parent.
         */
        $unreachable = [];

        foreach (array_keys(self::INVENTORY) as $model) {
            $seen = [];
            $current = $model;

            while ($current !== null) {
                if (isset($seen[$current])) {
                    $unreachable[] = class_basename($model).': its parent chain loops';

                    break;
                }

                $seen[$current] = true;

                if (! isset(self::INVENTORY[$current])) {
                    $unreachable[] = sprintf(
                        '%s needs a %s and nothing here creates one',
                        class_basename($model),
                        class_basename($current),
                    );

                    break;
                }

                [$routeName, $parent] = self::INVENTORY[$current];

                if (! Route::getRoutes()->getByName($routeName) instanceof RoutingRoute) {
                    $unreachable[] = sprintf(
                        '%s needs a %s, whose writer does not exist',
                        class_basename($model),
                        class_basename($current),
                    );

                    break;
                }

                $current = $parent;
            }
        }

        $this->assertSame([], $unreachable, sprintf(
            "These can only be created if something else is created first, and it cannot be:\n  %s",
            implode("\n  ", $unreachable),
        ));
    }

    #[Test]
    public function no_inventory_writer_is_reachable_without_a_permission(): void
    {
        $unguarded = [];

        foreach (self::INVENTORY as $model => [$routeName, $_parent]) {
            $route = Route::getRoutes()->getByName($routeName);

            if (! $route instanceof RoutingRoute) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $guarded = array_filter(
                $middleware,
                static fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'permission:'),
            );

            if ($guarded === []) {
                $unguarded[] = class_basename($model).' ('.$routeName.')';
            }

            if (! in_array('auth:sanctum', $middleware, true)) {
                $unguarded[] = class_basename($model).' is not behind authentication';
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "Inventory these could be written without a named permission:\n  %s",
            implode("\n  ", $unguarded),
        ));
    }

    #[Test]
    public function privileged_role_management_has_a_reachable_protected_route(): void
    {
        /*
         * `role.manage` was in the permission catalogue and referenced by
         * nothing — a power nobody could exercise, and eleven permissions
         * nobody could be granted. A deployment could have exactly as many
         * operators as it was born with.
         *
         * Asserted against the route table rather than against a file, and it
         * checks the guard as well as the existence: a role-management route
         * that anybody could reach would be worse than none.
         */
        $roleRoutes = array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => in_array(
                'permission:'.Permission::RoleManage->value,
                $route->gatherMiddleware(),
                true,
            ),
        ));

        $this->assertNotEmpty(
            $roleRoutes,
            sprintf('%s is declared and no route checks it.', Permission::RoleManage->value),
        );

        $verbs = [];

        foreach ($roleRoutes as $route) {
            $this->assertContains(
                'auth:sanctum',
                $route->gatherMiddleware(),
                $route->uri().' manages roles without requiring a login.',
            );

            $verbs = [...$verbs, ...array_diff($route->methods(), ['HEAD'])];
        }

        // Reading who is privileged is not enough: the defect was that nobody
        // could *change* it.
        $this->assertNotEmpty(
            array_intersect(['POST', 'PUT', 'PATCH'], $verbs),
            'Roles can be read and not changed, which is the dead end this closed.',
        );
    }
}
