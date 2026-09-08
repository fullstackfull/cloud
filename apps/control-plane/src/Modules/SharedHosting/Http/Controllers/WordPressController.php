<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\SharedHosting\Application\Actions\OrderWordPressSite;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressDomainSource;
use Lynomia\Modules\SharedHosting\Http\Requests\OrderWordPressSiteRequest;
use Lynomia\Modules\SharedHosting\Http\Resources\WordPressSiteResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * A customer's WordPress sites.
 *
 * **Scoped, not checked.** Every site is reached through a `where` on the
 * acting customer, so an id from another account is a 404 rather than a 403.
 *
 * **Ordering takes `service.manage`.** It commits the account to a service and
 * to an invoice; reading the list takes `service.view`.
 */
final class WordPressController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly OrderWordPressSite $orders,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $sites = WordPressSite::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->orderBy('domain')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => WordPressSiteResource::collection($sites),
            'meta' => ['total' => $sites->count()],
        ]);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        return (new WordPressSiteResource($this->scoped($site)))->response();
    }

    public function store(OrderWordPressSiteRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $site = app(RecordActAtomically::class)->execute(
            fn (): WordPressSite => $this->orders->execute(
                $this->actingCustomer->get(),
                (string) $request->validated('domain'),
                WordPressDomainSource::from((string) $request->validated('domain_source')),
                (string) $request->validated('admin_username'),
                (string) $request->validated('admin_email'),
                (string) $request->validated('locale', 'en_US'),
            ),
            fn (WordPressSite $ordered) => new AuditedAct(
                action: AuditAction::WordPressSiteOrdered,
                subject: $ordered,
                customerId: (string) $ordered->customer_id,

                /*
                 * The name and how it is being supplied — not the
                 * administrator's address. An audit entry is read by operators
                 * for years and the site row already holds that under
                 * encryption.
                 */
                context: [
                    'domain' => $ordered->domain,
                    'domain_source' => $ordered->domain_source->value,
                ],
            ),
        );

        return (new WordPressSiteResource($site))->response()->setStatusCode(201);
    }

    private function scoped(string $id): WordPressSite
    {
        /** @var WordPressSite */
        return WordPressSite::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->findOrFail($id);
    }
}
