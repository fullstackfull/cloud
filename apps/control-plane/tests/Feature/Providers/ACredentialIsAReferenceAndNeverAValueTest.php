<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The credential centre stores where a secret is and never what it is.
 *
 * ---------------------------------------------------------------------------
 * The three ways this could leak, and the test for each
 * ---------------------------------------------------------------------------
 *
 * Out: a response carrying the reference or the value. Every response body in
 * this file is searched for both strings.
 *
 * In: a client posting the value into the reference field, or under a field
 * of its own. The first is refused by shape, the second by name — and the
 * refusal says "rotate it", because a secret that has been in a request body
 * has been in a log.
 *
 * Sideways: a staging credential attached to a production provider. Refused
 * at attachment, not only at use, so two independent guards have to fail
 * before a token crosses an environment.
 */
final class ACredentialIsAReferenceAndNeverAValueTest extends TestCase
{
    use RefreshDatabase;

    private const string PRESENT = 'LYNOMIA_TEST_PRESENT_SECRET';

    private const string ABSENT = 'LYNOMIA_TEST_ABSENT_SECRET';

    private const string VALUE = 'sk-live-this-must-never-appear-anywhere';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        putenv(self::PRESENT.'='.self::VALUE);
        putenv(self::ABSENT);
    }

    protected function tearDown(): void
    {
        putenv(self::PRESENT);

        parent::tearDown();
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(User $operator, array $overrides = []): TestResponse
    {
        return $this->actingAs($operator)->postJson('/api/admin/credentials', array_merge([
            'name' => 'registrar-'.uniqid(),
            'purpose' => 'Registrar API',
            'environment' => DeploymentEnvironment::Staging->value,
            'backend_reference' => self::PRESENT,
            'masked_hint' => 'C3D4',
        ], $overrides));
    }

    private function assertCarriesNoSecret(string $body): void
    {
        $this->assertStringNotContainsString(self::VALUE, $body);
        $this->assertStringNotContainsString(self::PRESENT, $body);
        $this->assertStringNotContainsString(self::ABSENT, $body);
        $this->assertStringNotContainsString('backend_reference', $body);
    }

    /* ---------------------------------------------------------------------
     | Recording
     */

    #[Test]
    public function a_reference_the_controller_can_see_is_recorded_as_configured_and_nothing_else_is_shown(): void
    {
        $response = $this->record($this->operator());

        $response->assertCreated();
        $response->assertJsonPath('data.state', CredentialState::Configured->value);
        $response->assertJsonPath('data.present', true);
        $response->assertJsonPath('data.masked_hint', 'C3D4');

        $this->assertCarriesNoSecret($response->getContent());
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::CredentialRecorded->value]);
    }

    #[Test]
    public function a_reference_the_controller_cannot_see_yet_is_recorded_as_missing_not_refused(): void
    {
        // Declaring a credential before the controller has been given it is
        // an ordinary onboarding step, and the state is the honest one.
        $response = $this->record($this->operator(), ['backend_reference' => self::ABSENT]);

        $response->assertCreated();
        $response->assertJsonPath('data.state', CredentialState::Missing->value);
        $response->assertJsonPath('data.present', false);
    }

    #[Test]
    public function a_reference_shaped_like_a_value_is_refused_and_the_operator_is_told_to_rotate(): void
    {
        foreach ([self::VALUE, 'hunter2', 'ghp_abcDEF123', 'lower_case_name', 'HAS SPACE', 'A'] as $notAName) {
            $response = $this->record($this->operator(), ['backend_reference' => $notAName]);

            $response->assertConflict();
            $this->assertStringContainsString('rotate it now', $response->json('error.message'));

            // A one-letter input is trivially a substring of any sentence;
            // the echo check means something for anything secret-sized.
            if (strlen($notAName) > 8) {
                $this->assertStringNotContainsString($notAName, $response->getContent(), 'The refused input must not be echoed.');
            }
        }

        $this->assertDatabaseCount('credential_references', 0);
    }

    #[Test]
    public function a_secret_sent_under_its_own_field_is_refused_by_name_and_never_echoed(): void
    {
        foreach (['secret', 'password', 'value', 'token', 'api_key'] as $field) {
            $response = $this->record($this->operator(), [$field => self::VALUE]);

            $response->assertUnprocessable();
            $this->assertStringContainsString($field, $response->getContent());
            $this->assertStringContainsString('rotate it now', $response->getContent());
            $this->assertStringNotContainsString(self::VALUE, $response->getContent());
        }

        $this->assertDatabaseCount('credential_references', 0);
    }

    #[Test]
    public function only_the_known_backend_is_accepted(): void
    {
        $this->record($this->operator(), ['backend' => 'vault'])->assertUnprocessable();
    }

    #[Test]
    public function the_list_never_carries_a_reference_and_puts_the_missing_ones_first(): void
    {
        $operator = $this->operator();
        $this->record($operator, ['name' => 'zzz-configured']);
        $this->record($operator, ['name' => 'aaa-missing', 'backend_reference' => self::ABSENT]);

        $response = $this->actingAs($operator)->getJson('/api/admin/credentials');

        $response->assertOk();
        $this->assertSame('aaa-missing', $response->json('data.0.name'));
        $this->assertSame(CredentialState::Missing->value, $response->json('data.0.state'));
        $this->assertCarriesNoSecret($response->getContent());
    }

    /* ---------------------------------------------------------------------
     | Attaching
     */

    private function provider(DeploymentEnvironment $environment = DeploymentEnvironment::Staging): ProviderInstance
    {
        return ProviderInstance::factory()->create([
            'environment' => $environment,
            'endpoint' => 'fake://connected',
            'credential_reference_id' => null,
        ]);
    }

    private function credential(
        DeploymentEnvironment $environment = DeploymentEnvironment::Staging,
        CredentialState $state = CredentialState::Configured,
    ): CredentialReference {
        return CredentialReference::factory()->create([
            'environment' => $environment,
            'state' => $state,
            'backend_reference' => self::PRESENT,
        ]);
    }

    #[Test]
    public function attaching_a_credential_moves_the_providers_blocker_past_credentials(): void
    {
        $operator = $this->operator();
        $provider = $this->provider();
        $credential = $this->credential();

        $this->actingAs($operator)->postJson("/api/admin/providers/{$provider->id}/assess")
            ->assertJsonPath('data.readiness.blocker', BlockerReason::Credentials->value);

        $response = $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/credential", ['credential_id' => $credential->id]);

        $response->assertOk();
        $response->assertJsonPath('data.credential.credential_name', $credential->name);
        // Configured is not proven: the next blocker is still credentials until a test says otherwise.
        $response->assertJsonPath('data.readiness.blocker', BlockerReason::Credentials->value);
        $this->assertCarriesNoSecret($response->getContent());

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::CredentialAttached->value]);
    }

    #[Test]
    public function a_credential_from_another_environment_cannot_be_attached_even_though_use_would_also_refuse_it(): void
    {
        $operator = $this->operator();
        $provider = $this->provider(DeploymentEnvironment::Production);
        $credential = $this->credential(DeploymentEnvironment::Staging, CredentialState::Valid);

        $response = $this->actingAs($operator)
            ->postJson("/api/admin/providers/{$provider->id}/credential", ['credential_id' => $credential->id]);

        $response->assertConflict();
        $this->assertStringContainsString('staging credential and this is production', $response->json('error.message'));
        $this->assertNull($provider->fresh()->credential_reference_id);
    }

    #[Test]
    public function a_revoked_credential_cannot_be_attached_to_anything(): void
    {
        $operator = $this->operator();
        $credential = $this->credential(state: CredentialState::Revoked);

        $this->actingAs($operator)
            ->postJson('/api/admin/providers/'.$this->provider()->id.'/credential', ['credential_id' => $credential->id])
            ->assertConflict();

        $server = ManagedServer::factory()->create(['environment' => DeploymentEnvironment::Staging]);

        $this->actingAs($operator)
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/credential", ['credential_id' => $credential->id])
            ->assertConflict();
    }

    #[Test]
    public function detaching_puts_the_credentials_blocker_back_and_is_recorded(): void
    {
        $operator = $this->operator();
        $provider = $this->provider();
        $provider->forceFill(['credential_reference_id' => $this->credential(state: CredentialState::Valid)->getKey()])->save();

        $response = $this->actingAs($operator)->deleteJson("/api/admin/providers/{$provider->id}/credential");

        $response->assertOk();
        $response->assertJsonPath('data.credential', null);
        $response->assertJsonPath('data.readiness.blocker', BlockerReason::Credentials->value);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::CredentialDetached->value]);
    }

    #[Test]
    public function a_machine_nobody_may_touch_can_still_be_given_a_credential_and_still_cannot_be_reached(): void
    {
        /*
         * Attaching opens no socket, so the safety gate does not apply to it.
         * The gate applies to the connection test, and this proves it still
         * does after a credential is present — the credential does not become
         * a way round the classification.
         */
        $operator = $this->operator();
        $server = ManagedServer::factory()->create([
            'environment' => DeploymentEnvironment::Staging,
            'safety_class' => SafetyClass::DoNotTouch,
        ]);
        $credential = $this->credential();

        $this->actingAs($operator)
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/credential", ['credential_id' => $credential->id])
            ->assertOk()
            ->assertJsonPath('data.connection.credential.name', $credential->name);

        $this->actingAs($operator)
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/connection-test", ['driver' => 'fake'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'safety_refused');
    }

    #[Test]
    public function a_machine_in_another_environment_refuses_the_credential(): void
    {
        $server = ManagedServer::factory()->create(['environment' => DeploymentEnvironment::Production]);

        $this->actingAs($this->operator())
            ->postJson("/api/admin/infrastructure/servers/{$server->id}/credential", ['credential_id' => $this->credential()->id])
            ->assertConflict();
    }

    /* ---------------------------------------------------------------------
     | Revoking and rotating
     */

    private function servingProvider(CredentialReference $credential, ProviderCategory $category = ProviderCategory::Dns): ProviderInstance
    {
        // One enabled provider per category and environment is a database
        // rule, so a second serving provider on the same credential has to be
        // a different kind of provider.
        $provider = ProviderInstance::factory()->create([
            'category' => $category,
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::Connected,
            'readiness' => ReadinessState::ReadyForProduction,
            'state' => ProviderState::Enabled,
            'credential_reference_id' => $credential->getKey(),
        ]);
        ProviderCapability::factory()->for($provider, 'provider')->create();

        return $provider;
    }

    #[Test]
    public function revoking_blocks_every_provider_using_the_credential_without_switching_any_off(): void
    {
        $operator = $this->operator();
        $credential = $this->credential(state: CredentialState::Valid);
        $first = $this->servingProvider($credential);
        $second = $this->servingProvider($credential, ProviderCategory::Registrar);

        $response = $this->actingAs($operator)
            ->postJson("/api/admin/credentials/{$credential->id}/revoke", ['reason' => 'Key seen in a screenshot.']);

        $response->assertOk();
        $response->assertJsonPath('data.state', CredentialState::Revoked->value);
        $response->assertJsonPath('data.revoked_reason', 'Key seen in a screenshot.');

        foreach ([$first, $second] as $provider) {
            $provider->refresh();
            $this->assertSame(BlockerReason::Credentials, $provider->blocker);
            $this->assertSame(ReadinessState::NotReady, $provider->readiness);
            // Still Enabled. Switching a provider off is an operator's call;
            // an automatic shutdown on a key event turns a rotation into a
            // halt in sales.
            $this->assertSame(ProviderState::Enabled, $provider->state);
        }

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::CredentialRevoked->value]);
    }

    #[Test]
    public function revoking_needs_a_reason_and_happens_once(): void
    {
        $operator = $this->operator();
        $credential = $this->credential();

        $this->actingAs($operator)->postJson("/api/admin/credentials/{$credential->id}/revoke", [])->assertUnprocessable();
        $this->actingAs($operator)->postJson("/api/admin/credentials/{$credential->id}/revoke", ['reason' => 'Compromised.'])->assertOk();
        $this->actingAs($operator)->postJson("/api/admin/credentials/{$credential->id}/revoke", ['reason' => 'Again.'])->assertConflict();
    }

    #[Test]
    public function marking_a_credential_rotated_forgets_what_was_proven_about_the_old_value(): void
    {
        $operator = $this->operator();
        $credential = $this->credential(state: CredentialState::Valid);
        $credential->forceFill(['last_tested_at' => now()])->save();
        $provider = $this->servingProvider($credential);

        $response = $this->actingAs($operator)->postJson("/api/admin/credentials/{$credential->id}/rotated");

        $response->assertOk();
        $response->assertJsonPath('data.state', CredentialState::Configured->value);
        $this->assertNull($credential->fresh()->last_tested_at);
        $this->assertNotNull($credential->fresh()->rotated_at);

        // A provider that was ready on the strength of the old secret is not
        // ready on the strength of a new one nobody has tried.
        $this->assertSame(BlockerReason::Credentials, $provider->fresh()->blocker);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::CredentialRotated->value]);
    }

    #[Test]
    public function a_rotation_the_controller_has_not_received_yet_is_missing_not_configured(): void
    {
        $credential = CredentialReference::factory()->create([
            'state' => CredentialState::Valid,
            'backend_reference' => self::ABSENT,
        ]);

        $this->actingAs($this->operator())
            ->postJson("/api/admin/credentials/{$credential->id}/rotated")
            ->assertOk()
            ->assertJsonPath('data.state', CredentialState::Missing->value);
    }

    #[Test]
    public function a_revoked_credential_cannot_be_rotated_back_into_service(): void
    {
        $credential = $this->credential(state: CredentialState::Revoked);

        $this->actingAs($this->operator())
            ->postJson("/api/admin/credentials/{$credential->id}/rotated")
            ->assertConflict();
    }

    /* ---------------------------------------------------------------------
     | Who may
     */

    #[Test]
    public function a_customer_cannot_reach_any_of_this(): void
    {
        $customer = User::factory()->create();
        $credential = $this->credential();
        $provider = $this->provider();

        $this->actingAs($customer)->getJson('/api/admin/credentials')->assertForbidden();
        $this->actingAs($customer)->postJson('/api/admin/credentials')->assertForbidden();
        $this->actingAs($customer)->postJson("/api/admin/credentials/{$credential->id}/revoke")->assertForbidden();
        $this->actingAs($customer)->postJson("/api/admin/providers/{$provider->id}/credential")->assertForbidden();
    }

    #[Test]
    public function reading_the_estate_does_not_mean_managing_its_secrets(): void
    {
        $support = $this->operator(Role::Noc);
        $credential = $this->credential();

        $this->actingAs($support)->getJson('/api/admin/credentials')->assertOk();
        $this->actingAs($support)->postJson('/api/admin/credentials', [])->assertForbidden();
        $this->actingAs($support)->postJson("/api/admin/credentials/{$credential->id}/revoke", ['reason' => 'x'])->assertForbidden();
    }
}
