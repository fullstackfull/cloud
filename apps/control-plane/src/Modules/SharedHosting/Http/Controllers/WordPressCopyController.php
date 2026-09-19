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
use Lynomia\Modules\SharedHosting\Application\Actions\CopyWordPressSite;
use Lynomia\Modules\SharedHosting\Application\Actions\PushWordPressToProduction;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;
use Lynomia\Modules\SharedHosting\Http\Requests\CloneWordPressSiteRequest;
use Lynomia\Modules\SharedHosting\Http\Requests\PushWordPressToProductionRequest;
use Lynomia\Modules\SharedHosting\Http\Resources\WordPressSiteOperationResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;

/**
 * Copies of a site and the push back. Every write is `service.manage`;
 * the push additionally needs the production domain typed back, checked
 * by the action. Answers 202: the toolkit is doing the work, and the row
 * says where it has got to.
 */
final class WordPressCopyController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly CopyWordPressSite $copies,
        private readonly PushWordPressToProduction $pushes,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    public function staging(Request $request, string $site): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($site);
        $userId = $request->user()?->getAuthIdentifier();

        $operation = app(RecordActAtomically::class)->execute(
            fn (): WordPressSiteOperation => $this->copies->staging($found, $userId === null ? null : (string) $userId),
            static fn (WordPressSiteOperation $op): AuditedAct => new AuditedAct(
                action: AuditAction::WordPressStagingRequested,
                subject: $op,
                customerId: (string) $op->customer_id,
                context: ['domain' => $found->domain, 'target' => $op->target()->value('domain')],
            ),
        );

        return (new WordPressSiteOperationResource($operation))->response()->setStatusCode(202);
    }

    public function clone(CloneWordPressSiteRequest $request, string $site): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($site);
        $userId = $request->user()?->getAuthIdentifier();

        $operation = app(RecordActAtomically::class)->execute(
            fn (): WordPressSiteOperation => $this->copies->clone($found, $request->domain(), $userId === null ? null : (string) $userId),
            static fn (WordPressSiteOperation $op): AuditedAct => new AuditedAct(
                action: AuditAction::WordPressCloneRequested,
                subject: $op,
                customerId: (string) $op->customer_id,
                context: ['domain' => $found->domain, 'target' => $op->target()->value('domain')],
            ),
        );

        return (new WordPressSiteOperationResource($operation))->response()->setStatusCode(202);
    }

    /**
     * What a push would overwrite, before the customer is asked to type
     * anything. The same words the confirmation shows.
     */
    public function pushImpact(Request $request, string $site): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $scope = WordPressPushScope::tryFrom((string) $request->query('scope', WordPressPushScope::Both->value)) ?? WordPressPushScope::Both;

        return response()->json(['data' => $this->pushes->impact($this->scoped($site), $scope)]);
    }

    public function push(PushWordPressToProductionRequest $request, string $site): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.manage');

        $found = $this->scoped($site);
        $userId = $request->user()?->getAuthIdentifier();

        $operation = app(RecordActAtomically::class)->execute(
            fn (): WordPressSiteOperation => $this->pushes->execute($found, $request->scope(), $request->confirmation(), $userId === null ? null : (string) $userId),
            static fn (WordPressSiteOperation $op): AuditedAct => new AuditedAct(
                action: AuditAction::WordPressPushRequested,
                subject: $op,
                customerId: (string) $op->customer_id,
                context: ['staging' => $found->domain, 'production' => $op->target()->value('domain'), 'scope' => $op->scope?->value, 'impact' => $op->impact],
            ),
        );

        return (new WordPressSiteOperationResource($operation))->response()->setStatusCode(202);
    }

    public function operations(Request $request, string $site): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'service.view');

        $found = $this->scoped($site);

        $rows = WordPressSiteOperation::query()
            ->where(fn ($query) => $query->where('wordpress_site_id', $found->getKey())->orWhere('target_site_id', $found->getKey()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['data' => WordPressSiteOperationResource::collection($rows)]);
    }

    private function scoped(string $id): WordPressSite
    {
        /** @var WordPressSite $site */
        $site = WordPressSite::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->findOrFail($id);

        return $site;
    }
}
