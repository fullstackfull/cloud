<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * What one check found, and what to do about it.
 *
 * ===========================================================================
 * THE FIELD THAT MAKES THIS WORTH HAVING
 * ===========================================================================
 *
 * `nextAction`. A preflight that says "configuration invalid" has moved the
 * operator's problem from "something is wrong" to "something is wrong
 * somewhere in the configuration", which is not progress. Every blocking
 * finding in this platform carries the thing to go and do, and where a
 * blocker reason is involved the platform already knows it —
 * {@see BlockerReason::nextAction()} has said so since 30B-P, and this reuses
 * it rather than writing a second set of words that will drift.
 *
 * `evidence` is the safe, human sentence. It never carries a secret and never
 * carries an upstream response body: the identity testers established that
 * discipline in Gap 2, and a preflight report is persisted, rendered on a
 * screen and carried into an audit entry, so it has to hold the same line.
 */
final readonly class PreflightFinding
{
    /**
     * @param  string  $id  Stable, machine-readable: `provider.identity`, `mapping.storage`.
     *                      Automation matches on this, so it does not change with wording.
     * @param  string  $target  What was checked, named the way an operator named it.
     * @param  int  $durationMs  How long the check took. Worth keeping: a real-mode check
     *                           that takes eight seconds is a finding of its own.
     */
    public function __construct(
        public string $id,
        public CheckCategory $category,
        public CheckStatus $status,
        public string $target,
        public string $summary,
        public EvidenceClass $evidence,
        public ?BlockerReason $blocker = null,
        public ?string $nextAction = null,
        public int $durationMs = 0,
        public ?VerificationLevel $verified = null,
    ) {}

    public static function pass(
        string $id,
        CheckCategory $category,
        string $target,
        string $summary,
        EvidenceClass $evidence,
        ?VerificationLevel $verified = null,
    ): self {
        return new self($id, $category, CheckStatus::Pass, $target, $summary, $evidence, verified: $verified);
    }

    /**
     * Ours to fix: a mapping, a reference, a configuration.
     *
     * A next action is required, not optional, and the constructor signature
     * is what enforces it. "Fix configuration" is not an action; "Map a
     * storage pool to cluster pve-1" is.
     */
    public static function fail(
        string $id,
        CheckCategory $category,
        string $target,
        string $summary,
        string $nextAction,
        EvidenceClass $evidence = EvidenceClass::Configuration,
    ): self {
        return new self($id, $category, CheckStatus::Fail, $target, $summary, $evidence, nextAction: $nextAction);
    }

    /**
     * Waiting on something outside this platform.
     *
     * The blocker reason is required, and the five it can be are the only five
     * this project reports: credentials, hardware, network, licence, or not
     * implemented. There is deliberately no blocker for "the provider is the
     * problem" — that phrasing hides which of the five it actually is.
     *
     * The next action is required too, and it is a **sentence**. It used to
     * default to {@see BlockerReason::nextAction()}, which was wrong in a way
     * that only showed up when the command was run rather than tested: that
     * method returns a translation *key* for the Control Center to render, so
     * the CLI printed `controlCenter.guidance.dependency` where an operator
     * expected an instruction. A raw key is worse than a vague sentence.
     *
     * Requiring the argument also produces better advice, because a check
     * knows more than a blocker reason does: "Run the preflight against
     * shared_hosting and clear its blockers first" beats "Make the provider
     * this one depends on ready first".
     */
    public static function blocked(
        string $id,
        CheckCategory $category,
        string $target,
        string $summary,
        BlockerReason $blocker,
        string $nextAction,
        EvidenceClass $evidence = EvidenceClass::Configuration,
    ): self {
        return new self(
            $id,
            $category,
            CheckStatus::Blocked,
            $target,
            $summary,
            $evidence,
            blocker: $blocker,
            nextAction: $nextAction,
        );
    }

    public static function warning(
        string $id,
        CheckCategory $category,
        string $target,
        string $summary,
        ?string $nextAction = null,
        EvidenceClass $evidence = EvidenceClass::Configuration,
    ): self {
        return new self($id, $category, CheckStatus::Warning, $target, $summary, $evidence, nextAction: $nextAction);
    }

    public static function notApplicable(string $id, CheckCategory $category, string $target, string $summary): self
    {
        return new self($id, $category, CheckStatus::NotApplicable, $target, $summary, EvidenceClass::Configuration);
    }

    /**
     * Nothing established, and why.
     *
     * The reason is required. An untested check with no reason is
     * indistinguishable from a check nobody wrote, and the difference matters
     * to whoever reads the report.
     */
    public static function notTested(string $id, CheckCategory $category, string $target, string $reason): self
    {
        return new self($id, $category, CheckStatus::NotTested, $target, $reason, EvidenceClass::None);
    }

    public function withDuration(int $milliseconds): self
    {
        return new self(
            $this->id,
            $this->category,
            $this->status,
            $this->target,
            $this->summary,
            $this->evidence,
            $this->blocker,
            $this->nextAction,
            $milliseconds,
            $this->verified,
        );
    }

    /**
     * May this finding support a real-infrastructure claim?
     *
     * Both halves are required, and they are different questions: the evidence
     * has to have come from a real endpoint, and the check has to have
     * actually passed. A real endpoint answering a read with a failure is real
     * evidence of a failure, which is not something to claim verification for.
     */
    public function evidencesReality(): bool
    {
        return $this->evidence->mayClaimReality()
            && $this->status->established()
            && $this->verified === VerificationLevel::RealInfraVerified;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'target' => $this->target,
            'summary' => $this->summary,
            'evidence_class' => $this->evidence->value,
            'blocker_reason' => $this->blocker?->value,
            'next_action' => $this->nextAction,
            'duration_ms' => $this->durationMs,
            'verified' => $this->verified?->value,
        ];
    }
}
