<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Provisioning\Application\Queries\CustomerServices;
use Lynomia\Modules\Provisioning\Http\Requests\ListServiceEventsRequest;
use Lynomia\Modules\Provisioning\Http\Resources\ProvisioningEventResource;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * What the platform has done to one service, as its owner may read it.
 *
 * The history is the provisioning jobs, one event per unit of work: the build,
 * the reboot that was asked for at 3am, the suspension for non-payment. It is
 * the answer to "what happened to my server", and it is the endpoint most
 * likely to leak, because the rows behind it are the ones that carry provider
 * responses. ProvisioningEventResource is where that is decided; this class's
 * job is to make sure nothing but the acting customer's own jobs reach it.
 *
 * The scoping is transitive and deliberate. The service is fetched through the
 * acting customer first, and the jobs are read through `$service->jobs()` — so
 * a job is never queried by id, and there is no path here on which an
 * unscoped provisioning job exists. A job id in the URL would be that path,
 * which is why the route names a service and not a job.
 *
 * The attempt log hanging off each job is not published. Every row of it holds
 * a provider's error message and a provider's response metadata; it is the
 * record support reads to explain a failure, not the record the customer reads
 * to know there was one.
 */
final class ServiceEventController
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
     * One service's provisioning history, newest first.
     *
     * A service that has never been worked on returns an empty page rather
     * than a 404: "there is no history" is a fact about the service, and a
     * missing-resource answer would make a client think the service itself was
     * gone.
     */
    public function index(ListServiceEventsRequest $request, string $service): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        /** @var Service $found */
        $found = CustomerServices::of($this->actingCustomer->get())
            ->whereKey($service)
            ->firstOrFail();

        /** @var LengthAwarePaginator<int, ProvisioningJob> $events */
        $events = $found->jobs()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => ProvisioningEventResource::collection($events->getCollection()),
            'meta' => [
                'service_id' => $found->getKey(),
                'page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
                'max_per_page' => ListServiceEventsRequest::MAX_PER_PAGE,
            ],
        ]);
    }
}
