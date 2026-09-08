<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Handlers;

use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * Moves a shared hosting account onto the package the customer now pays for.
 *
 * The other half of a plan change, and the half that was missing. Both panel
 * adapters implement `changePackage`, both are tested against a recorded HTTP
 * exchange, and nothing in the platform called either: a hosting customer
 * could upgrade, be charged the difference on the spot, and keep the old
 * disk quota, the old database limit and the old CPU cap for ever. The money
 * moved and the product did not, which is the exact failure a compute plan
 * change already has a resize job to prevent.
 *
 * ---------------------------------------------------------------------------
 * Recorded after the panel, never before
 * ---------------------------------------------------------------------------
 *
 * The account's package column is written only once the panel has accepted the
 * change. The other order produces the worst outcome available: a platform
 * that believes the customer is on the plan they paid for, a panel still
 * enforcing the old quota, and no later pass that will try again because the
 * row already reads correct.
 *
 * ---------------------------------------------------------------------------
 * Classification
 * ---------------------------------------------------------------------------
 *
 * A panel that refuses is transient. Nothing was created and nothing was
 * destroyed — the account is exactly as it was, on its old package — so the
 * engine may try again, and a panel that was down for a minute must not cost a
 * customer the upgrade they paid for.
 *
 * A panel that stops answering is a timeout, and never retried: the change may
 * have been applied, and a second attempt against an unknown state is how a
 * customer ends up on a package nobody chose. It goes to review, and the
 * operator can see both the platform's record and the panel's.
 */
final readonly class ChangeHostingPackageHandler implements ProvisioningHandler
{
    public function __construct(
        private HostingProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::ChangeHostingPackage;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $accountId = (string) ($payload['hosting_account_id'] ?? '');
        $account = $accountId === '' ? null : HostingAccount::query()->find($accountId);

        if ($account === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'hosting.unknown_account',
                'The job names a hosting account that no longer exists.',
                metadata: ['hosting_account_id' => $accountId],
            );
        }

        $packageId = (string) ($payload['hosting_package_id'] ?? '');
        $package = $packageId === '' ? null : HostingPackage::query()->find($packageId);

        if ($package === null) {
            /*
             * Permanent, like the create handler's version of this: a job
             * naming a package that does not exist will name the same
             * non-existent package on every retry.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'hosting.unknown_package',
                sprintf('No hosting package exists with the id "%s".', $packageId),
                metadata: ['hosting_package_id' => $packageId],
            );
        }

        if ((string) $account->hosting_package_id === (string) $package->getKey()) {
            // A redelivered message, or a change an operator applied by hand.
            // Answered without touching the panel.
            return ProvisioningResult::succeeded(
                metadata: [
                    'hosting_account_id' => (string) $account->getKey(),
                    'hosting_package_id' => (string) $package->getKey(),
                    'replayed' => true,
                ],
            );
        }

        $node = $account->node()->first();

        if ($node === null) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'hosting.account_has_no_node',
                'The account is not attached to a node, so there is no panel to ask.',
                metadata: ['hosting_account_id' => (string) $account->getKey()],
            );
        }

        try {
            $this->providers->for($node)->changePackage(
                $node,
                $account->username,
                $package->panel_package_name,
            );
        } catch (HostingProviderException $e) {
            if ($e->isIndeterminate()) {
                return ProvisioningResult::failed(
                    FailureClass::Timeout,
                    $e->errorCode(),
                    $e->getMessage(),
                    metadata: $this->redactor->redact([
                        ...$e->context(),
                        'hosting_account_id' => (string) $account->getKey(),
                        'hosting_package_id' => (string) $package->getKey(),
                    ]),
                );
            }

            return ProvisioningResult::failed(
                FailureClass::Transient,
                $e->errorCode(),
                $e->getMessage(),
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $account->forceFill(['hosting_package_id' => $package->getKey()])->save();

        return ProvisioningResult::succeeded(
            providerReference: $account->username,
            metadata: [
                'hosting_account_id' => (string) $account->getKey(),
                'hosting_package_id' => (string) $package->getKey(),
                'panel_package_name' => $package->panel_package_name,
            ],
        );
    }
}
