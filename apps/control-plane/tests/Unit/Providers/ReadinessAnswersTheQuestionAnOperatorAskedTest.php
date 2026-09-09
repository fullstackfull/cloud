<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\DTOs\CatalogueEntry;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Services\ProviderReadiness;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Readiness has to name the thing a person can go and fix.
 *
 * ---------------------------------------------------------------------------
 * What this is really testing
 * ---------------------------------------------------------------------------
 *
 * Not "does the boolean come out right". The reason a readiness engine is
 * worth having is that it answers "why can I not sell this yet" with something
 * actionable, and there is exactly one way for it to be useless while passing
 * a naive test: report the last blocker it happens to notice rather than the
 * first one that can be acted on.
 *
 * So most of these cases stack several problems at once and assert which one
 * is reported. A provider with no machine, no licence and no credential has
 * three problems and one next step, and telling somebody about the credential
 * would send them to configure a secret for a machine that nobody has agreed
 * we may touch.
 */
final class ReadinessAnswersTheQuestionAnOperatorAskedTest extends TestCase
{
    use RefreshDatabase;

    private ProviderReadiness $readiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->readiness = new ProviderReadiness;
    }

    private function entry(
        ProviderCategory $category = ProviderCategory::Dns,
        bool $needsEndpoint = true,
        bool $needsCredential = true,
        bool $needsLicence = false,
    ): CatalogueEntry {
        return new CatalogueEntry(
            driver: 'fake',
            category: $category,
            needsEndpoint: $needsEndpoint,
            needsCredential: $needsCredential,
            needsLicence: $needsLicence,
            summary: 'A provider for this test.',
        );
    }

    /**
     * A provider with nothing wrong with it, so each test can break one thing.
     */
    private function healthy(): ProviderInstance
    {
        $provider = ProviderInstance::factory()->create([
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::Connected,
            'credential_reference_id' => CredentialReference::factory()->proven()->create()->getKey(),
        ]);

        ProviderCapability::factory()->for($provider, 'provider')->create();

        return $provider->load(['credential', 'licence', 'server', 'capabilities']);
    }

    #[Test]
    public function everything_present_and_proven_is_ready(): void
    {
        $verdict = $this->readiness->assess($this->entry(), $this->healthy(), testerAvailable: true);

        $this->assertSame(ReadinessState::ReadyForProduction, $verdict->state);
        $this->assertNull($verdict->blocker);
    }

    #[Test]
    public function a_provider_that_runs_on_our_hardware_and_has_none_is_blocked_on_hardware(): void
    {
        $provider = $this->healthy();

        $verdict = $this->readiness->assess(
            $this->entry(category: ProviderCategory::Compute),
            $provider,
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Hardware, $verdict->blocker);
        $this->assertSame(ReadinessState::NotReady, $verdict->state);
    }

    #[Test]
    public function a_machine_nobody_may_touch_blocks_the_provider_on_it(): void
    {
        /*
         * The case that makes safety classification part of readiness rather
         * than only a guard. Nothing may connect to a do_not_touch machine, so
         * a provider on one can never be tested and can never become ready —
         * and saying "not tested" would send an operator to press a button
         * that is going to be refused.
         */
        $server = ManagedServer::factory()->create([
            'safety_class' => SafetyClass::DoNotTouch,
            'environment' => DeploymentEnvironment::Staging,
        ]);

        $provider = $this->healthy();
        $provider->forceFill(['managed_server_id' => $server->getKey()])->save();
        $provider->load('server');

        $verdict = $this->readiness->assess(
            $this->entry(category: ProviderCategory::Compute),
            $provider,
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Hardware, $verdict->blocker);
        $this->assertStringContainsString('do_not_touch', $verdict->detail);
        $this->assertStringContainsString($server->name, $verdict->detail);
    }

    #[Test]
    public function a_machine_that_may_be_looked_at_does_not_block_the_provider(): void
    {
        // The other half of the rule above, and the reason it is isTouchable()
        // rather than a check for one class: discovery_only permits a read, and
        // a connection test is a read.
        $server = ManagedServer::factory()->create([
            'safety_class' => SafetyClass::DiscoveryOnly,
            'environment' => DeploymentEnvironment::Staging,
        ]);

        $provider = $this->healthy();
        $provider->forceFill(['managed_server_id' => $server->getKey()])->save();
        $provider->load('server');

        $verdict = $this->readiness->assess(
            $this->entry(category: ProviderCategory::Compute),
            $provider,
            testerAvailable: true,
        );

        $this->assertNull($verdict->blocker);
    }

    #[Test]
    public function hardware_is_reported_before_licence_and_credentials(): void
    {
        // Three things wrong, one next step. Reporting the credential here
        // would have somebody provision a secret for a machine that does not
        // exist.
        $provider = ProviderInstance::factory()->create([
            'endpoint' => null,
            'connection_state' => ConnectionState::NotTested,
        ]);

        $verdict = $this->readiness->assess(
            $this->entry(category: ProviderCategory::Compute, needsLicence: true),
            $provider->load(['credential', 'licence', 'server', 'capabilities']),
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Hardware, $verdict->blocker);
    }

    #[Test]
    public function a_licence_is_reported_before_a_credential(): void
    {
        $provider = ProviderInstance::factory()->create([
            'endpoint' => 'fake://connected',
            'credential_reference_id' => null,
        ]);

        $verdict = $this->readiness->assess(
            $this->entry(needsLicence: true),
            $provider->load(['credential', 'licence', 'server', 'capabilities']),
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Licence, $verdict->blocker);
    }

    #[Test]
    public function an_expired_licence_blocks_even_though_the_row_exists(): void
    {
        $provider = $this->healthy();
        $provider->forceFill([
            'licence_id' => Licence::factory()->in(LicenceState::Expired)->create()->getKey(),
        ])->save();

        $verdict = $this->readiness->assess(
            $this->entry(needsLicence: true),
            $provider->load('licence'),
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Licence, $verdict->blocker);
        $this->assertStringContainsString('expired', $verdict->detail);
    }

    #[Test]
    public function a_licence_bought_for_another_environment_does_not_cover_this_one(): void
    {
        $provider = $this->healthy();
        $provider->forceFill([
            'licence_id' => Licence::factory()
                ->forEnvironment(DeploymentEnvironment::Development)
                ->create()
                ->getKey(),
        ])->save();

        $verdict = $this->readiness->assess(
            $this->entry(needsLicence: true),
            $provider->load('licence'),
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Licence, $verdict->blocker);
        $this->assertStringContainsString('development', $verdict->detail);
    }

    /**
     * A credential that exists and has never been proven does not make a
     * provider ready, in any of the states that mean "present but unproven".
     */
    #[Test]
    public function an_unproven_credential_is_not_enough_to_serve(): void
    {
        foreach ([CredentialState::Configured, CredentialState::Untested, CredentialState::Invalid, CredentialState::Expired, CredentialState::Revoked] as $state) {
            $provider = $this->healthy();
            $provider->credential->forceFill(['state' => $state])->save();

            $verdict = $this->readiness->assess(
                $this->entry(),
                $provider->load('credential'),
                testerAvailable: true,
            );

            $this->assertSame(
                BlockerReason::Credentials,
                $verdict->blocker,
                sprintf('A %s credential should not let a provider serve.', $state->value),
            );
        }
    }

    #[Test]
    public function a_credential_for_another_environment_is_refused_even_when_it_is_valid(): void
    {
        /*
         * The separation the whole module rests on. This credential is proven
         * — somebody has used it successfully — and it is proven somewhere
         * else. mayServe answers no, and the reason is the environment rather
         * than the state.
         */
        $provider = ProviderInstance::factory()->inProduction()->create([
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::Connected,
            'credential_reference_id' => CredentialReference::factory()
                ->proven()
                ->forEnvironment(DeploymentEnvironment::Staging)
                ->create()
                ->getKey(),
        ]);

        ProviderCapability::factory()->for($provider, 'provider')->create();

        $verdict = $this->readiness->assess(
            $this->entry(),
            $provider->load(['credential', 'licence', 'server', 'capabilities']),
            testerAvailable: true,
        );

        $this->assertSame(BlockerReason::Credentials, $verdict->blocker);
        $this->assertStringContainsString('staging', $verdict->detail);
    }

    #[Test]
    public function a_driver_with_no_tester_cannot_become_ready_and_says_so(): void
    {
        /*
         * The honest state of this build. Most catalogued drivers have an
         * adapter and no connection tester, and a provider using one can never
         * be proven. Reported as a configuration blocker naming the driver,
         * because the gap is Lynomia's rather than the vendor's.
         */
        $verdict = $this->readiness->assess($this->entry(), $this->healthy(), testerAvailable: false);

        $this->assertSame(BlockerReason::Configuration, $verdict->blocker);
        $this->assertSame(ReadinessState::NotReady, $verdict->state);
        $this->assertStringContainsString('fake', $verdict->detail);
    }

    #[Test]
    public function a_provider_nobody_has_contacted_is_ready_to_test_rather_than_blocked(): void
    {
        // Not the same thing as broken, and the state says so. Everything it
        // needs is present; the only missing step is the test itself.
        $provider = $this->healthy();
        $provider->forceFill(['connection_state' => ConnectionState::NotTested])->save();

        $verdict = $this->readiness->assess($this->entry(), $provider, testerAvailable: true);

        $this->assertSame(ReadinessState::ReadyForTest, $verdict->state);
    }

    #[Test]
    public function a_provider_that_answered_badly_reports_the_blocker_the_answer_implies(): void
    {
        $cases = [
            ConnectionState::AuthFailed->value => [ConnectionState::AuthFailed, BlockerReason::Credentials],
            ConnectionState::NetworkFailed->value => [ConnectionState::NetworkFailed, BlockerReason::Network],
            ConnectionState::LicenceMissing->value => [ConnectionState::LicenceMissing, BlockerReason::Licence],
            ConnectionState::PermissionInsufficient->value => [ConnectionState::PermissionInsufficient, BlockerReason::Configuration],
        ];

        foreach ($cases as [$connection, $expected]) {
            $provider = $this->healthy();
            $provider->forceFill(['connection_state' => $connection])->save();

            $verdict = $this->readiness->assess($this->entry(), $provider, testerAvailable: true);

            $this->assertSame(
                $expected,
                $verdict->blocker,
                sprintf('%s should be reported as %s.', $connection->value, $expected->value),
            );
        }
    }

    #[Test]
    public function a_provider_that_answers_but_was_never_asked_what_it_can_do_is_not_ready(): void
    {
        /*
         * The case this phase exists for. Reachable, authenticated, licensed —
         * and nobody has established that this account can create a zone. A
         * platform that called this ready would be one that sells a product
         * and finds out at provisioning time.
         */
        $provider = $this->healthy();
        $provider->capabilities()->delete();
        $provider->setRelation('capabilities', new Collection);

        $verdict = $this->readiness->assess($this->entry(), $provider, testerAvailable: true);

        $this->assertSame(ReadinessState::ReadyForTest, $verdict->state);
        $this->assertSame(BlockerReason::Configuration, $verdict->blocker);
        $this->assertStringContainsString('discovery', $verdict->detail);
    }

    #[Test]
    public function a_provider_needing_no_credential_is_not_blocked_for_want_of_one(): void
    {
        // NotRequired is a real answer, and a readiness engine that cannot say
        // it teaches operators to ignore the column.
        $provider = ProviderInstance::factory()->create([
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::Connected,
            'credential_reference_id' => null,
        ]);

        ProviderCapability::factory()->for($provider, 'provider')->create();

        $verdict = $this->readiness->assess(
            $this->entry(needsCredential: false),
            $provider->load(['credential', 'licence', 'server', 'capabilities']),
            testerAvailable: true,
        );

        $this->assertNull($verdict->blocker);
    }
}
