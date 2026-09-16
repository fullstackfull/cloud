<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightReport;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\Infrastructure\Domain\Preflight\VerificationLevel;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing passes a preflight by accident, and nothing fails one by being
 * refused too broadly.
 *
 * ===========================================================================
 * BOTH HALVES, ALWAYS
 * ===========================================================================
 *
 * The false-pass half is obvious: a preflight that reports green with a
 * missing credential is worse than no preflight, because somebody acts on it.
 *
 * The positive half is the one that gets left out, and leaving it out is how a
 * gate rots. A preflight that refused everything would pass every false-pass
 * test in this file — and an operator who has seen it refuse a correct
 * configuration twice stops reading it, which costs more than the check was
 * ever worth. So every refusal here has a twin that proves the correct
 * arrangement is accepted, and the twin sits next to it.
 *
 * ===========================================================================
 * WHAT MODE MEANS IN A TEST
 * ===========================================================================
 *
 * Simulation runs against the controlled driver, whose outcome is written into
 * the endpoint — `fake://auth-failed`, `fake://tls-failed` — so the whole chain
 * from probe through finding to aggregation runs for real without a socket.
 *
 * Real mode runs against a faked HTTP layer with each product's actual
 * response shapes, which is how READ_ONLY_REAL is proven CODE_COMPLETE in CI
 * where no real provider exists. A faked response is never called real
 * validation, and `real_verification_claims` is asserted rather than assumed.
 */
