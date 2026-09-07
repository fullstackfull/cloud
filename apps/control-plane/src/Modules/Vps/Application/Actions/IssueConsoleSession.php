<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Actions;

use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\Exceptions\ConsoleSessionUnavailableException;
use Lynomia\Modules\Vps\Domain\Exceptions\VpsNotProvisionedException;
use Lynomia\Modules\Vps\Domain\Services\VpsOperationGuard;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * Mint one short-lived, single-use permit to open a console.
 *
 * Note what this action does NOT do: it does not call the hypervisor.
 *
 * That is the whole design, not an omission. Proxmox issues a console ticket
 * from `vncproxy`, and that ticket is a bearer credential for a root console
 * on the node. Fetching it here would mean handing it to whoever called the
 * API, and a credential that reaches a browser has reached the browser's
 * history, every extension that can read the page, and whatever the customer
 * pastes into a support chat when the console does not work. It would also
 * start the hypervisor's own expiry clock at the moment of the API call rather
 * than the moment the socket opens, so a customer on a slow connection would
 * get a ticket that had already expired.
 *
 * So the platform issues its own opaque secret and keeps the hypervisor out of
 * it. The console gateway redeems that secret — see
 * {@see RedeemConsoleSession} — and only then, server-side and on its own
 * connection, obtains whatever the hypervisor wants. The browser never holds a
 * credential that is valid anywhere except against this platform, for one
 * machine, once, for a minute.
 *
 * The guard is assertReachable() rather than assertOperable(): a console is
 * exactly what a customer needs when the guest will not boot, so a stopped
 * machine still gets one. A machine the hypervisor has never confirmed does
 * not, because there is nothing to attach to.
 */
final readonly class IssueConsoleSession
{
    public function __construct(
        private ConsoleSessionStore $sessions,
        private VpsOperationGuard $guard,
    ) {}

    public function execute(VirtualMachine $machine, Customer $customer, ?string $userId = null): ConsoleSession
    {
        /*
         * The service first, and it is not the same question as the guest's
         * power state. A console is deliberately available to a machine that
         * will not boot — that is what a console is for — but a suspended
         * service is one the platform cut off on purpose, for non-payment or
         * for abuse, and a terminated one is a service somebody stopped paying
         * for. A console is root access, so it must not be the one door that
         * stays open after POST /power has closed: this refusal carries the
         * same code as the power one because it is the same fact.
         */
        $this->guard->assertServiceActive($machine);

        try {
            $this->guard->assertReachable($machine);
        } catch (VpsNotProvisionedException) {
            throw ConsoleSessionUnavailableException::notRunnable();
        }

        return $this->sessions->issue(
            virtualMachineId: (string) $machine->getKey(),
            customerId: (string) $customer->getKey(),
            userId: $userId,
            id: (string) Str::ulid(),
            /*
             * 32 bytes from the CSPRNG, base64url-encoded. Not a ULID, not a
             * uuid4, not Str::random(): the first two are structured and
             * partly predictable, and the third draws from a generator whose
             * seeding is a framework detail. This is the only thing standing
             * between a guessed URL and somebody's root console.
             */
            token: rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
        );
    }
}
