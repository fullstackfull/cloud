<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightReport;

/**
 * A preflight report, as the Control Center receives it.
 *
 * ---------------------------------------------------------------------------
 * Every field written out, on purpose
 * ---------------------------------------------------------------------------
 *
 * An earlier version of this class delegated to the report's own `toArray`,
 * which was shorter and wrong for one specific reason: the drift gate in the
 * API suite reads the published field names out of the resource class itself,
 * so a resource that forwards to another object publishes a shape nothing can
 * check. The gate caught it, which is the gate working — a response whose
 * fields nobody compares against the API description is a response that
 * quietly stops matching it.
 *
 * So the keys are literal here and the schema in `resources/openapi` names the
 * same ones. Adding a field to the report without adding it here does not
 * change the API; adding it here without documenting it fails the build.
 *
 * ---------------------------------------------------------------------------
 * What cannot be in it
 * ---------------------------------------------------------------------------
 *
 * There is no model to serialise — a report is a value assembled in memory and
 * never stored — so there is nothing here that could accidentally carry a
 * column. And nothing upstream of it carries a secret: every summary is a
 * sentence written by the check that produced it, the credential checks report
 * only PRESENT, MISSING, REVOKED or ENVIRONMENT_MISMATCH, and no raw provider
 * response or exception message reaches a finding at all.
 *
 * @mixin PreflightReport
 */
final class PreflightReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PreflightReport $report */
        $report = $this->resource;

        return [
            'mode' => $report->mode->value,
            // The word that goes at the top of every screen. A simulation
            // result that does not say SIMULATION is one somebody quotes.
            'mode_label' => $report->mode->label(),
            /*
             * What the checks ran against, beside how they ran. A complete
             * green against a model of an estate says nothing about an estate,
             * and the screen needs both words to say so.
             */
            'reference_topology' => $report->referenceTopology,
            'topology_label' => $report->topologyLabel(),
            'scope' => $report->scope->value,
            'target' => $report->target,
            'started_at' => $report->startedAt->toIso8601String(),
            'finished_at' => $report->finishedAt->toIso8601String(),
            'duration_ms' => $report->durationMs(),
            'overall_status' => $report->overallStatus()->value,
            'passed' => $report->passed(),
            'counts' => $report->counts(),
            'checks' => self::findings($report->findings),
            'blockers' => self::findings($report->blockers()),
            'warnings' => self::findings($report->warnings()),
            'blocker_reasons' => $report->blockerReasons(),
            'verification_levels' => $report->verificationLevels(),
            /*
             * By check id, and there is deliberately no field saying the estate
             * is verified — no such fact exists. A read that succeeded against
             * a real provider has earned exactly that read.
             */
            'real_verification_claims' => $report->realVerificationClaims(),
            'next_actions' => $report->nextActions(),
        ];
    }

    /**
     * @param  list<PreflightFinding>  $findings
     * @return list<array<string, mixed>>
     */
    private static function findings(array $findings): array
    {
        return array_map(static fn (PreflightFinding $finding): array => $finding->toArray(), $findings);
    }
}
