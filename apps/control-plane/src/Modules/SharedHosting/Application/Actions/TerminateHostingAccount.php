<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\AccountStillInServiceException;
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
 * The check is two questions, not one: is this account suspended at all, and
 * has its window run out. An account that is serving has no window, and before
 * F-18 that absence was read as a window that had elapsed — so the cheapest
 * path through this action destroyed live sites. See
 * assertWaitingToBeReleased() for why the status is asked first.
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
     * @param  bool  $force  Skip the guard entirely — the suspension as well as the window.
     *                       Reserved for an operator acting on an explicit request — an abuse
     *                       case, or a customer who has asked for their data to be deleted
     *                       now — who holds both service.terminate and hosting_account.manage.
     *                       Two operator routes reach this action and each checks both before
     *                       forcing: the hosting-account route in its controller, the service
     *                       route (through EndHostingService) in EndOfService::authorityOver().
     *                       Never set by an automated path.
     *
     * @throws AccountStillInServiceException
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

        if (! $force) {
            $this->assertWaitingToBeReleased($account);
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

    /**
     * The guard, in the order that makes it one.
     *
     * The status is asked before the date, and that ordering is the guard
     * rather than a detail of it. `suspended_at` is not a reliable witness on
     * its own: the create path writes Active without clearing it and a
     * re-armed row keeps it, so a serving account can carry a suspension date
     * from months ago whose "window" elapsed long since. Read first, that date
     * would let the row through. Only a Suspended account has a window that
     * means anything, so everything else — Active, Pending, and Failed too —
     * is refused here before any date is looked at.
     *
     * Failed is refused for ever rather than let through: the short-circuit
     * above matches Terminated alone, so a failed build, with nothing at the
     * panel to destroy, reaches this and stops. That fails closed, and is
     * recorded rather than repaired here. `HostingAccountStatus::isTerminal()`
     * carries the predicate that would admit it, and has no callers: an unused
     * predicate beside a hand-written equality that needs it is the shape of a
     * decision half taken, and it is somebody else's decision to finish.
     *
     * @throws AccountStillInServiceException
     * @throws RetentionPeriodActiveException
     */
    private function assertWaitingToBeReleased(HostingAccount $account): void
    {
        if ($account->status !== HostingAccountStatus::Suspended) {
            throw AccountStillInServiceException::forAccount(
                (string) $account->getKey(),
                $account->username,
                $account->status->value,
            );
        }

        if ($account->retentionHasElapsed()) {
            return;
        }

        /*
         * The refusal names the date the data may go, and it is always a real
         * timestamp: the field is a Timestamp wherever it is published. A
         * suspended row with no `suspended_at` cannot prove when its window
         * started, so it is read as starting now — a full window from the
         * moment of asking, the same reading the VPS path makes. That date
         * moves with each ask, which is the honest answer to a window with no
         * anchor; an empty string or a sentence in its place is not.
         */
        $releasesAt = $account->retentionReleasesAt() ?? CarbonImmutable::now()->addDays(
            max(0, (int) config('hosting.retention.suspended_days', 30)),
        );

        throw RetentionPeriodActiveException::forAccount(
            (string) $account->getKey(),
            $account->username,
            $releasesAt->toIso8601String(),
        );
    }
}
