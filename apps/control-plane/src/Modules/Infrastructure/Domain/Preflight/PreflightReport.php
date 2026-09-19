<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * Everything one preflight run established.
 *
 * ===========================================================================
 * THE AGGREGATION RULE, AND WHY IT IS HERE AND NOT IN THE SERVICE
 * ===========================================================================
 *
 * One blocking finding makes the whole report blocking. There is no
 * percentage, no "mostly ready", and no severity weighting that lets eleven
 * passes outvote one missing credential — because the operator's question is
 * "can I use this", and the answer to that is no.
 *
 * It lives on this object rather than in the orchestrator because the
 * orchestrator is where somebody would be tempted to special-case it: skip a
 * check that is failing noisily, treat a warning as a pass to get a screen
 * green. Here it is a pure function of the findings, and the deliberate
 * breakage in the test suite proves that forcing a green past a blocking
 * finding fails a gate.
 *
 * ===========================================================================
 * REAL CLAIMS ARE A LIST, NOT A FLAG
 * ===========================================================================
 *
 * {@see self::realVerificationClaims()} returns the individual checks that
 * earned REAL_INFRA_VERIFIED, by id. That shape is deliberate: there is no
 * field on this report that says the estate is verified, because no such fact
 * exists. A cluster answering an authenticated read has earned exactly that
 * much, and the report says exactly that much.
 *
 * ===========================================================================
 * WHICH TOPOLOGY WAS LOOKED AT
 * ===========================================================================
 *
 * {@see $referenceTopology} is true when the run saw rows out of the reference
 * topology, and it is carried separately from the mode because the two say
 * different things. SIMULATION says how the checks were run; REFERENCE
 * TOPOLOGY says what they were run against. A complete green against a model
 * of an estate is a fact about this codebase and about nothing else, and a
 * report that showed only the first word would let somebody read it as a fact
 * about an estate.
 */
