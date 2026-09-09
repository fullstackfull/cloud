<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "May we try this credential" and "may we rely on it" are different questions.
 *
 * Collapsing them into one produces a deadlock nobody notices until the
 * control centre is empty: a credential reaches Valid only by being tested, so
 * a test that demanded a Valid credential could never run on a new one. Every
 * credential would sit at Configured for ever, every provider would show
 * blocked, and the platform would report an estate it had never contacted.
 *
 * The environment half of the rule is the same for both, and it is the half
 * that must never bend.
 */
final class ACredentialIsTriedBeforeItIsTrustedTest extends TestCase
{
    private function credential(CredentialState $state, DeploymentEnvironment $environment): CredentialReference
    {
        $credential = new CredentialReference;
        $credential->setRawAttributes([
            'state' => $state->value,
            'environment' => $environment->value,
        ], sync: true);

        return $credential;
    }

    /**
     * @return array<string, array{CredentialState, bool, bool}>
     */
    public static function states(): array
    {
        return [
            //                                          may be tried, may serve
            'missing is neither' => [CredentialState::Missing, false, false],
            'revoked is neither' => [CredentialState::Revoked, false, false],

            // The case the split exists for: present, unproven, and the only
            // way to prove it is to try it.
            'configured may be tried and not relied on' => [CredentialState::Configured, true, false],
            'untested may be tried and not relied on' => [CredentialState::Untested, true, false],

            // Worth trying precisely because the answer might have changed.
            'invalid may be tried and not relied on' => [CredentialState::Invalid, true, false],
            'expired may be tried and not relied on' => [CredentialState::Expired, true, false],

            'valid is both' => [CredentialState::Valid, true, true],
            'rotation due is both' => [CredentialState::RotationDue, true, true],
        ];
    }

    #[Test]
    #[DataProvider('states')]
    public function trying_and_relying_are_answered_separately(
        CredentialState $state,
        bool $mayBeTried,
        bool $mayServe,
    ): void {
        $credential = $this->credential($state, DeploymentEnvironment::Staging);

        $this->assertSame($mayBeTried, $credential->mayBeTried(DeploymentEnvironment::Staging));
        $this->assertSame($mayServe, $credential->mayServe(DeploymentEnvironment::Staging));
    }

    /**
     * @return array<string, array{DeploymentEnvironment, DeploymentEnvironment}>
     */
    public static function mismatchedEnvironments(): array
    {
        return [
            'staging against production' => [DeploymentEnvironment::Staging, DeploymentEnvironment::Production],
            'production against staging' => [DeploymentEnvironment::Production, DeploymentEnvironment::Staging],
            'development against production' => [DeploymentEnvironment::Development, DeploymentEnvironment::Production],
            'production against development' => [DeploymentEnvironment::Production, DeploymentEnvironment::Development],
        ];
    }

    #[Test]
    #[DataProvider('mismatchedEnvironments')]
    public function a_credential_is_never_used_across_environments_even_to_test_it(
        DeploymentEnvironment $held,
        DeploymentEnvironment $wanted,
    ): void {
        // Valid, and still refused. The state is the half that bends; the
        // environment is the half that does not — not even "just to see what
        // happens", because what happens is a real call to the wrong account.
        $credential = $this->credential(CredentialState::Valid, $held);

        $this->assertFalse($credential->mayBeTried($wanted));
        $this->assertFalse($credential->mayServe($wanted));
    }
}
