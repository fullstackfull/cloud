<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Provisioning\Application\Queries\CustomerOperations;
use Lynomia\Modules\Provisioning\Http\Resources\CustomerOperationResource;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * "What happened to the thing I asked for?"
 *
 * The read that AR-12 needed and the platform did not have. Every accepted
 * mutation already returned a job id; nothing could turn one back into a
 * state, so the portal had no honest way to tell a customer whether their
 * reboot had happened.
 *
 * One route, and it reads. There is no `POST /operations/{id}/retry` here and
 * that is deliberate: retrying is not a property of an operation, it is
 * re-asking the product for the same thing, with that product's own
 * idempotency key and its own guards. A generic retry would be a second path
 * to every mutation in the platform, wired to whatever the last state read
 * said — which is exactly how an indeterminate registrar operation gets asked
 * for twice.
 *
 * Scoped through `CustomerOperations`, so another tenant's operation id is a
 * 404 rather than a refusal: a 403 would confirm the id names real work.
 */
final class OperationController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * One operation's current state.
     *
     * `service.view` rather than `service.manage`: watching is not acting, and
     * a member who may look at the account's servers may see what is happening
     * to them even if they may not start it.
     */
    public function show(Request $request, string $operation): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        /** @var ProvisioningJob $found */
        $found = CustomerOperations::of($this->actingCustomer->get())
            ->whereKey($operation)
            ->firstOrFail();

        return (new CustomerOperationResource($found))->response();
    }
}
