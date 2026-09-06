<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Dedicated\Application\Actions\AuthorisePxeBoot;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PxeAuthorisationRefusedException;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Granting one machine permission to erase itself.
 *
 * Every assertion here is about a customer's data. A PXE boot destroys
 * whatever is on the disks, so the guards are not validation niceties: each
 * one is a server that would otherwise have been wiped by a job that had no
 * business touching it.
 */
final class AuthorisePxeBootTest extends TestCase
{
    use RefreshDatabase;

    private const string MAC = 'aa:bb:cc:dd:ee:01';

    private DedicatedServer $server;

    private BmcEndpoint $endpoint;

    private OsInstallProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        // The fake is installed by configuration, the way the platform runs in
        // the test environment, rather than by reaching into the factory.
        config()->set('dedicated.provider', 'fake');

        $this->server = DedicatedServer::factory()
            ->status(DedicatedServerStatus::Provisioning)
            ->create();

        $this->endpoint = BmcEndpoint::factory()->forServer($this->server)->create();

        ServerComponent::factory()->forServer($this->server)->nic(self::MAC)->create();

        $this->profile = OsInstallProfile::factory()->create();
    }

    #[Test]
    public function it_records_the_decision_and_arms_a_one_time_pxe_boot(): void
    {
        $user = User::factory()->create();

        $authorisation = $this->authorise(
            reason: 'Customer ordered a rebuild on Ubuntu 24.04',
            authorisedByUserId: (string) $user->getKey(),
        );

        $this->assertSame(PxeAuthorisationStatus::Pending, $authorisation->status);
        $this->assertSame(self::MAC, $authorisation->mac_address);
        $this->assertSame('Customer ordered a rebuild on Ubuntu 24.04', $authorisation->authorisation_reason);
        $this->assertSame((string) $user->getKey(), $authorisation->authorised_by_user_id);

        // "Once", stated in the record as well as sent to the controller. A
        // persistent boot-order change would reinstall the machine on the next
        // power cut.
        $this->assertSame('Once', $authorisation->rendered_config['bmc']['boot_source_override_enabled']);
        $this->assertSame('Pxe', $authorisation->rendered_config['bmc']['boot_source_override_target']);

        $provider = app(DedicatedProviderFactory::class)->for($this->endpoint);
        $this->assertInstanceOf(FakeDedicatedProvider::class, $provider);
    }

    #[Test]
    public function pxe_cannot_be_authorised_for_a_server_that_is_active(): void
    {
        $this->server->forceFill(['status' => DedicatedServerStatus::Active])->save();

        try {
            $this->authorise();

            $this->fail('A running customer server was authorised for reinstallation.');
        } catch (PxeAuthorisationRefusedException $e) {
            $this->assertSame(DedicatedServerStatus::Active->value, $e->context()['status']);
            $this->assertSame(DedicatedServerStatus::Provisioning->value, $e->context()['required_status']);
        }

        // And nothing was recorded, because nothing was granted.
        $this->assertSame(0, PxeBootAuthorisation::query()->count());
    }

    #[Test]
    public function pxe_cannot_be_authorised_in_any_state_other_than_provisioning(): void
    {
        foreach ([
            DedicatedServerStatus::Available,
            DedicatedServerStatus::Reserved,
            DedicatedServerStatus::Active,
            DedicatedServerStatus::Maintenance,
            DedicatedServerStatus::Failed,
            DedicatedServerStatus::Retired,
        ] as $status) {
            $this->server->forceFill(['status' => $status])->save();

            try {
                $this->authorise();

                $this->fail(sprintf('A "%s" server was authorised for reinstallation.', $status->value));
            } catch (PxeAuthorisationRefusedException) {
                // Expected for every one of them.
            }
        }

        $this->assertSame(0, PxeBootAuthorisation::query()->count());
    }

    #[Test]
    public function an_authorisation_expires_and_cannot_be_used_afterwards(): void
    {
        config()->set('dedicated.pxe.authorisation_ttl_minutes', 30);

        $authorisation = $this->authorise();

        $this->assertTrue($authorisation->isUsable());

        // The machine sat unbooted past its window — a cabling problem, a job
        // that never dispatched, an engineer who went home.
        $this->travel(31)->minutes();

        $authorisation->refresh();

        $this->assertTrue($authorisation->hasExpired());
        $this->assertFalse($authorisation->isUsable());

        try {
            // The boot server asking, at the moment a machine says it wants to
            // install. This is the last place the platform can refuse.
            $authorisation->markBooted();

            $this->fail('A lapsed authorisation still permitted a network boot.');
        } catch (PxeAuthorisationRefusedException $e) {
            $this->assertSame((string) $authorisation->getKey(), $e->context()['pxe_boot_authorisation_id']);
        }

        $authorisation->refresh();

        // The refusal did not quietly consume it either.
        $this->assertSame(PxeAuthorisationStatus::Pending, $authorisation->status);
        $this->assertNull($authorisation->booted_at);
    }

    #[Test]
    public function a_spent_authorisation_cannot_be_used_a_second_time(): void
    {
        $authorisation = $this->authorise();

        $authorisation->markBooted();
        $authorisation->markCompleted();

        $this->expectException(PxeAuthorisationRefusedException::class);

        // A completed install must not be a standing invitation to run
        // another one.
        $authorisation->markBooted();
    }

    #[Test]
    public function the_authorisation_window_is_short_by_default(): void
    {
        config()->set('dedicated.pxe.authorisation_ttl_minutes', 60);

        $authorisation = $this->authorise();

        // The permission is a decision with an expiry, not a standing
        // configuration; an hour is long enough to install and short enough
        // that a forgotten grant lapses on its own.
        $this->assertTrue($authorisation->expires_at->lessThanOrEqualTo(now()->addHour()->addSecond()));
        $this->assertTrue($authorisation->expires_at->greaterThan(now()));
    }

    #[Test]
    public function a_machine_with_no_recorded_mac_is_refused_rather_than_wildcarded(): void
    {
        ServerComponent::query()->delete();

        try {
            $this->authorise();

            $this->fail('An authorisation was granted with no machine named.');
        } catch (PxeAuthorisationRefusedException $e) {
            /*
             * DHCP and PXE on the provisioning VLAN answer whoever asks. An
             * authorisation with no MAC is an authorisation for every machine
             * on that VLAN, including ones mid-install for other customers.
             */
            $this->assertSame((string) $this->server->getKey(), $e->context()['dedicated_server_id']);
        }
    }

    #[Test]
    public function an_authorisation_with_no_recorded_reason_is_refused(): void
    {
        // When a customer's server is found reinstalled, the reason is the
        // difference between an answer and a shrug.
        $this->expectException(PxeAuthorisationRefusedException::class);

        $this->authorise(reason: '   ');
    }

    #[Test]
    public function a_template_with_an_unfilled_placeholder_is_refused_before_anything_is_armed(): void
    {
        try {
            // The profile needs an address and a gateway; only a hostname is
            // supplied.
            $this->authorise(variables: ['hostname' => 'srv-01']);

            $this->fail('An install profile with holes in it was accepted.');
        } catch (InstallProfileNotRenderableException $e) {
            $this->assertStringContainsString('ipv4_address', $e->getMessage());
        }

        /*
         * An answer file containing a literal "{{ ipv4_address }}" does not
         * fail where the mistake was made: the installer runs, partitions the
         * disks, and produces a machine that is wrong in a way only
         * discoverable after whatever was on those disks is gone.
         */
        $this->assertSame(0, PxeBootAuthorisation::query()->count());
    }

    #[Test]
    public function a_withdrawn_profile_stops_being_used_by_jobs_queued_before_it_was_withdrawn(): void
    {
        $this->profile->forceFill(['is_active' => false])->save();

        $this->expectException(InstallProfileNotRenderableException::class);

        $this->authorise();
    }

    #[Test]
    public function a_controller_that_refuses_leaves_the_grant_revoked_rather_than_standing(): void
    {
        // A refusal spoken out loud means nothing was armed. Leaving the
        // permission pending would let a later, unrelated network boot consume
        // it.
        $this->endpoint->forceFill([
            'address' => FakeDedicatedProvider::addressWith('192.0.2.90', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER),
        ])->save();

        try {
            $this->authorise();

            $this->fail('A refused PXE arm was reported as success.');
        } catch (DedicatedProviderException $e) {
            $this->assertFalse($e->isIndeterminate());
        }

        $authorisation = PxeBootAuthorisation::query()->sole();

        $this->assertSame(PxeAuthorisationStatus::Revoked, $authorisation->status);
        $this->assertFalse($authorisation->isUsable());
        // Withdrawn, not deleted: a machine that was told to boot from the
        // network and then told not to is something an operator investigating a
        // surprise reinstall needs to see.
        $this->assertSame('Initial provisioning', $authorisation->authorisation_reason);
    }

    #[Test]
    public function a_controller_that_stops_answering_leaves_the_grant_standing(): void
    {
        $this->endpoint->forceFill([
            'address' => FakeDedicatedProvider::addressWith('192.0.2.91', FakeDedicatedProvider::TIMEOUT_MARKER),
        ])->save();

        try {
            $this->authorise();

            $this->fail('A timed-out PXE arm was reported as success.');
        } catch (DedicatedProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
        }

        $authorisation = PxeBootAuthorisation::query()->sole();

        /*
         * The controller may well have armed the override. Revoking would tell
         * the boot server to refuse a machine that is about to ask to install,
         * and the install would hang half-done instead of completing.
         */
        $this->assertSame(PxeAuthorisationStatus::Pending, $authorisation->status);
        $this->assertTrue($authorisation->isUsable());
    }

    #[Test]
    public function the_best_available_controller_is_used(): void
    {
        // The machine also has an IPMI endpoint. Redfish wins, which is how a
        // fleet migrates off IPMI one machine at a time without a caller
        // learning what protocol it is speaking.
        BmcEndpoint::factory()->forServer($this->server)->protocol(BmcProtocol::Ipmi)->create();

        $preferred = $this->server->fresh()?->preferredBmcEndpoint();

        $this->assertNotNull($preferred);
        $this->assertSame(BmcProtocol::Redfish, $preferred->protocol);
    }

    /**
     * @param  array<string, scalar|null>|null  $variables
     */
    private function authorise(
        string $reason = 'Initial provisioning',
        ?string $authorisedByUserId = null,
        ?array $variables = null,
    ): PxeBootAuthorisation {
        return app(AuthorisePxeBoot::class)->execute(
            server: $this->server->fresh() ?? $this->server,
            profile: $this->profile->fresh() ?? $this->profile,
            reason: $reason,
            authorisedByUserId: $authorisedByUserId,
            variables: $variables ?? [
                'hostname' => 'srv-01',
                'ipv4_address' => '198.51.100.10',
                'ipv4_prefix_length' => 29,
                'ipv4_gateway' => '198.51.100.9',
            ],
        );
    }
}
