<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\ClassifyServer;
use Lynomia\Modules\Infrastructure\Application\Actions\ClearForReimage;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterServer;
use Lynomia\Modules\Infrastructure\Application\Actions\RevokeReimageClearance;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ServerRegistration;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\ClassificationRefused;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SafetyRefusal;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A machine cannot be wiped by anybody who never looked at it.
 *
 * The ladder — do_not_touch, discovery_only, configuration_allowed,
 * reimage_allowed — exists so that somebody reads what is on the disks before
 * anybody destroys them. These tests walk it, and try to jump it.
 */
final class OnboardingAServerRefusesToSkipTheLookTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::factory()->create();
    }

    #[Test]
    public function a_registered_server_is_untouchable_whatever_was_asked_for(): void
    {
        $server = app(RegisterServer::class)->execute(new ServerRegistration(
            name: 'pve-1',
            environment: DeploymentEnvironment::Staging,
            managementAddress: 'fake://connected',
        ));

        $this->assertSame(SafetyClass::DoNotTouch, $server->safety_class);
        $this->assertFalse($server->allow_reimage);
        $this->assertFalse($server->isTouchable());

        $this->assertDatabaseHas('audit_log', [
            'action' => AuditAction::ServerRegistered->value,
        ]);
    }

    #[Test]
    public function even_a_read_is_refused_on_an_untouchable_machine(): void
    {
        $server = ManagedServer::factory()->create();

        // The least dangerous thing this module does, and it still asks. A
        // machine nobody has claimed is one whose owner has not agreed we may
        // open a socket to it.
        $this->expectException(SafetyRefusal::class);

        app(TestConnection::class)->forServer($server, 'fake');
    }

    #[Test]
    public function a_machine_cannot_go_straight_from_untouchable_to_wipeable(): void
    {
        $server = ManagedServer::factory()->create();

        $this->expectException(ClassificationRefused::class);

        app(ClassifyServer::class)->execute(
            $server,
            SafetyClass::ReimageAllowed,
            $this->operator(),
            'the customer left and we are rebuilding it',
            typedName: $server->name,
        );
    }

    #[Test]
    public function the_ladder_is_climbed_one_rung_at_a_time(): void
    {
        $server = ManagedServer::factory()->create(['name' => 'pve-ladder']);
        $operator = $this->operator();
        $classify = app(ClassifyServer::class);

        $server = $classify->execute($server, SafetyClass::DiscoveryOnly, $operator, 'read it before we touch it');
        $this->assertSame(SafetyClass::DiscoveryOnly, $server->safety_class);

        $server = $classify->execute($server, SafetyClass::ConfigurationAllowed, $operator, 'it is ours and it is empty');
        $this->assertSame(SafetyClass::ConfigurationAllowed, $server->safety_class);

        $server = $classify->execute(
            $server,
            SafetyClass::ReimageAllowed,
            $operator,
            'scheduled rebuild on Thursday',
            typedName: 'pve-ladder',
        );
        $this->assertSame(SafetyClass::ReimageAllowed, $server->safety_class);

        // Still not cleared. The class is the standing decision; the clearance
        // is the decision about today.
        $this->assertFalse($server->allow_reimage);
    }

    #[Test]
    public function reaching_the_destructive_rung_requires_typing_the_machines_name(): void
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::ConfigurationAllowed)
            ->create(['name' => 'pve-typed']);

        $this->expectException(ClassificationRefused::class);

        app(ClassifyServer::class)->execute(
            $server,
            SafetyClass::ReimageAllowed,
            $this->operator(),
            'rebuild',
            typedName: 'pve-typo',
        );
    }

    #[Test]
    public function a_classification_change_without_a_reason_is_refused(): void
    {
        $server = ManagedServer::factory()->create();

        $this->expectException(ClassificationRefused::class);

        app(ClassifyServer::class)->execute($server, SafetyClass::DiscoveryOnly, $this->operator(), '   ');
    }

    #[Test]
    public function lowering_the_class_takes_the_clearance_with_it(): void
    {
        $server = ManagedServer::factory()->clearedForReimage()->create();
        $this->assertTrue($server->allow_reimage);

        $server = app(ClassifyServer::class)->execute(
            $server,
            SafetyClass::ConfigurationAllowed,
            $this->operator(),
            'the rebuild is done',
        );

        // Not merely tidy: the database CHECK constraint would refuse the row
        // otherwise, and a refusal about a constraint is a worse message than
        // this never being possible.
        $this->assertFalse($server->allow_reimage);
        $this->assertSame(SafetyClass::ConfigurationAllowed, $server->safety_class);
    }

    #[Test]
    public function a_clearance_can_be_given_and_taken_back(): void
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::ReimageAllowed)
            ->create(['name' => 'pve-clear']);
        $operator = $this->operator();

        $server = app(ClearForReimage::class)->execute($server, $operator, 'Thursday rebuild', 'pve-clear');
        $this->assertTrue($server->allow_reimage);

        $server = app(RevokeReimageClearance::class)->execute($server, $operator);
        $this->assertFalse($server->allow_reimage);

        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ServerReimageCleared->value]);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ServerReimageClearanceRevoked->value]);
    }

    #[Test]
    public function a_clearance_cannot_be_given_to_a_machine_of_the_wrong_class(): void
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::ConfigurationAllowed)
            ->create(['name' => 'pve-wrong']);

        $this->expectException(ClassificationRefused::class);

        app(ClearForReimage::class)->execute($server, $this->operator(), 'rebuild', 'pve-wrong');
    }

    #[Test]
    public function the_database_refuses_a_clearance_the_class_does_not_permit(): void
    {
        // The application refuses this above. This proves the row cannot exist
        // even when written around the application — by a seeder, a fixture or
        // somebody's psql session.
        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->create();

        $this->expectException(QueryException::class);

        \DB::table('managed_servers')
            ->where('id', $server->getKey())
            ->update(['allow_reimage' => true]);
    }

    #[Test]
    public function a_discovery_only_machine_can_be_reached_and_still_not_changed(): void
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::DiscoveryOnly)
            ->create(['credential_reference_id' => CredentialReference::factory()->create()->getKey()]);

        $this->app->bind(SecretResolver::class, fn (): SecretResolver => new class implements SecretResolver
        {
            public function resolve(string $backend, string $reference): ?string
            {
                return 'a-secret-this-test-never-asserts-on';
            }
        });

        $test = app(TestConnection::class)->forServer($server, 'fake');

        $this->assertSame(ConnectionState::Connected, $test->result);
        $this->assertSame(ConnectionState::Connected, $server->fresh()->connection_state);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ConnectionTested->value]);
    }

    #[Test]
    public function a_machine_with_no_credential_reports_a_credentials_blocker_rather_than_an_error(): void
    {
        // The distinction the whole ConnectionState enum exists for. "We
        // reached it and have nothing to authenticate with" sends an operator
        // to the credential centre; "Error" sends them nowhere.
        $server = ManagedServer::factory()
            ->classified(SafetyClass::DiscoveryOnly)
            ->create();

        $test = app(TestConnection::class)->forServer($server, 'fake');

        $this->assertSame(ConnectionState::AuthFailed, $test->result);
        $this->assertSame(BlockerReason::Credentials, $test->result->blocker());

        // And the steps say how far it got, so nobody goes looking at the network.
        $this->assertSame('passed', $test->steps[0]['outcome']);
        $this->assertSame('tcp', $test->steps[0]['name']);
    }

    #[Test]
    public function a_credential_from_another_environment_is_never_even_resolved(): void
    {
        $server = ManagedServer::factory()
            ->classified(SafetyClass::DiscoveryOnly)
            ->inProduction()
            ->create([
                'credential_reference_id' => CredentialReference::factory()
                    ->forEnvironment(DeploymentEnvironment::Staging)
                    ->create()
                    ->getKey(),
            ]);

        $asked = false;
        $this->app->bind(SecretResolver::class, function () use (&$asked): SecretResolver {
            return new class($asked) implements SecretResolver
            {
                public function __construct(private bool &$asked) {}

                public function resolve(string $backend, string $reference): ?string
                {
                    $this->asked = true;

                    return 'should-never-be-reached';
                }
            };
        });

        $test = app(TestConnection::class)->forServer($server, 'fake');

        // Refusing to resolve is stronger than refusing to use what was
        // resolved: a staging token never enters memory, so nothing downstream
        // has the chance to send it.
        $this->assertFalse($asked, 'A staging credential was resolved for a production machine.');
        $this->assertSame(ConnectionState::AuthFailed, $test->result);
    }
}
