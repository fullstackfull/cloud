<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\Provisioning\Application\Actions\EndAnUnbuiltService;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ABuildMayExistException;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\AccountStillInServiceException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingServiceAlreadyEndedException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\RetentionPeriodActiveException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Ending a shared-hosting service: the account at the panel, then the service.
 *
 * TerminateHostingAccount ends an account and says nothing about the service
 * that was bought, which is right for the hosting-account route — an operator
 * clearing out an account — and was wrong for everything that ends a
 * *service*. The retention sweep ended the account and left the service
 * `suspended` beside it for ever (seen on F-18's branch), and the service
 * route did not reach hosting at all: it sent every service down the VPS path,
 * which refused a hosting service for having no virtual machine.
 *
 * So this is the service-shaped half, and the only thing it adds to the
 * account action is the ending. Every guard on the account is
 * TerminateHostingAccount's own — F-18's, status before date: a live account is
 * refused whoever asks unless forced, a suspended one inside its window is
 * refused unless forced — and none of them is re-implemented here, so the two
 * roads to an account cannot disagree about when it may be destroyed.
 *
 * ---------------------------------------------------------------------------
 * What this does NOT inherit
 * ---------------------------------------------------------------------------
 *
 * The hosting-account route's controller gate. F-18 put a second permission
 * check in HostingController, and a caller reaching the account through here
 * has not passed it. The service route asks EndOfService::authorityOver()
 * instead, which demands `service.terminate` and `hosting_account.manage` for
 * a hosting service whatever `force` says — never less than the hosting route
 * asks for the same account, which EndingAHostingAccountThroughEitherDoorTest
 * drives cell by cell. The sweep never forces, so it gets only what the action
 * allows unforced: an account suspended past its window.
 *
 * ---------------------------------------------------------------------------
 * An account the panel never had
 * ---------------------------------------------------------------------------
 *
 * No account row, or a row whose build failed or never finished, is a service
 * with nothing at the panel to delete — if and only if its build history says
 * so. EndAnUnbuiltService reads that history, ends the service without
 * calling the panel, and refuses (`provisioning.build_may_exist`) when a build
 * may have left an account behind. The row, where there is one, is left as it
 * is: it records a build that failed, which is true.
 */
final readonly class EndHostingService
{
    public function __construct(
        private TerminateHostingAccount $terminate,
        private EndAnUnbuiltService $unbuilt,
        private TransitionService $transitionService,
        private ServiceStateMachine $serviceStates,
    ) {}

    /**
     * @param  bool  $force  Passed to TerminateHostingAccount unchanged: skip the suspension and
     *                       window guard. The caller decides who may send it.
     * @return HostingAccount|null the account terminated at the panel, or null when the panel
     *                             never had one and the service ended without asking it
     *
     * @throws HostingServiceAlreadyEndedException
     * @throws AccountStillInServiceException
     * @throws RetentionPeriodActiveException
     * @throws HostingProviderException
     * @throws ABuildMayExistException
     * @throws IllegalStateTransitionException
     */
    public function execute(Service $service, bool $force = false): ?HostingAccount
    {
        if ($service->status === ServiceStatus::Terminated) {
            throw HostingServiceAlreadyEndedException::forService((string) $service->getKey());
        }

        $account = HostingAccount::query()->where('service_id', $service->getKey())->first();

        if ($account === null || in_array($account->status, [HostingAccountStatus::Pending, HostingAccountStatus::Failed], true)) {
            $this->unbuilt->execute($service);

            return null;
        }

        /*
         * Asked before the panel is. Destroying the account and then finding
         * the service cannot reach `terminated` would leave a deleted site
         * behind a service row that still claims it, and no audit entry for
         * the deletion, because the caller records the ending only once this
         * returns.
         */
        $this->serviceStates->assertCanTransition($service->status, ServiceStatus::Terminated);

        $terminated = $this->terminate->execute($account, force: $force);

        $this->transitionService->execute($service, ServiceStatus::Terminated);

        return $terminated;
    }
}
