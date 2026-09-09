<?php

declare(strict_types=1);

namespace Tests\Unit\Estate;

use Lynomia\Modules\Estate\Domain\Enums\EstateAction;
use Lynomia\Modules\Estate\Domain\Enums\SafetyClass;
use Lynomia\Modules\Estate\Domain\Exceptions\SafetyRefusal;
use Lynomia\Modules\Estate\Domain\Services\SafetyGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The classification is only worth having if the gate enforces it.
 *
 * Every class against every action, exhaustively, in the same shape as
 * infrastructure/scripts/test_safety_gate.sh proves the Ansible role — because
 * the two halves of the estate implement one rule, and a test that covers only
 * the half written in PHP would not notice them diverging.
 */
final class TheSafetyGateRefusesWhatItClaimsToTest extends TestCase
{
    /**
     * @return array<string, array{SafetyClass, bool, EstateAction, bool}>
     */
    public static function everyClassAndAction(): array
    {
        return [
            // The default refuses everything, including a read. A machine
            // nobody has claimed is not one we connect to "just to look".
            'do not touch refuses a read' => [SafetyClass::DoNotTouch, false, EstateAction::Read, false],
            'do not touch refuses configuration' => [SafetyClass::DoNotTouch, false, EstateAction::Configure, false],
            'do not touch refuses a reimage' => [SafetyClass::DoNotTouch, false, EstateAction::Reimage, false],
            'do not touch refuses a reimage even when cleared' => [SafetyClass::DoNotTouch, true, EstateAction::Reimage, false],

            // Discovery may look and may not touch.
            'discovery permits a read' => [SafetyClass::DiscoveryOnly, false, EstateAction::Read, true],
            'discovery refuses configuration' => [SafetyClass::DiscoveryOnly, false, EstateAction::Configure, false],
            'discovery refuses a reimage' => [SafetyClass::DiscoveryOnly, false, EstateAction::Reimage, false],

            // Configuration may change settings and never wipe.
            'configuration permits a read' => [SafetyClass::ConfigurationAllowed, false, EstateAction::Read, true],
            'configuration permits configuration' => [SafetyClass::ConfigurationAllowed, false, EstateAction::Configure, true],
            'configuration refuses a reimage' => [SafetyClass::ConfigurationAllowed, false, EstateAction::Reimage, false],

            // The class that may be wiped still needs this machine cleared for
            // this piece of work.
            'reimageable permits a read' => [SafetyClass::ReimageAllowed, false, EstateAction::Read, true],
            'reimageable permits configuration' => [SafetyClass::ReimageAllowed, false, EstateAction::Configure, true],
            'reimageable refuses a reimage until cleared' => [SafetyClass::ReimageAllowed, false, EstateAction::Reimage, false],
            'reimageable permits a reimage once cleared' => [SafetyClass::ReimageAllowed, true, EstateAction::Reimage, true],
        ];
    }

    #[Test]
    #[DataProvider('everyClassAndAction')]
    public function the_gate_permits_exactly_what_the_classification_says(
        SafetyClass $classification,
        bool $allowReimage,
        EstateAction $action,
        bool $expected,
    ): void {
        $gate = new SafetyGate;

        $this->assertSame(
            $expected,
            $gate->permits($classification, $allowReimage, $action),
            sprintf('%s + %s should %s %s', $classification->value, $allowReimage ? 'cleared' : 'not cleared', $expected ? 'permit' : 'refuse', $action->value),
        );
    }

    #[Test]
    #[DataProvider('everyClassAndAction')]
    public function assert_throws_for_exactly_the_cases_permits_refuses(
        SafetyClass $classification,
        bool $allowReimage,
        EstateAction $action,
        bool $expected,
    ): void {
        $gate = new SafetyGate;

        if (! $expected) {
            $this->expectException(SafetyRefusal::class);
        }

        $gate->assert('pve-1', $classification, $allowReimage, $action);

        // Reached only on the permitted cases; assert something so the test is
        // not risky rather than merely quiet.
        $this->assertTrue($expected);
    }

    #[Test]
    public function a_refusal_says_which_classification_would_have_permitted_it(): void
    {
        $gate = new SafetyGate;

        try {
            $gate->assert('pve-1', SafetyClass::DiscoveryOnly, false, EstateAction::Configure);
            $this->fail('The gate permitted configuration on a discovery-only machine.');
        } catch (SafetyRefusal $refusal) {
            $this->assertSame('pve-1', $refusal->server);
            $this->assertSame(EstateAction::Configure, $refusal->attempted);
            $this->assertSame(SafetyClass::DiscoveryOnly, $refusal->classification);
            $this->assertSame(SafetyClass::ConfigurationAllowed, $refusal->wouldPermit);
        }
    }

    #[Test]
    public function a_reimage_refusal_names_the_clearance_rather_than_the_class(): void
    {
        $gate = new SafetyGate;

        try {
            $gate->assert('pve-1', SafetyClass::ReimageAllowed, false, EstateAction::Reimage);
            $this->fail('The gate permitted a reimage on a machine that was never cleared for one.');
        } catch (SafetyRefusal $refusal) {
            // The message has to send the operator to allow_reimage, not to the
            // classification they have already set.
            $this->assertStringContainsString('allow_reimage', $refusal->getMessage());
            $this->assertSame(SafetyClass::ReimageAllowed, $refusal->classification);
        }
    }

    /**
     * @return array<string, array{SafetyClass, SafetyClass, bool}>
     */
    public static function classTransitions(): array
    {
        return [
            'untouchable to discovery is one rung' => [SafetyClass::DoNotTouch, SafetyClass::DiscoveryOnly, true],
            'discovery to configuration is one rung' => [SafetyClass::DiscoveryOnly, SafetyClass::ConfigurationAllowed, true],
            'configuration to reimageable is one rung' => [SafetyClass::ConfigurationAllowed, SafetyClass::ReimageAllowed, true],

            // The transition this ladder exists to prevent: a machine arriving
            // and being cleared for a wipe without anybody having looked at it.
            'untouchable straight to reimageable is refused' => [SafetyClass::DoNotTouch, SafetyClass::ReimageAllowed, false],
            'untouchable straight to configuration is refused' => [SafetyClass::DoNotTouch, SafetyClass::ConfigurationAllowed, false],
            'discovery straight to reimageable is refused' => [SafetyClass::DiscoveryOnly, SafetyClass::ReimageAllowed, false],

            // Deciding to touch a machine less is never the dangerous
            // direction, so any drop is allowed in one step.
            'reimageable down to untouchable is allowed' => [SafetyClass::ReimageAllowed, SafetyClass::DoNotTouch, true],
            'configuration down to discovery is allowed' => [SafetyClass::ConfigurationAllowed, SafetyClass::DiscoveryOnly, true],

            'no change is not a transition' => [SafetyClass::DiscoveryOnly, SafetyClass::DiscoveryOnly, false],
        ];
    }

    #[Test]
    #[DataProvider('classTransitions')]
    public function a_classification_rises_one_rung_at_a_time(
        SafetyClass $from,
        SafetyClass $to,
        bool $allowed,
    ): void {
        $this->assertSame($allowed, $from->mayBecome($to));
    }
}
