<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\AnnouncesProgress;
use Illuminate\Database\Seeder;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Infrastructure\Application\Reference\LoadReferenceTopologyForSimulation;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use RuntimeException;

/**
 * Put the reference estate into a development database.
 *
 * ---------------------------------------------------------------------------
 * This seeder no longer describes an estate
 * ---------------------------------------------------------------------------
 *
 * It used to, in eight methods with the facts inline, and it was the de facto
 * reference topology for the whole project — the only place that said how many
 * nodes a plausible cluster has, which documentation range the addresses come
 * from, and that the panel is never cPanel. That made it authoritative by
 * accident, and a seeder is a bad place for something authoritative: it cannot
 * be read by a test, validated by CI, shown to somebody onboarding a real
 * estate, or checked for secrets.
 *
 * The estate is now declared once, in resources/reference-topology/topology.php,
 * and this seeder is one of its consumers. So are the tests, the graph
 * integrity gate and the documentation. That is the difference between a
 * canonical source and several files that happen to agree today.
 *
 * Everything the old seeder was careful about is still true, and is now
 * enforced rather than commented: the cluster driver is `fake` and the panel is
 * `fake`, every address is in an RFC documentation range, every hostname is
 * under `.example`, there is no credential anywhere, and the validator refuses
 * the file if any of that stops holding.
 */
final class InfrastructureSeeder extends Seeder
{
    use AnnouncesProgress;

    public function __construct(
        private readonly LoadReferenceTopologyForSimulation $reference,
    ) {}

    public function run(): void
    {
        // The loader refuses production on its own. This refusal stays because
        // a seeder that can be invoked by hand is worth stopping at its own
        // front door, and because the message an operator sees should name the
        // seeder they ran.
        if (app()->isProduction()) {
            throw new RuntimeException(
                'InfrastructureSeeder must never run in production: real inventory is declared through the Control Center and the private inventory, not seeded.'
            );
        }

        $written = $this->reference->execute();

        $this->announce(sprintf(
            'Reference topology loaded: %d objects across %d kinds — %d compute nodes, %d allocatable addresses, %d hosting nodes, %d dedicated chassis.',
            array_sum($written),
            count(array_filter($written)),
            ComputeNode::count(),
            // Allocatable, not total: the network, gateway and broadcast
            // addresses are rows too, and counting them would overstate what
            // the pool can actually hand out.
            IpAddress::query()->where('status', IpAddressStatus::Available)->count(),
            HostingNode::count(),
            DedicatedServer::count(),
        ));

        $this->announce('That estate is a MODEL. Nothing in it exists, nothing in it is reachable, and none of it may be deployed.');
    }
}
