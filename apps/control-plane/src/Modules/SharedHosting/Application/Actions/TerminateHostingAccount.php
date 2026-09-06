<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\RetentionPeriodActiveException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Releases an account and everything belonging to it.
 *
 * The counterpart to SuspendHostingAccount, and the reason the two are
 * separate operations rather than one with a flag. Suspension is reversible
 * and preserves everything; termination is neither. Once the panel has removed
 * the account, the customer's files, mail, databases and DNS are gone from the
 * node, and no call this platform can make brings them back.
 *
 * That is why the retention window is enforced HERE rather than left to
 * whatever triggered the termination. The window is a commercial safety net,
 * not a technical one: the platform cannot tell "cancelled" from "paying on
 * Tuesday" while a dunning cycle is still running, and terminating early turns
 * a recoverable invoice into a lost customer and an unrecoverable dataset.
 * Every automated path — dunning, cancellation, cleanup — has to pass through
 * this check, and the only way past it is an explicit, deliberate override
 * that a person has to ask for.
 *
 * The node's account slot is released only once the panel confirms the account
 * is gone. Releasing it earlier would let the scheduler place a new account
 * into disk that is still occupied.
 */
final readonly class TerminateHostingAccount
{
    public function __construct(
        private HostingProviderFactory $providers,
        private ReserveHostingNodeCapacity $capacity,
    ) {}

    /**
     * @param  bool  $force  Skip the retention window. Reserved for an operator acting on
     *                       an explicit request — an abuse case, or a customer who has asked
     *                       for their data to be deleted now. Never set by an automated path.
     *
     * @throws RetentionPeriodActiveException
     * @throws HostingProviderException
     */
    public function execute(HostingAccount $account, bool $force = false): HostingAccount
    {
        if ($account->status === HostingAccountStatus::Terminated) {
            // Idempotent: a retried cleanup job must not release the node's
            // slot a second time for one account.
            return $account;
        }

        if (! $force && ! $account->retentionHasElapsed()) {
            throw RetentionPeriodActiveException::forAccount(
                (string) $account->getKey(),
                $account->username,
                (string) $account->retentionReleasesAt()?->toIso8601String(),
            );
        }

        $node = $account->node()->firstOrFail();

        // The panel first, and only then the platform's record. A row marked
        // terminated while the account is still on the node is an account
        // nobody is billing for and nobody will ever clean up — it simply
        // occupies a shared machine forever.
        $this->providers->for($node)->terminateAccount($node, $account->username);

        /*
         * Now the slot goes back, because the disk genuinely has — and the
         * status and the decrement are written in one transaction under the
         * node's row lock because they are one fact. Written separately, a
         * process that died between them would leave the node one slot short
         * for the rest of its life: the retry would find a row already marked
         * terminated, return early, and hand nothing back.
         */
        return $this->capacity->releaseFor($account, HostingAccountStatus::Terminated, [
            'terminated_at' => now(),
        ]);
    }
}
