<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Stops an account serving while preserving every byte of it.
 *
 * Suspension and termination are two different products, not two intensities
 * of the same one, and the distinction is commercial rather than technical.
 * Technically the platform could free the disk on the day an invoice goes
 * unpaid. Commercially it must not: most suspensions are billing disputes — a
 * card that expired, an invoice sent to somebody who left the company, a
 * transfer that took a week — and the overwhelming majority end with the
 * customer paying. A suspension that destroyed data would turn a late invoice
 * into a lost customer, an unrecoverable dataset and, where the customer is
 * handling other people's data, a liability the platform cannot settle with a
 * refund.
 *
 * So suspension changes one flag on the panel and one status here. Files,
 * mail, databases, DNS and the node slot all stay exactly as they were, and
 * unsuspending is a single call that puts the customer back where they were
 * with nothing lost.
 *
 * The node's account slot is deliberately NOT released. The data is still on
 * the disk; releasing the slot would let the scheduler place a new account
 * into space that is still occupied.
 */
final readonly class SuspendHostingAccount
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @throws HostingProviderException
     */
    public function execute(HostingAccount $account, string $reason): HostingAccount
    {
        if ($account->status === HostingAccountStatus::Suspended) {
            // Idempotent: a dunning run and an operator can arrive at the same
            // account in the same minute, and the second must not overwrite the
            // first one's reason or restart the retention clock. Restarting it
            // would silently extend how long the data is kept — which is safe —
            // but also how long the platform waits before it may release it,
            // which is not what anybody asked for.
            return $account;
        }

        $node = $account->node()->firstOrFail();

        // The panel first. If it refuses, the platform's record must not claim
        // an account is suspended while it is still serving: that is a
        // customer who is not paying and not stopped, and nobody looks again.
        $this->providers->for($node)->suspendAccount($node, $account->username, $reason);

        $account->forceFill([
            'status' => HostingAccountStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ])->save();

        return $account;
    }
}
