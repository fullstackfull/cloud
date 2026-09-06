<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Puts a suspended account back into service.
 *
 * The other half of what makes suspension the right answer to an unpaid
 * invoice: it is undone by one call, and the customer is back exactly where
 * they were — same files, same mail, same databases, same node, same address.
 * Nothing here restores anything, because nothing was destroyed.
 *
 * The suspension reason is cleared along with the timestamp, which also stops
 * the retention clock: an account that is serving again is not waiting to be
 * released.
 */
final readonly class UnsuspendHostingAccount
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @throws HostingProviderException
     */
    public function execute(HostingAccount $account): HostingAccount
    {
        if ($account->status !== HostingAccountStatus::Suspended) {
            return $account;
        }

        $node = $account->node()->firstOrFail();

        $this->providers->for($node)->unsuspendAccount($node, $account->username);

        $account->forceFill([
            'status' => HostingAccountStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        return $account;
    }
}