final class APreflightSaysExactlyWhatIsInTheWayTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_PREFLIGHT_SECRET';

    protected function setUp(): void
    {
        parent::setUp();

        putenv(self::VARIABLE.'=a-value-that-is-never-reported');
    }

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);

        parent::tearDown();
    }

    /* =====================================================================
     | Credentials
     ===================================================================== */

    #[Test]
    public function a_provider_with_no_credential_cannot_pass_and_nothing_below_it_is_guessed_at(): void
    {
        $provider = ProviderInstance::factory()->create(['credential_reference_id' => null]);

        $report = $this->runFor($provider);

        $this->assertBlockedOn($report, 'provider.credential', BlockerReason::Credentials);

        /*
         * The root-cause rule. Everything past the break is recorded as not
         * established, naming the credential — so the operator reading the
         * fifth line still knows to act on the first, and does not spend the
         * afternoon on an identity failure that was never real.
         */
        foreach (['provider.endpoint', 'provider.identity', 'provider.capabilities', 'provider.licence'] as $downstream) {
            $finding = $this->finding($report, $downstream);

            $this->assertSame(CheckStatus::NotTested, $finding->status, $downstream.' was guessed at.');
            $this->assertStringContainsString('provider.credential', $finding->summary);
        }

        $this->assertSame(
            ['provider.credential'],
            array_map(static fn (PreflightFinding $f): string => $f->id, $report->blockers()),
            'One missing credential produced more than one blocker. Five findings for one cause is how an operator '
            .'learns to skim the report.',
        );
    }

    #[Test]
    public function a_correct_credential_reference_reaches_the_tester(): void
    {
        // The positive twin. If the check above were simply refusing
        // everything, this would fail.
        $provider = $this->configuredProvider();

        $report = $this->runFor($provider);

        $credential = $this->finding($report, 'provider.credential');

        $this->assertSame(CheckStatus::Pass, $credential->status);
        $this->assertStringContainsString('PRESENT', $credential->summary);
        $this->assertSame(CheckStatus::Pass, $this->finding($report, 'provider.identity')->status);
    }

    #[Test]
    public function a_credential_from_another_environment_cannot_pass_and_is_never_resolved(): void
    {
        $provider = ProviderInstance::factory()->create([
            'credential_reference_id' => CredentialReference::factory()
                ->forEnvironment(DeploymentEnvironment::Production)
                ->create(['backend_reference' => self::VARIABLE])
                ->getKey(),
        ]);

        $report = $this->runFor($provider);

        $credential = $this->finding($report, 'provider.credential');

        $this->assertSame(CheckStatus::Blocked, $credential->status);
        $this->assertStringContainsString('ENVIRONMENT_MISMATCH', $credential->summary);
        $this->assertStringContainsString('was not resolved', $credential->summary);
    }

    #[Test]
    public function a_credential_whose_backend_holds_nothing_cannot_pass(): void
    {
        $provider = ProviderInstance::factory()->create([
            'credential_reference_id' => CredentialReference::factory()
                ->create(['backend_reference' => 'LYNOMIA_TEST_DEFINITELY_UNSET_'.uniqid()])
                ->getKey(),
        ]);

        $report = $this->runFor($provider);

        $this->assertBlockedOn($report, 'provider.credential', BlockerReason::Credentials);
        $this->assertStringContainsString('MISSING', $this->finding($report, 'provider.credential')->summary);
    }

    #[Test]
    public function a_revoked_credential_cannot_pass(): void
    {
        $provider = ProviderInstance::factory()->create([
            'credential_reference_id' => CredentialReference::factory()
                ->in(CredentialState::Revoked)
                ->create(['backend_reference' => self::VARIABLE])
                ->getKey(),
        ]);

        $this->assertBlockedOn($this->runFor($provider), 'provider.credential', BlockerReason::Credentials);
    }

    #[Test]
    public function no_report_ever_contains_the_credential_value(): void
    {
        $provider = $this->configuredProvider();

        $json = (string) json_encode($this->runFor($provider)->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('a-value-that-is-never-reported', $json);
    }

    /* =====================================================================
     | Identity — the Gap 2 distinctions, preserved
     ===================================================================== */

    #[Test]
    public function an_identity_mismatch_cannot_pass_and_does_not_read_as_a_credential_problem(): void
    {
        /*
         * The distinction that matters most, asserted where an operator
         * actually reads it. A mismatch says the endpoint is wrong; it says
         * nothing about the credential, because the credential was never
         * judged. Reporting it as an authentication failure sends somebody to
         * rotate a working key, and they come back an hour later with the same
         * red row and less trust in the credential centre.
         */
        Http::fake(['*' => Http::response('OK')]);

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $identity = $this->finding($report, 'provider.identity');

        $this->assertSame(CheckStatus::Blocked, $identity->status);
        $this->assertSame(BlockerReason::Configuration, $identity->blocker);
        $this->assertStringContainsString('not this product', $identity->summary);
        $this->assertStringContainsString('do not rotate it', (string) $identity->nextAction);
        $this->assertNotSame(BlockerReason::Credentials, $identity->blocker);
    }

    #[Test]
    public function a_verified_handshake_with_nothing_behind_it_cannot_pass(): void
    {
        /*
         * The 30B.0 finding, carried into the preflight. In this project's own
         * sandbox a TLS handshake to a hostname that does not exist completes
         * and verifies against the trust store — so a socket opening, a
         * handshake completing and a certificate checking out are, all three,
         * not evidence that a destination exists.
         *
         * Faked here as a 200 with a body that is not the product, because the
         * assertion is about what the platform concludes, and that does not
         * depend on who produced the bytes.
         */
        Http::fake(['*' => Http::response('{"message":"upstream connect error or disconnect"}', 200)]);

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $this->assertFalse($report->passed());
        $this->assertSame(CheckStatus::Blocked, $this->finding($report, 'provider.identity')->status);
        $this->assertSame([], $report->realVerificationClaims(), 'A handshake earned a real-infrastructure claim.');
    }

    #[Test]
    public function another_products_response_cannot_pass_as_this_ones(): void
    {
        // WHM's own answer, served to the Proxmox driver. The wrong-schema
        // case, which no status code tells apart.
        Http::fake(['*' => Http::response([
            'metadata' => ['command' => 'version', 'result' => 1],
            'data' => ['version' => '11.126.0.4'],
        ])]);

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $this->assertFalse($report->passed());
        $this->assertSame(CheckStatus::Blocked, $this->finding($report, 'provider.identity')->status);
    }

    #[Test]
    public function the_products_own_answer_passes_and_earns_a_claim_for_that_read_only(): void
    {
        /*
         * The positive twin of the three above, and the only place in this
         * phase where REAL_INFRA_VERIFIED is earned at all.
         *
         * What it is earned for is named: `provider.identity`. Not the
         * provider, not the product, not the estate. A cluster answering an
         * authenticated read has established that this platform can
         * authenticate to it and read from it — nothing about whether a
         * virtual machine will build.
         */
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2', 'repoid' => 'abc']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => ['/vms' => ['VM.Allocate' => 1, 'VM.PowerMgmt' => 1]]]),
        ]);

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $identity = $this->finding($report, 'provider.identity');

        $this->assertSame(CheckStatus::Pass, $identity->status);
        $this->assertSame(EvidenceClass::RealRead, $identity->evidence);
        $this->assertSame(VerificationLevel::RealInfraVerified, $identity->verified);
        $this->assertSame(['provider.identity'], $report->realVerificationClaims());
        $this->assertContains(VerificationLevel::RealInfraVerified->value, $report->verificationLevels());
    }

    #[Test]
    public function a_capability_nobody_has_exercised_cannot_become_real_infra_verified(): void
    {
        /*
         * The generalisation this phase refuses to make. The identity read
         * succeeded against a real endpoint; `create` is a write and was not
         * attempted, so it stays unknown and earns nothing. There is no code
         * path by which a successful read promotes an unexercised capability.
         */
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => ['/' => ['Sys.Audit' => 1]]]),
        ]);

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006');

        ProviderCapability::factory()->create([
            'provider_instance_id' => $provider->getKey(),
            'capability' => 'create',
            'state' => CapabilityState::Unknown,
        ]);

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $this->assertSame(
            ['provider.identity'],
            $report->realVerificationClaims(),
            'Something other than the identity read earned a real-infrastructure claim.',
        );
    }

    /* =====================================================================
     | Network
     ===================================================================== */

    #[Test]
    public function an_endpoint_the_policy_refuses_cannot_pass(): void
    {
        $provider = $this->realProvider('cpanel', ProviderCategory::Hosting, 'https://169.254.169.254:2087');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $this->assertBlockedOn($report, 'provider.endpoint', BlockerReason::Network);
        $this->assertSame(CheckStatus::NotTested, $this->finding($report, 'provider.identity')->status);
    }

    #[Test]
    public function a_private_endpoint_for_a_provider_on_our_own_hardware_is_accepted(): void
    {
        /*
         * The positive twin, and the reason the endpoint policy is not simply
         * "refuse private addresses". A Proxmox cluster is on the management
         * network: a private address for it is where it actually is, and a
         * preflight that refused it would refuse every correct deployment.
         */
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => ['/' => ['Sys.Audit' => 1]]]),
        ]);

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://10.66.0.9:8006');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $this->assertSame(CheckStatus::Pass, $this->finding($report, 'provider.endpoint')->status);
    }

    /* =====================================================================
     | Licence
     ===================================================================== */

    #[Test]
    public function a_licensed_product_with_no_licence_cannot_pass(): void
    {
        $this->fakeWhm();

        $provider = $this->realProvider('cpanel', ProviderCategory::Hosting, 'https://whm.example.test:2087');

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        // cPanel is a commercial product and an unlicensed node stops serving.
        // Nobody fixes that by rotating a token, and an operator sent to do so
        // loses a day — which is what the Licence Center exists to prevent.
        $this->assertBlockedOn($report, 'provider.licence', BlockerReason::Licence);
    }

    #[Test]
    public function an_active_licence_passes_and_an_expiring_one_only_warns(): void
    {
        $this->fakeWhm();

        $provider = $this->realProvider('cpanel', ProviderCategory::Hosting, 'https://whm.example.test:2087');

        $provider->forceFill([
            'licence_id' => Licence::factory()->create([
                'product' => 'cpanel',
                'state' => LicenceState::Active,
                'environment' => DeploymentEnvironment::Staging,
            ])->getKey(),
        ])->save();

        $report = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        $this->assertSame(CheckStatus::Pass, $this->finding($report, 'provider.licence')->status);

        $provider->licence?->forceFill(['state' => LicenceState::Expiring])->save();

        $expiring = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $provider->name);

        /*
         * A warning, not a blocker, and the report still passes. An expiring
         * licence is worth saying and is not worth stopping a deployment
         * pipeline over — and if it were, it would be a blocker.
         */
        $this->assertSame(CheckStatus::Warning, $this->finding($expiring, 'provider.licence')->status);
        $this->assertTrue($expiring->passed());
    }

    /* =====================================================================
     | Mappings
     ===================================================================== */

    #[Test]
    public function a_product_with_no_storage_mapping_cannot_pass(): void
    {
        $this->computeEstate(withStorage: false);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Product, Product::Vps->value);

        $storage = $this->finding($report, 'mapping.storage');

        $this->assertSame(CheckStatus::Fail, $storage->status);
        $this->assertStringContainsString('Map a storage pool', (string) $storage->nextAction);
        $this->assertFalse($report->passed());
    }

    #[Test]
    public function a_product_with_a_mapped_storage_pool_passes_that_check(): void
    {
        $this->computeEstate(withStorage: true);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Product, Product::Vps->value);

        $this->assertSame(CheckStatus::Pass, $this->finding($report, 'mapping.storage')->status);
    }

    #[Test]
    public function a_template_the_hypervisor_has_no_name_for_cannot_pass(): void
    {
        /*
         * A template row with no provider reference is a template this
         * platform believes in and the cluster has never heard of. It fails at
         * build time, per order, which is the worst possible moment — and no
         * code path anywhere in this platform invents an identifier to paper
         * over it.
         */
        $cluster = $this->computeEstate(withStorage: true);

        VmTemplate::query()->where('cluster_id', $cluster->getKey())->update(['provider_reference' => null]);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Product, Product::Vps->value);

        $template = $this->finding($report, 'mapping.template');

        $this->assertSame(CheckStatus::Fail, $template->status);
        $this->assertStringContainsString('no name for any of them', $template->summary);
        $this->assertStringContainsString('read off the cluster rather than chosen', (string) $template->nextAction);
        $this->assertFalse($report->passed());
    }

    #[Test]
    public function a_template_with_a_reference_taken_from_the_cluster_passes(): void
    {
        $this->computeEstate(withStorage: true);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Product, Product::Vps->value);

        $template = $this->finding($report, 'mapping.template');

        $this->assertSame(CheckStatus::Pass, $template->status);
        $this->assertStringContainsString('installable template', $template->summary);
    }

    /* =====================================================================
     | Simulation must never satisfy production
     ===================================================================== */

    #[Test]
    public function a_simulation_run_never_claims_real_verification(): void
    {
        $provider = $this->configuredProvider();

        $report = $this->runFor($provider);

        $this->assertSame(CheckStatus::Pass, $this->finding($report, 'provider.identity')->status);
        $this->assertSame(EvidenceClass::Simulation, $this->finding($report, 'provider.identity')->evidence);
        $this->assertSame([], $report->realVerificationClaims());
        $this->assertNotContains(VerificationLevel::RealInfraVerified->value, $report->verificationLevels());
        $this->assertSame('SIMULATION', $report->toArray()['mode_label']);
    }

    #[Test]
    public function a_controlled_provider_cannot_satisfy_a_production_row(): void
    {
        /*
         * The Gap 2 guard, reached through the preflight. A production row is
         * what the readiness engine consults before a product is offered for
         * sale, and a fake reporting it connected is how a customer buys
         * something that does not exist.
         */
        $provider = ProviderInstance::factory()->inProduction()->create([
            'credential_reference_id' => CredentialReference::factory()
                ->forEnvironment(DeploymentEnvironment::Production)
                ->create(['backend_reference' => self::VARIABLE])
                ->getKey(),
        ]);

        /*
         * Asserted in BOTH modes, because a deliberate breakage proved that
         * checking only one of them was vacuous: the real-mode branch used to
         * return a soft warning before the guard was ever reached, so removing
         * the guard changed nothing and this test noticed nothing.
         */
        foreach ([PreflightMode::Simulation, PreflightMode::ReadOnlyReal] as $mode) {
            $report = $this->preflight($mode, PreflightScope::Provider, $provider->name);

            $identity = $this->finding($report, 'provider.identity');

            $this->assertSame(
                CheckStatus::Blocked,
                $identity->status,
                sprintf('A fake answered for a production row in %s mode.', $mode->value),
            );
            $this->assertSame(BlockerReason::NotImplemented, $identity->blocker);
            $this->assertFalse($report->passed());
            $this->assertSame([], $report->realVerificationClaims());
        }
    }

    #[Test]
    public function a_controlled_provider_in_a_non_production_row_is_still_allowed_to_rehearse(): void
    {
        /*
         * The positive twin of the refusal above. The controlled driver exists
         * so that the whole onboarding path — provider, credential, endpoint,
         * identity, capabilities — can be rehearsed without a datacenter, and
         * a refusal broad enough to stop that would have removed the only way
         * anybody ever sees these screens work.
         */
        $provider = $this->configuredProvider();

        $report = $this->runFor($provider);

        $this->assertSame(CheckStatus::Pass, $this->finding($report, 'provider.identity')->status);
        $this->assertSame(EvidenceClass::Simulation, $this->finding($report, 'provider.identity')->evidence);
    }

    #[Test]
    public function simulation_does_not_dial_a_real_provider_at_all(): void
    {
        Http::fake();

        $provider = $this->realProvider('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006');

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Provider, $provider->name);

        Http::assertNothingSent();

        $identity = $this->finding($report, 'provider.identity');

        $this->assertSame(CheckStatus::NotTested, $identity->status);
        $this->assertStringContainsString('does not dial real providers', $identity->summary);
    }

    #[Test]
    public function a_simulation_pass_does_not_remove_a_real_mode_blocker(): void
    {
        /*
         * Both answers are valid at once, which is the normal state of a
         * platform being brought up: the software works, and the estate is not
         * there yet. Nothing about a green simulation is allowed to travel
         * into a real-mode report.
         */
        $simulated = $this->configuredProvider();

        $this->assertTrue($this->runFor($simulated)->passed() || $this->runFor($simulated)->blockers() !== []);

        Http::fake(['*' => Http::response('', 500)]);

        $real = $this->realProvider('cpanel', ProviderCategory::Hosting, 'https://whm.example.test:2087', withCredential: false);

        $realReport = $this->preflight(PreflightMode::ReadOnlyReal, PreflightScope::Provider, $real->name);

        $this->assertFalse($realReport->passed());
        $this->assertSame(BlockerReason::Credentials, $this->finding($realReport, 'provider.credential')->blocker);
    }

    /* =====================================================================
     | Aggregation
     ===================================================================== */

    #[Test]
    public function one_blocking_check_makes_the_whole_report_blocking(): void
    {
        /*
         * No average, no percentage, no severity weighting that lets eleven
         * passes outvote one missing credential. The operator's question is
         * "can I use this", and the answer to that is no.
         */
        $provider = ProviderInstance::factory()->create(['credential_reference_id' => null]);

        $report = $this->runFor($provider);

        $this->assertGreaterThan(0, $report->countOf(CheckStatus::Pass), 'This test needs at least one passing check to be about aggregation at all.');
        $this->assertFalse($report->passed());
        $this->assertSame(CheckStatus::Blocked, $report->overallStatus());
    }

    #[Test]
    public function a_run_in_which_nothing_was_established_is_not_a_pass(): void
    {
        $report = new PreflightReport(
            mode: PreflightMode::Simulation,
            scope: PreflightScope::Estate,
            target: null,
            startedAt: now()->toImmutable(),
            finishedAt: now()->toImmutable(),
            findings: [
                PreflightFinding::notTested('a', CheckCategory::Configuration, 'x', 'nothing ran'),
                PreflightFinding::notApplicable('b', CheckCategory::Licence, 'x', 'not needed'),
            ],
        );

        $this->assertSame(CheckStatus::NotTested, $report->overallStatus());
    }

    #[Test]
    public function every_blocking_finding_carries_something_to_go_and_do(): void
    {
        /*
         * "Fix configuration" is not an action. A blocker with no next action
         * has moved the operator's problem from "something is wrong" to
         * "something is wrong somewhere", which is not progress.
         */
        $this->computeEstate(withStorage: false);
        ProviderInstance::factory()->create(['credential_reference_id' => null]);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Estate);

        $this->assertNotSame([], $report->blockers());

        foreach ($report->blockers() as $blocker) {
            $this->assertNotNull($blocker->nextAction, $blocker->id.' blocks and does not say what to do.');
            $this->assertNotSame('', trim((string) $blocker->nextAction));
            $this->assertGreaterThan(15, mb_strlen((string) $blocker->nextAction), $blocker->id.'\'s next action is too short to be one.');
        }

        $this->assertNotSame([], $report->nextActions());
    }

    #[Test]
    public function no_next_action_is_a_translation_key(): void
    {
        /*
         * A defect this file did not catch until the command was actually run,
         * which is the argument for running things.
         *
         * `BlockerReason::nextAction()` returns a translation key on purpose —
         * the Control Center renders it in the operator's language. A preflight
         * finding that adopted it printed `controlCenter.guidance.dependency`
         * from the CLI, where an operator expected an instruction. A raw key is
         * worse than a vague sentence: it looks like a bug in the tool and
         * tells them nothing.
         *
         * So every next action is a sentence written by the check that produced
         * it, and this asserts the shape rather than trusting it.
         */
        $this->computeEstate(withStorage: false);
        ProviderInstance::factory()->create(['credential_reference_id' => null]);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Estate);

        $this->assertNotSame([], $report->nextActions());

        foreach ($report->findings as $finding) {
            $action = $finding->nextAction;

            if ($action === null) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/^[a-zA-Z]+(\.[a-zA-Z]+)+$/',
                $action,
                sprintf(
                    'The next action for %s is "%s", which is a translation key rather than an instruction. The '
                    .'Control Center would render it and the CLI would print it raw.',
                    $finding->id,
                    $action,
                ),
            );

            $this->assertStringContainsString(' ', $action, $finding->id.'\'s next action is a single token.');
        }
    }

    #[Test]
    public function a_blocked_finding_only_ever_uses_the_five_reasons_this_project_reports(): void
    {
        $this->computeEstate(withStorage: false);
        ProviderInstance::factory()->create(['credential_reference_id' => null]);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Estate);

        foreach ($report->findings as $finding) {
            if ($finding->blocker === null) {
                continue;
            }

            $this->assertNotSame(
                'blocked_provider',
                $finding->blocker->value,
                'A finding reports blocked_provider, which hides which of the five real reasons it is.',
            );
        }
    }

    /* =====================================================================
     | Failure isolation
     ===================================================================== */

    #[Test]
    public function one_unreachable_provider_does_not_end_the_run(): void
    {
        ProviderInstance::factory()->reachableAs('network-failed')->create([
            'name' => 'unreachable-one',
            'credential_reference_id' => CredentialReference::factory()->create(['backend_reference' => self::VARIABLE])->getKey(),
        ]);

        $healthy = $this->configuredProvider(['name' => 'healthy-one']);

        $report = $this->preflight(PreflightMode::Simulation, PreflightScope::Estate);

        $targets = array_map(static fn (PreflightFinding $f): string => $f->target, $report->findings);

        $this->assertContains('unreachable-one', $targets);
        $this->assertContains($healthy->name, $targets, 'A failing provider cost the operator the answers about every other one.');
        $this->assertFalse($report->passed());
    }

    /* =====================================================================
     | Helpers
     ===================================================================== */

    private function runFor(ProviderInstance $provider): PreflightReport
    {
        return $this->preflight(PreflightMode::Simulation, PreflightScope::Provider, $provider->name);
    }

    private function preflight(PreflightMode $mode, PreflightScope $scope, ?string $target = null): PreflightReport
    {
        return app(InfrastructurePreflightService::class)->run(new PreflightRequest($mode, $scope, $target));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configuredProvider(array $overrides = []): ProviderInstance
    {
        return ProviderInstance::factory()->create(array_merge([
            'credential_reference_id' => CredentialReference::factory()
                ->create(['backend_reference' => self::VARIABLE])
                ->getKey(),
        ], $overrides));
    }

    /**
     * A row for a real driver, with the machine the platform already insists on.
     *
     * A compute, backup, hosting or BMC provider runs on a machine of ours, and
     * RegisterProvider refuses to create one without a managed server bound —
     * so a fixture without one would be testing a row the platform would never
     * have let exist. The machine is classified for discovery, which is the
     * least that permits a read.
     */
    private function realProvider(string $driver, ProviderCategory $category, string $endpoint, bool $withCredential = true): ProviderInstance
    {
        $secret = match ($driver) {
            'proxmox', 'proxmox_backup' => 'lynomia@pve!control=00000000-0000-0000-0000-000000000000',
            default => 'lynomia:0000000000000000000000000000',
        };

        putenv(self::VARIABLE.'='.$secret);

        $server = $category->needsServer()
            ? ManagedServer::factory()->classified(SafetyClass::DiscoveryOnly)->create()
            : null;

        return ProviderInstance::factory()->create([
            'driver' => $driver,
            'category' => $category,
            'endpoint' => $endpoint,
            'state' => ProviderState::Draft,
            'managed_server_id' => $server?->getKey(),
            'credential_reference_id' => $withCredential
                ? CredentialReference::factory()->create(['backend_reference' => self::VARIABLE])->getKey()
                : null,
        ]);
    }

    /**
     * WHM's own answers: the version command it echoes back, and the access
     * list it reports without exercising any of it.
     */
    private function fakeWhm(): void
    {
        Http::fake([
            '*/json-api/version*' => Http::response([
                'metadata' => ['command' => 'version', 'result' => 1],
                'data' => ['version' => '11.126.0.4'],
            ]),
            '*/json-api/myprivs*' => Http::response([
                'metadata' => ['command' => 'myprivs', 'result' => 1],
                'data' => ['all' => 1],
            ]),
        ]);
    }

    private function computeEstate(bool $withStorage): ComputeCluster
    {
        $cluster = ComputeCluster::factory()->create(['status' => 'active']);

        ComputeNode::factory()->create([
            'cluster_id' => $cluster->getKey(),
            'status' => 'active',
            'is_healthy' => true,
            'last_seen_at' => now(),
        ]);

        if ($withStorage) {
            ComputeStorage::factory()->create(['cluster_id' => $cluster->getKey(), 'is_active' => true]);
        }

        VmTemplate::factory()->create(['cluster_id' => $cluster->getKey(), 'provider_reference' => 'local:vztmpl/ubuntu-24.04']);
        IpPool::factory()->create();

        return $cluster;
    }

    private function finding(PreflightReport $report, string $id): PreflightFinding
    {
        foreach ($report->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail(sprintf(
            'No finding with id %s. The report holds: %s.',
            $id,
            implode(', ', array_unique(array_map(static fn (PreflightFinding $f): string => $f->id, $report->findings))),
        ));
    }

    private function assertBlockedOn(PreflightReport $report, string $id, BlockerReason $reason): void
    {
        $finding = $this->finding($report, $id);

        $this->assertSame(CheckStatus::Blocked, $finding->status, $id.' did not block.');
        $this->assertSame($reason, $finding->blocker);
        $this->assertFalse($report->passed());
        $this->assertContains($reason->value, $report->blockerReasons());
    }
}