final readonly class PreflightReport
{
    /**
     * @param  list<PreflightFinding>  $findings
     */
    public function __construct(
        public PreflightMode $mode,
        public PreflightScope $scope,
        public ?string $target,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $finishedAt,
        public array $findings,
        public bool $referenceTopology = false,
    ) {}

    /**
     * What the run looked at, in the words that go beside the mode.
     *
     * A sentence rather than a flag at the call sites, so that neither the
     * command nor the screen has to decide how to phrase it and they cannot
     * phrase it differently.
     */
    public function topologyLabel(): string
    {
        return $this->referenceTopology ? 'REFERENCE TOPOLOGY' : 'CONFIGURED INFRASTRUCTURE';
    }

    /**
     * Blocking, or not. Nothing in between.
     */
    public function passed(): bool
    {
        return $this->blockers() === [];
    }

    public function overallStatus(): CheckStatus
    {
        foreach ($this->findings as $finding) {
            if ($finding->status === CheckStatus::Blocked) {
                return CheckStatus::Blocked;
            }
        }

        foreach ($this->findings as $finding) {
            if ($finding->status === CheckStatus::Fail) {
                return CheckStatus::Fail;
            }
        }

        /*
         * A run in which nothing was established is not a pass.
         *
         * It is reachable — a scope whose every check was skipped for want of
         * a prerequisite that is itself not applicable — and reporting PASS
         * for it would be the emptiest possible green.
         */
        if ($this->countOf(CheckStatus::Pass) === 0) {
            return CheckStatus::NotTested;
        }

        return $this->countOf(CheckStatus::Warning) > 0 ? CheckStatus::Warning : CheckStatus::Pass;
    }

    /** @return list<PreflightFinding> */
    public function blockers(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (PreflightFinding $finding): bool => $finding->status->blocking(),
        ));
    }

    /** @return list<PreflightFinding> */
    public function warnings(): array
    {
        return $this->of(CheckStatus::Warning);
    }

    /** @return list<PreflightFinding> */
    public function observations(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (PreflightFinding $finding): bool => $finding->status === CheckStatus::Pass
                || $finding->status === CheckStatus::NotApplicable
                || $finding->status === CheckStatus::NotTested,
        ));
    }

    /**
     * The blocker reasons present, deduplicated.
     *
     * The audit entry records these rather than the findings: an operator
     * looking at the audit trail wants to know that a run was blocked on
     * credentials, and the detail belongs to the run.
     *
     * @return list<string>
     */
    public function blockerReasons(): array
    {
        $reasons = [];

        foreach ($this->blockers() as $finding) {
            if ($finding->blocker instanceof BlockerReason) {
                $reasons[$finding->blocker->value] = true;
            }
        }

        return array_keys($reasons);
    }

    /**
     * The verification levels this run supports.
     *
     * A simulation run can support the first three and never the fourth, and
     * the mode is asked rather than the findings trusted — belt and braces,
     * because a check with the wrong evidence class is a bug and this is where
     * it would otherwise escape.
     *
     * @return list<string>
     */
    public function verificationLevels(): array
    {
        $levels = [VerificationLevel::CodeComplete->value, VerificationLevel::Tested->value];

        if ($this->countOf(CheckStatus::Pass) > 0) {
            $levels[] = VerificationLevel::RuntimeVerified->value;
        }

        if ($this->mode->mayEvidenceReality() && $this->realVerificationClaims() !== []) {
            $levels[] = VerificationLevel::RealInfraVerified->value;
        }

        return $levels;
    }

    /**
     * Exactly which reads a real provider answered, by check id.
     *
     * Empty in simulation mode, always, whatever the findings say — the mode
     * is checked here as well as at the point each finding is built, because
     * these two controls fail differently and this is the one that is read out
     * loud.
     *
     * @return list<string>
     */
    public function realVerificationClaims(): array
    {
        if (! $this->mode->mayEvidenceReality()) {
            return [];
        }

        return array_values(array_map(
            static fn (PreflightFinding $finding): string => $finding->id,
            array_filter($this->findings, static fn (PreflightFinding $finding): bool => $finding->evidencesReality()),
        ));
    }

    /**
     * What to go and do, in the order the dependency graph found them.
     *
     * Deduplicated by action text rather than by check, because a missing
     * credential reference shows up as the root cause of several checks and
     * the operator only has to do the one thing.
     *
     * @return list<string>
     */
    public function nextActions(): array
    {
        $actions = [];

        foreach ($this->blockers() as $finding) {
            if ($finding->nextAction !== null && $finding->nextAction !== '') {
                $actions[$finding->nextAction] = true;
            }
        }

        return array_keys($actions);
    }

    /**
     * How many checks landed in each status.
     *
     * On the report rather than built at each caller, so the CLI's JSON, the
     * Admin response and the human summary are counting the same thing — and
     * so the resource has one literal key instead of seven, which keeps the
     * API drift gate able to see it.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'total' => count($this->findings),
            'pass' => $this->countOf(CheckStatus::Pass),
            'fail' => $this->countOf(CheckStatus::Fail),
            'blocked' => $this->countOf(CheckStatus::Blocked),
            'warning' => $this->countOf(CheckStatus::Warning),
            'not_applicable' => $this->countOf(CheckStatus::NotApplicable),
            'not_tested' => $this->countOf(CheckStatus::NotTested),
        ];
    }

    public function countOf(CheckStatus $status): int
    {
        return count($this->of($status));
    }

    /** @return list<PreflightFinding> */
    public function of(CheckStatus $status): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (PreflightFinding $finding): bool => $finding->status === $status,
        ));
    }

    public function durationMs(): int
    {
        return (int) $this->startedAt->diffInMilliseconds($this->finishedAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'reference_topology' => $this->referenceTopology,
            'topology_label' => $this->topologyLabel(),
            'mode_label' => $this->mode->label(),
            'scope' => $this->scope->value,
            'target' => $this->target,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => $this->finishedAt->toIso8601String(),
            'duration_ms' => $this->durationMs(),
            'overall_status' => $this->overallStatus()->value,
            'passed' => $this->passed(),
            'counts' => $this->counts(),
            'checks' => array_map(static fn (PreflightFinding $f): array => $f->toArray(), $this->findings),
            'blockers' => array_map(static fn (PreflightFinding $f): array => $f->toArray(), $this->blockers()),
            'warnings' => array_map(static fn (PreflightFinding $f): array => $f->toArray(), $this->warnings()),
            'blocker_reasons' => $this->blockerReasons(),
            'verification_levels' => $this->verificationLevels(),
            'real_verification_claims' => $this->realVerificationClaims(),
            'next_actions' => $this->nextActions(),
        ];
    }
}
