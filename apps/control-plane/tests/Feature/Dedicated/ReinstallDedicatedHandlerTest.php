<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Application\Handlers\ReinstallDedicatedHandler;
use Lynomia\Modules\Dedicated\Domain\Contracts\HostReachability;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Dedicated\Infrastructure\Reachability\UnreachableHostProbe;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rebuilding a machine the customer already owns.
 *
 * `RequestDedicatedReinstall` created jobs of this kind for the whole of Phase
 * 29 and no handler was registered for them: every request answered 202 and
 * died at the worker. The gap was documented rather than hidden, which was the
 * right call while the path from a running customer server to a rebuilt one
 * had not been designed — and this file is that design, proved.
 *
 * The assertions are grouped around the two questions an operator actually
 * asks about a physical rebuild: **did the installer start**, and **is the
 * machine still this customer's**. Everything else is detail.
 */
final class ReinstallDedicatedHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Datacenter $datacenter;

    private Customer $customer;

    private Service $service;

    private OsInstallProfile $osProfile;

    private DedicatedServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dedicated.provider', 'fake');

        // One factory for the whole test, as a worker holds it: without the
        // singleton a test that pre-powers a machine would be talking to a
        // different fake than the handler is.
        $this->app->singleton(DedicatedProviderFactory::class);

        // The machine answers as soon as it is asked, unless a test says
        // otherwise. The waiting is not what these tests are about.
        $this->app->bind(HostReachability::class, AlwaysReachableHost::class);

        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $this->datacenter = Datacenter::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->osProfile = OsInstallProfile::factory()->create();

        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'dedicated',
        ]);

        $this->server = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->create([
                'status' => DedicatedServerStatus::Active,
                'customer_id' => $this->customer->id,
                'service_id' => $this->service->getKey(),
                'os_install_profile_id' => $this->osProfile->getKey(),
                'power_state' => PowerState::On,
            ]);

        BmcEndpoint::factory()->forServer($this->server)->at('192.0.2.10')->create();
        ServerComponent::factory()->forServer($this->server)->nic('aa:bb:cc:dd:ee:11')->create();

        $this->giveTheServerAnAddress();
    }

    #[Test]
    public function a_rebuild_installs_the_machine_and_gives_it_back(): void
    {
        $this->completeTheInstallOnFirstPoll();

        $result = $this->reinstall();

        $this->assertTrue($result->successful, (string) $result->errorMessage);

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::Completed, $operation->state);
        $this->assertNotNull($operation->completed_at);

        // The machine came back to its owner, not to stock.
        $server = $this->server->fresh();
        $this->assertSame(DedicatedServerStatus::Active, $server?->status);
        $this->assertSame($this->customer->id, $server->customer_id);
        $this->assertSame($this->service->getKey(), $server->service_id);
    }

    #[Test]
    public function the_machine_keeps_its_identity_and_its_address(): void
    {
        /*
         * The invariant the design exists for. A rebuild that re-reserved a
         * machine or re-allocated an address would satisfy every other
         * assertion here and break this one — and the customer would find out
         * when their DNS stopped resolving to their server.
         */
        $before = IpAssignment::query()->sole();

        $this->completeTheInstallOnFirstPoll();
        $this->reinstall();

        $after = IpAssignment::query()->sole();

        $this->assertSame($before->getKey(), $after->getKey(), 'The rebuild replaced the address assignment.');
        $this->assertNull($after->released_at);
        $this->assertSame($this->server->getKey(), $after->assignable_id);

        // And the machine is the same machine.
        $this->assertSame($this->server->serial, $this->server->fresh()?->serial);
        $this->assertSame(1, DedicatedServer::query()->count());
    }

    #[Test]
    public function the_installer_is_rendered_with_the_address_the_machine_already_has(): void
    {
        // Anything else brings the machine up on an address IPAM never
        // allocated — a conflict at best, another tenant's address at worst.
        $this->completeTheInstallOnFirstPoll();
        $this->reinstall();

        $authorisation = PxeBootAuthorisation::query()->latest('id')->firstOrFail();

        $this->assertSame('198.51.100.10', $authorisation->rendered_config['variables']['ipv4_address'] ?? null);
    }

    #[Test]
    public function the_operation_records_the_moment_the_disks_went(): void
    {
        $this->completeTheInstallOnFirstPoll();
        $this->reinstall();

        $operation = DedicatedReinstall::query()->sole();

        $this->assertNotNull($operation->destructive_started_at);
        $this->assertTrue($operation->destroyedData());
        $this->assertNotNull($operation->pxe_boot_authorisation_id);
        $this->assertNotNull($operation->bmc_endpoint_id);
        $this->assertContains(
            $operation->power_operation,
            ['reset', 'power_on'],
            'The operation does not record how the machine was made to boot.',
        );
    }

    #[Test]
    public function a_running_machine_is_reset_rather_than_powered_on(): void
    {
        /*
         * The subtlety the provisioning handler documents and this one
         * inherits: the test is "is it definitely OFF", not "is it definitely
         * ON". A power-on sent to a machine that is already running does
         * nothing, never consumes the one-time override, and leaves it armed
         * for some later unrelated reboot to pick up — reinstalling the
         * machine then, with nobody watching.
         */
        $controller = $this->swapController(new RecordingDedicatedProvider(reportedState: PowerState::On));

        $this->completeTheInstallOnFirstPoll();
        $this->reinstall();

        $this->assertContains('reset', $controller->calls);
        $this->assertNotContains('power_on', $controller->calls);
    }

    #[Test]
    public function a_machine_with_no_controller_is_refused_before_anything_is_armed(): void
    {
        /*
         * Its own ending rather than a generic failure: "we could not reach
         * the controller" sends an operator to a cable, and "the install went
         * wrong" sends them to a disk. Nothing has been armed, so the
         * customer's server is exactly as they left it.
         */
        BmcEndpoint::query()->where('dedicated_server_id', $this->server->getKey())->delete();

        $result = $this->reinstall();

        $this->assertFalse($result->successful);

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::HardwareUnavailable, $operation->state);
        $this->assertFalse($operation->destroyedData());
        $this->assertNull($operation->destructive_started_at);

        // And the machine was never moved out of service.
        $this->assertSame(DedicatedServerStatus::Active, $this->server->fresh()?->status);
        $this->assertSame(0, PxeBootAuthorisation::query()->count());
    }

    #[Test]
    public function a_withdrawn_install_profile_is_refused_rather_than_substituted(): void
    {
        $this->osProfile->update(['is_active' => false]);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame('dedicated.install_profile_unavailable', $result->errorCode);

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::Failed, $operation->state);
        $this->assertFalse($operation->destroyedData());
        $this->assertSame(DedicatedServerStatus::Active, $this->server->fresh()?->status);
    }

    #[Test]
    public function a_controller_that_refuses_the_power_cycle_leaves_no_armed_override(): void
    {
        /*
         * The ugliest case in the module. The override is armed and the
         * machine did not boot — so if it is left armed, this machine erases
         * itself at its next reboot, whenever that is and whoever causes it.
         */
        $this->refuseAfterArming();

        $result = $this->reinstall();

        $this->assertFalse($result->successful);

        $authorisation = PxeBootAuthorisation::query()->sole();
        $this->assertSame(
            PxeAuthorisationStatus::Revoked,
            $authorisation->status,
            'A boot override was left armed on a machine that did not boot.',
        );

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::HardwareUnavailable, $operation->state);

        /*
         * And the destructive stamp is retracted. It is written before the
         * power cycle is attempted — pessimistically, so a worker that dies
         * mid-call leaves a record saying the disks may be gone — and this is
         * the one situation with evidence strong enough to take it back: the
         * controller itself said it would not boot the machine.
         */
        $this->assertFalse($operation->destroyedData());
        $this->assertNull($operation->destructive_started_at);

        // Back on the customer's dashboard as a working machine, because it is
        // one: nothing was erased.
        $this->assertSame(DedicatedServerStatus::Active, $this->server->fresh()?->status);
    }

    #[Test]
    public function a_controller_that_goes_quiet_during_the_power_cycle_is_never_retried(): void
    {
        /*
         * The most important assertion in this file. The reset may have
         * happened; the machine may be erasing itself right now. A retry
         * resets it a second time, mid-install.
         */
        $this->timeOutAfterArming();

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Timeout, $result->failureClass);
        $this->assertFalse($result->failureClass->isAutomaticallyRetryable());
        $this->assertTrue($result->failureClass->requiresReview());

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::Indeterminate, $operation->state);
        $this->assertTrue($operation->destroyedData());
        $this->assertTrue($operation->state->needsAttention());

        // Everything needed to ask the hardware afterwards.
        $this->assertNotNull($operation->pxe_boot_authorisation_id);
        $this->assertNotNull($operation->bmc_endpoint_id);
        $this->assertSame((string) $this->service->getKey(), $operation->service_id);

        // The machine stays in `reinstalling`. Returning it to active would
        // put a machine that may be mid-erase back on the dashboard as ready.
        $this->assertSame(DedicatedServerStatus::Reinstalling, $this->server->fresh()?->status);
    }

    #[Test]
    public function an_installer_that_never_reports_back_is_a_timeout_not_a_failure(): void
    {
        // The install was started and the platform stopped waiting. The disks
        // are gone either way, and the machine may still be installing.
        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Timeout, $result->failureClass);

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::ProvisioningTimeout, $operation->state);
        $this->assertTrue($operation->destroyedData());

        // Not active: whatever is on those disks now is not what the customer
        // had, so it does not go back on their dashboard as though nothing
        // happened.
        $this->assertSame(DedicatedServerStatus::Maintenance, $this->server->fresh()?->status);
    }

    #[Test]
    public function an_installer_that_reports_failure_needs_a_person(): void
    {
        $this->failTheInstallOnFirstPoll();

        $result = $this->reinstall();

        $this->assertFalse($result->successful);

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::NeedsReview, $operation->state);
        $this->assertTrue($operation->destroyedData());
        $this->assertSame(DedicatedServerStatus::Maintenance, $this->server->fresh()?->status);
    }

    #[Test]
    public function a_machine_that_installs_and_does_not_answer_is_not_called_ready(): void
    {
        /*
         * The installer said it finished and nothing is listening. Telling the
         * customer their server is ready is the one answer that is certainly
         * wrong — and calling it a failed rebuild would be wrong too, because
         * the rebuild happened.
         */
        $this->app->bind(HostReachability::class, UnreachableHostProbe::class);
        config()->set('dedicated.reinstall.reachability_attempts', 2);

        $this->completeTheInstallOnFirstPoll();

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame('dedicated.reinstall_unverified', $result->errorCode);

        $operation = DedicatedReinstall::query()->sole();
        $this->assertSame(DedicatedReinstallState::NeedsReview, $operation->state);
        $this->assertSame(DedicatedServerStatus::Maintenance, $this->server->fresh()?->status);
    }

    #[Test]
    public function a_redelivered_job_does_not_erase_the_machine_twice(): void
    {
        /*
         * A queue that delivers a message twice must not cost a customer their
         * data twice. The second delivery finds a finished operation and
         * answers without touching the controller.
         */
        $this->completeTheInstallOnFirstPoll();

        $job = $this->job();

        $first = $this->handler()->execute($job);
        $this->assertTrue($first->successful);

        $second = $this->handler()->execute($job->fresh());

        $this->assertTrue($second->successful);
        $this->assertTrue($second->metadata['replayed'] ?? false);

        $this->assertCount(1, DedicatedReinstall::query()->get());
        $this->assertSame(1, PxeBootAuthorisation::query()->count(), 'A redelivered job armed a second boot override.');
    }

    #[Test]
    public function a_server_that_no_longer_exists_is_a_permanent_failure(): void
    {
        $job = $this->job();
        $this->server->delete();

        $result = $this->handler()->execute($job);

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('dedicated.unknown_server', $result->errorCode);
    }

    #[Test]
    public function the_rebuilt_machine_keeps_the_hostname_it_was_installed_with(): void
    {
        // A rebuild that renamed the server would break the DNS, the
        // monitoring and the runbooks the customer built around it.
        PxeBootAuthorisation::factory()->create([
            'dedicated_server_id' => $this->server->getKey(),
            'os_install_profile_id' => $this->osProfile->getKey(),
            'mac_address' => 'aa:bb:cc:dd:ee:11',
            'status' => PxeAuthorisationStatus::Completed,
            'completed_at' => now()->subMonth(),
            'rendered_config' => ['variables' => ['hostname' => 'db-kw-07']],
        ]);

        $this->completeTheInstallOnFirstPoll();
        $this->reinstall();

        $authorisation = PxeBootAuthorisation::query()
            ->where('status', PxeAuthorisationStatus::Completed->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('db-kw-07', $authorisation->rendered_config['variables']['hostname'] ?? null);
    }

    private function handler(): ReinstallDedicatedHandler
    {
        return app(ReinstallDedicatedHandler::class);
    }

    private function reinstall(): ProvisioningResult
    {
        return $this->handler()->execute($this->job());
    }

    private function job(): ProvisioningJob
    {
        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::factory()->create([
            'service_id' => $this->service->getKey(),
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::ReinstallDedicated->value,
            'provider' => 'fake',
            'status' => 'running',
            'max_attempts' => 1,
            'payload' => [
                'dedicated_server_id' => (string) $this->server->getKey(),
                'serial' => $this->server->serial,
                'os_install_profile_id' => (string) $this->osProfile->getKey(),
                'os_install_profile_slug' => $this->osProfile->slug,
                'install_reason' => 'Customer-requested reinstall.',
            ],
        ]);

        return $job;
    }

    private function giveTheServerAnAddress(): void
    {
        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);

        $address = IpAddress::query()->where('subnet_id', $subnet->getKey())
            ->where('address', '198.51.100.10')
            ->firstOrFail();

        IpAssignment::factory()->create([
            'ip_address_id' => $address->getKey(),
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->getKey(),
            'assignable_type' => DedicatedServer::class,
            'assignable_id' => $this->server->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
            'released_at' => null,
        ]);
    }

    /**
     * Stand in for the boot server, which marks the authorisation completed
     * when the installer reports success.
     */
    private function completeTheInstallOnFirstPoll(): void
    {
        PxeBootAuthorisation::created(static function (PxeBootAuthorisation $authorisation): void {
            $authorisation->forceFill([
                'status' => PxeAuthorisationStatus::Completed,
                'booted_at' => now(),
                'completed_at' => now(),
            ])->saveQuietly();
        });
    }

    private function failTheInstallOnFirstPoll(): void
    {
        PxeBootAuthorisation::created(static function (PxeBootAuthorisation $authorisation): void {
            $authorisation->forceFill([
                'status' => PxeAuthorisationStatus::Failed,
                'booted_at' => now(),
                'completed_at' => now(),
            ])->saveQuietly();
        });
    }

    /**
     * A controller that arms the boot override and then refuses the power
     * cycle that would consume it.
     *
     * Swapped in rather than driven through the fake's address markers,
     * because the fake fails from its first call: the case that matters here
     * is precisely "armed, then refused", which is what leaves a machine
     * primed to erase itself at its next reboot.
     */
    private function refuseAfterArming(): void
    {
        $this->swapController(new RecordingDedicatedProvider(
            reportedState: PowerState::On,
            failFrom: ['reset', 'power_on'],
        ));
    }

    private function timeOutAfterArming(): void
    {
        $this->swapController(new RecordingDedicatedProvider(
            reportedState: PowerState::On,
            goQuietFrom: ['reset', 'power_on'],
        ));
    }

    private function swapController(RecordingDedicatedProvider $provider): RecordingDedicatedProvider
    {
        $endpoint = $this->server->preferredBmcEndpoint();
        $this->assertNotNull($endpoint);

        $this->app->make(DedicatedProviderFactory::class)->swap($endpoint, $provider);

        return $provider;
    }
}

/**
 * A machine that answers the moment it is asked.
 *
 * Named rather than anonymous so the container can build it, and separate from
 * the unreachable probe so a test asking "what happens when it does not come
 * back" has to say so explicitly.
 */
final class AlwaysReachableHost implements HostReachability
{
    public function answers(string $host, int $port, float $timeoutSeconds = 5.0): bool
    {
        return true;
    }
}
