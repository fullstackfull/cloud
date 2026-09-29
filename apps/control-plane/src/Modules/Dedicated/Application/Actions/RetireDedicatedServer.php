<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DecommissionRefusedException;
use Lynomia\Modules\Dedicated\Domain\StateMachines\DedicatedServerStateMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;

/**
 * A machine leaves the fleet for good, because a person says it is going.
 *
 * The other way out of {@see DecommissionDedicatedServer}'s second step. Not
 * every machine that comes back from a customer goes back on the shelf: a
 * board fails, a chassis is end of life, the disks are shredded rather than
 * wiped. Without this, such a machine had nowhere to go but `maintenance` or
 * `failed` for ever — and the addresses it held with it, because their
 * quarantine clock only starts when somebody says the machine is empty. That
 * is F-12's leak arriving by a second road.
 *
 * So retiring does two things under one row lock, and the second is the one
 * that matters to the rest of the platform:
 *
 *  - the machine goes to `retired`, which is terminal. The row is kept —
 *    "where did this serial number go" is answered from it — and nothing
 *    leaves the state, so a retired machine cannot be put back on the shelf
 *    and sold.
 *  - the quarantine clock starts on the addresses it was the last to hold.
 *    A machine on its way to disposal is not answering on them any more.
 *
 * Like returning to stock, this is somebody's word about the world rather
 * than anything the platform can check, so the caller has to give evidence,
 * and it lands in the audit trail beside their name.
 *
 * It refuses a machine that is still assigned. A server with a customer on it
 * has not been decommissioned, whatever its status column says; retiring it
 * would take a running machine out from under a paying service.
 *
 * **What happens when two operators act at once.** Both doors take the same
 * row lock, so they are decided one at a time, and the order decides the
 * answer:
 *
 *  - two operators retiring one machine at once both pass, because
 *    `AbstractStateMachine::canTransition()` treats a no-op transition as
 *    legal (each writes its own audit row);
 *  - shelve then retire passes: a machine on the shelf may still be sent for
 *    disposal;
 *  - retire then shelve is refused with 409 `state.illegal_transition`.
 *
 * `retired` having no outward edges is the only thing standing between a
 * machine sent for disposal and the shelf. That is measured, not assumed:
 * giving `retired` an edge to `available` makes returning it to stock succeed.
 * `DecommissioningGivesTheAddressBackTest::a_scrapped_machine_is_not_put_back_on_the_shelf_by_the_other_order`
 * pins both orders through the endpoints.
 */
final readonly class RetireDedicatedServer
{
    public function __construct(
        private DedicatedServerStateMachine $states,
        private IpAllocator $addresses,
    ) {}

    /**
     * @throws DecommissionRefusedException
     */
    public function execute(DedicatedServer $server): DedicatedServer
    {
        return DB::transaction(function () use ($server): DedicatedServer {
            /** @var DedicatedServer $locked */
            $locked = DedicatedServer::query()->lockForUpdate()->findOrFail($server->getKey());

            if ($locked->service_id !== null || $locked->customer_id !== null) {
                throw DecommissionRefusedException::becauseItIsStillSomebodys((string) $locked->getKey());
            }

            $this->states->assertCanTransition($locked->status, DedicatedServerStatus::Retired);

            $locked->forceFill(['status' => DedicatedServerStatus::Retired])->save();

            $this->addresses->startHeldQuarantines($locked);

            return $locked;
        });
    }
}
