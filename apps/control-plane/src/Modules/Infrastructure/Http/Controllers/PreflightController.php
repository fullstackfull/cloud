<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightReport;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\Infrastructure\Http\Requests\RunPreflightRequest;
use Lynomia\Modules\Infrastructure\Http\Resources\PreflightReportResource;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;

/**
 * The Control Center's door to the one preflight service.
 *
 * ---------------------------------------------------------------------------
 * There is no preflight logic here, and there is none in React either
 * ---------------------------------------------------------------------------
 *
 * This controller resolves what was asked, checks that the caller may ask it,
 * calls the service, records that it happened, and serialises the answer. Every
 * judgement — what counts as a blocker, what the next action is, whether
 * anything may claim real verification — belongs to the service, so the screen
 * and the command cannot disagree.
 *
 * The frontend never contacts a provider. It has no credential, no endpoint and
 * no reason to: a browser that could dial a customer's hypervisor would be a
 * browser holding the credential for it.
 *
 * ---------------------------------------------------------------------------
 * Two modes, two permissions
 * ---------------------------------------------------------------------------
 *
 * Simulation reads this platform's own records and rehearses against controlled
 * providers, which is the operator view's business.
 *
 * READ_ONLY_REAL sends real credentials to real endpoints. That is exactly what
 * pressing "test connection" does, so it takes exactly that permission — and it
 * still grants nothing: the service cannot write, whoever calls it.
 */
final class PreflightController
{
    public function run(
        RunPreflightRequest $request,
        InfrastructurePreflightService $preflight,
        RecordActAtomically $record,
    ): JsonResponse {
        $mode = PreflightMode::from((string) $request->validated('mode'));

        if ($mode->mayEvidenceReality() && ! $request->user()?->can(Permission::ProviderManage->value)) {
            /*
             * Refused here rather than by route middleware, because the
             * permission a run needs depends on the mode it asked for and
             * middleware cannot see the body. The route already requires the
             * operator view; this is the second, mode-dependent half.
             */
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'A read-only-real preflight sends real credentials to real endpoints, which needs the '
                        .'same permission as a connection test. A simulation preflight does not.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        try {
            $preflightRequest = new PreflightRequest(
                mode: $mode,
                scope: PreflightScope::from((string) $request->validated('scope')),
                target: $request->validated('target'),
            );
        } catch (InvalidArgumentException $wrong) {
            return response()->json([
                'error' => ['code' => 'invalid_scope', 'message' => $wrong->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /*
         * Run first, record second, and the record is not inside the run's
         * transaction — because the run has no transaction. A preflight writes
         * nothing except this audit entry, which is the one thing about it
         * worth keeping: who asked, in which mode, about what, and what came
         * back.
         *
         * A fresh run every time. There is no cache and no "recent result"
         * shortcut: showing an operator a stored success as though it had just
         * been executed is the single most misleading thing a diagnostic can
         * do.
         */
        $report = $preflight->run($preflightRequest);

        $record->execute(
            act: static fn (): PreflightReport => $report,
            describe: fn (PreflightReport $finished): AuditedAct => new AuditedAct(
                action: AuditAction::InfrastructurePreflightRun,
                subject: null,
                context: [
                    'mode' => $finished->mode->value,
                    'scope' => $finished->scope->value,
                    'target' => $finished->target,
                    'overall_status' => $finished->overallStatus()->value,
                    'checks' => count($finished->findings),
                    // The reasons, not the findings. An audit reader wants to
                    // know a run was blocked on credentials; the detail belongs
                    // to the run. Nothing here can carry a secret or an
                    // upstream response.
                    'blocker_reasons' => $finished->blockerReasons(),
                    'real_verification_claims' => $finished->realVerificationClaims(),
                ],
            ),
        );

        return response()->json([
            'data' => (new PreflightReportResource($report))->toArray($request),
        ]);
    }
}
