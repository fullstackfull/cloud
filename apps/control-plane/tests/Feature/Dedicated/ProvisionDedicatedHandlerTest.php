<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Application\Handlers\ProvisionDedicatedHandler;
use Lynomia\Modules\Dedicated\Domain\Contracts\DedicatedProvider;
use Lynomia\Modules\Dedicated\Domain\DTOs\BmcOperation;
use Lynomia\Modules\Dedicated\Domain\DTOs\FirmwareComponent;
use Lynomia\Modules\Dedicated\Domain\DTOs\HardwareHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The handler that turns a paid order into an installed physical server.
 *
 * Its ordering is the design, and it differs from the VPS handler in one way
 * that changes everything: nothing here is created, so the irreversible step
 * is not "a machine now exists" but "a machine's disks have been erased".
 * Reserve, address, authorise, and only then power cycle.
 *
 * The classification tests below matter more than the happy path. Getting a
 * class wrong on physical hardware is a customer's server reinstalled twice.
 */
final class ProvisionDedicatedHandlerTest extends TestCase
{
    use RefreshDatabase;

    private const string PROFILE = 'ded-epyc-64';

    private Datacenter $datacenter;

    private IpPool $pool;

    private Customer $customer;

    private OsInstallProfile $osProfile;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dedicated.provider', 'fake');

        /*
         * One factory for the whole test, which is how a queue worker holds it:
         * the handler is given a single instance and every adapter it resolves
         * is memoised on it. Without the singleton a test that pre-powers a
         * machine through the factory would be talking to a different fake than
         * the handler is.
         */
        $this->app->singleton(DedicatedProviderFactory::class);

        /*
         * The install poll is not what these tests are about. Freezing the
         * clock and letting the faked sleeps advance it means the wait loop
         * reaches its deadline in a few iterations instead of ninety real
         * minutes — while still exercising the loop rather than skipping it.
         */
        $this->freezeTime();
        Sleep::fake(syncWithCarbon: true);

        $this->datacenter = Datacenter::factory()->create();

        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);
        $this->pool = $subnet->ipPool;

        $this->customer = Customer::factory()->create();
        $this->osProfile = OsInstallProfile::factory()->create();
    }

    #[Test]
    public function it_reserves_addresses_authorises_pxe_powers_on_and_marks_the_machine_active(): void
    {
        $server = $this->rackedServer();

        // The boot server will report the install finished; without it the
        // handler would correctly wait.
        $this->completeTheInstallOnFirstPoll();

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        $this->assertTrue($result->successful, (string) $result->errorMessage);

        $server->refresh();
        $this->assertSame(DedicatedServerStatus::Active, $server->status);
        $this->assertSame($this->customer->id, $server->customer_id);
        $this->assertNull($server->reserved_until);

        // The machine was off, so it was powered on rather than reset — a
        // reset on a machine that is already installing is an interrupted
        // install.
        $this->assertSame('power_on', $result->metadata['power_operation']);
        $this->assertSame(PowerState::On, $server->power_state);

        $authorisation = PxeBootAuthorisation::query()->sole();
        $this->assertSame(PxeAuthorisationStatus::Completed, $authorisation->status);
        $this->assertSame('Once', $authorisation->rendered_config['bmc']['boot_source_override_enabled']);

        // The address is committed only now, against a machine that is
        // actually installed.
        $assignment = IpAssignment::query()->sole();
        $this->assertSame($server->id, $assignment->assignable_id);
        // IPAM stores MACs uppercased and this module stores them lowercased;
        // compared case-insensitively because the address is the fact, not its
        // spelling.
        $this->assertSame($authorisation->mac_address, strtolower((string) $assignment->mac_address));
        $this->assertSame(IpAddressStatus::Assigned, IpAddress::query()->findOrFail($assignment->ip_address_id)->status);
    }

    #[Test]
    public function a_running_machine_is_reset_rather_than_powered_on(): void
    {
        $server = $this->rackedServer();
        $endpoint = $server->bmcEndpoints()->sole();

        // Put the fake's remembered state to "on" the way the platform would:
        // by asking the provider to power it on.
        app(DedicatedProviderFactory::class)->for($endpoint)->powerOn($endpoint);

        $this->completeTheInstallOnFirstPoll();

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        // A running host has no reason to reboot itself, and a graceful
        // shutdown would ask an operating system that may not be there.
        $this->assertSame('reset', $result->metadata['power_operation']);
    }

    #[Test]
    public function a_machine_whose_power_state_is_unknown_is_reset_rather_than_powered_on(): void
    {
        $server = $this->rackedServer();
        $endpoint = $server->bmcEndpoints()->sole();

        // A controller that will not say what the machine is doing: a
        // transitional state, an unrecognised vendor string, an IPMI line the
        // parser has not seen. Real, and the branch with the worst failure.
        app(DedicatedProviderFactory::class)->swap($endpoint, $this->providerReportingUnknownPower());

        $this->completeTheInstallOnFirstPoll();

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        $this->assertTrue($result->successful, (string) $result->errorMessage);

        /*
         * Reset, not power-on. Issuing power-on to a machine that is already
         * running does nothing at all: the one-time override is never
         * consumed, the install silently never starts, and the machine is left
         * armed to network boot at some later unrelated reboot — which is the
         * surprise reinstall the whole module is built to prevent, merely
         * postponed.
         */
        $this->assertSame('reset', $result->metadata['power_operation']);
    }

    /**
     * The fake adapter, with only its power reading replaced.
     *
     * Everything else — the PXE arming, the remembered state, the markers —
     * stays the real fake, so the test exercises the handler's branch rather
     * than a mock of it.
     */
    private function providerReportingUnknownPower(): DedicatedProvider
    {
        return new class(new FakeDedicatedProvider) implements DedicatedProvider
        {
            public function __construct(private readonly FakeDedicatedProvider $inner) {}

            public function protocol(): BmcProtocol
            {
                return $this->inner->protocol();
            }

            public function hardwareHealth(BmcEndpoint $endpoint): HardwareHealth
            {
                return $this->inner->hardwareHealth($endpoint);
            }

            public function powerState(BmcEndpoint $endpoint): PowerState
            {
                return PowerState::Unknown;
            }

            public function powerOn(BmcEndpoint $endpoint): BmcOperation
            {
                return $this->inner->powerOn($endpoint);
            }

            public function powerOff(BmcEndpoint $endpoint): BmcOperation
            {
                return $this->inner->powerOff($endpoint);
            }

            public function gracefulShutdown(BmcEndpoint $endpoint): BmcOperation
            {
                return $this->inner->gracefulShutdown($endpoint);
            }

            public function reset(BmcEndpoint $endpoint): BmcOperation
            {
                return $this->inner->reset($endpoint);
            }

            public function setOneTimePxeBoot(BmcEndpoint $endpoint): BmcOperation
            {
                return $this->inner->setOneTimePxeBoot($endpoint);
            }

            /**
             * @return list<string>
             */
            public function bootOrder(BmcEndpoint $endpoint): array
            {
                return $this->inner->bootOrder($endpoint);
            }

            /**
             * @return list<FirmwareComponent>
             */
            public function firmwareInventory(BmcEndpoint $endpoint): array
            {
                return $this->inner->firmwareInventory($endpoint);
            }
        };
    }

    #[Test]
    public function no_matching_hardware_is_capacity_and_goes_to_manual_review_rather_than_a_refund(): void
    {
        // Nothing racked of that profile.
        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        $this->assertTrue($result->isFailure());

        /*
         * Capacity, not Permanent. A dedicated server already exists or it does
         * not, and no amount of retrying conjures another one — but an operator
         * racking a machine, or a termination freeing one, resolves this within
         * a day. Permanent would fail the order and refund a customer who was
         * content to wait.
         */
        $this->assertSame(FailureClass::Capacity, $result->failureClass);
        $this->assertSame('dedicated.no_matching_hardware', $result->errorCode);
        $this->assertTrue($result->metadata['requires_manual_review']);
        $this->assertSame('manual_review', $result->metadata['disposition']);

        // Nothing was reserved and no addresses were taken.
        $this->assertSame(0, PxeBootAuthorisation::query()->count());
        $this->assertSame(0, IpAssignment::query()->count());
    }

    #[Test]
    public function a_controller_that_stops_answering_mid_install_is_a_timeout_and_the_machine_is_quarantined(): void
    {
        $server = $this->rackedServer(
            bmcAddress: FakeDedicatedProvider::addressWith('192.0.2.40', FakeDedicatedProvider::TIMEOUT_MARKER),
        );

        $order = Order::factory()->create();

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job(['order_id' => $order->getKey()]));

        $this->assertTrue($result->isFailure());

        /*
         * A BMC that goes quiet has not told us the install stopped; the
         * machine is very likely still erasing and rebuilding itself. The
         * engine neither retries nor releases resources for a timeout.
         */
        $this->assertSame(FailureClass::Timeout, $result->failureClass);
        $this->assertTrue($result->failureClass->requiresQuarantine());
        $this->assertFalse($result->failureClass->isAutomaticallyRetryable());
        $this->assertTrue($result->failureClass->requiresReview());
        $this->assertTrue($result->metadata['quarantined']);

        $server->refresh();

        /*
         * Still provisioning: not returned to stock, not marked failed.
         * Releasing it would offer a half-erased machine to the next order;
         * marking it failed would send an engineer to a machine that is fine.
         */
        $this->assertSame(DedicatedServerStatus::Provisioning, $server->status);
        $this->assertNotNull($server->reserved_by_order_id);
    }

    #[Test]
    public function a_controller_that_refuses_out_loud_is_transient_and_the_machine_is_held_for_the_retry(): void
    {
        $server = $this->rackedServer(
            bmcAddress: FakeDedicatedProvider::addressWith('192.0.2.41', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER),
        );

        $order = Order::factory()->create();

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job(['order_id' => $order->getKey()]));

        // The controller answered and declined, so nothing physical happened
        // and the engine may try again.
        $this->assertSame(FailureClass::Transient, $result->failureClass);
        $this->assertTrue($result->failureClass->isAutomaticallyRetryable());
        $this->assertTrue($result->metadata['held_for_retry']);

        $server->refresh();

        /*
         * The machine is NOT put back on the shelf. Once it has entered
         * provisioning the platform cannot always tell from outside whether an
         * installer has begun writing to its disks, and the hold is keyed to
         * the order — so the retry continues with this same box rather than
         * taking a second one out of stock.
         */
        $this->assertSame(DedicatedServerStatus::Provisioning, $server->status);
        $this->assertSame((string) $order->getKey(), $server->reserved_by_order_id);
    }

    #[Test]
    public function a_retry_after_a_transient_refusal_continues_with_the_same_machine(): void
    {
        $server = $this->rackedServer(
            bmcAddress: FakeDedicatedProvider::addressWith('192.0.2.42', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER),
        );

        // A second machine of the same profile, so that a retry which lost the
        // hold would silently succeed on the wrong box.
        $this->rackedServer(bmcAddress: '192.0.2.43');

        $order = Order::factory()->create();

        app(ProvisionDedicatedHandler::class)->execute($this->job(['order_id' => $order->getKey()]));
        app(ProvisionDedicatedHandler::class)->execute($this->job(['order_id' => $order->getKey()]));

        $this->assertSame(
            1,
            DedicatedServer::query()->where('reserved_by_order_id', $order->getKey())->count(),
            'A retry took a second machine out of stock.',
        );
        $this->assertSame(DedicatedServerStatus::Provisioning, $server->fresh()?->status);
    }

    #[Test]
    public function an_install_that_runs_out_of_time_is_a_timeout_and_the_machine_is_not_returned_to_stock(): void
    {
        $server = $this->rackedServer();

        // The boot server never reports completion.
        config()->set('dedicated.pxe.install_timeout_minutes', 1);

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        $this->assertSame(FailureClass::Timeout, $result->failureClass);
        $this->assertSame('dedicated.install_timed_out', $result->errorCode);
        $this->assertTrue($result->metadata['quarantined']);

        // The installer may still be running: the platform stopped waiting, the
        // machine did not stop erasing itself.
        $this->assertSame(DedicatedServerStatus::Provisioning, $server->fresh()?->status);
    }

    #[Test]
    public function an_install_that_reports_failure_marks_the_hardware_failed_and_is_not_retried(): void
    {
        $server = $this->rackedServer();

        $this->failTheInstallOnFirstPoll();

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        /*
         * Permanent, which is the uncomfortable but correct call. An install
         * that fails after the machine booted is usually a fact about the
         * machine — a dead disk, a failing DIMM, a NIC off the provisioning
         * VLAN — and a retry against the same box repeats it, having erased the
         * disks a second time. An operator decides between repair and
         * replacement.
         */
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('dedicated.install_failed', $result->errorCode);
        $this->assertFalse($result->failureClass->isAutomaticallyRetryable());

        $this->assertSame(DedicatedServerStatus::Failed, $server->fresh()?->status);
    }

    #[Test]
    public function a_machine_with_no_recorded_nic_is_refused_permanently_without_being_flagged_faulty(): void
    {
        $server = $this->rackedServer(withNic: false);

        $result = app(ProvisionDedicatedHandler::class)->execute($this->job());

        // The request cannot be satisfied and will be just as unsatisfiable
        // next time.
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('dedicated.pxe_authorisation_refused', $result->errorCode);
        $this->assertTrue($result->metadata['held_for_review']);

        /*
         * The hardware is not at fault — the inventory record is — so the
         * machine is not marked failed, which would take a sellable box out of
         * stock and send an engineer to a rack for nothing. It waits in
         * provisioning for a person, with the row naming the order that put it
         * there.
         */
        $this->assertSame(DedicatedServerStatus::Provisioning, $server->fresh()?->status);
        $this->assertSame(0, PxeBootAuthorisation::query()->count());
    }

    #[Test]
    public function the_handler_is_registered_for_the_dedicated_kind(): void
    {
        $this->assertSame(
            ProvisioningJobKind::ProvisionDedicated,
            app(ProvisionDedicatedHandler::class)->kind(),
        );

        // Provisioning a dedicated server brings a resource into existence, so
        // an unclassified failure has to be treated as "something may exist out
        // there" rather than "nothing happened".
        $this->assertTrue(ProvisioningJobKind::ProvisionDedicated->createsResource());
    }

    /**
     * A machine on the shelf, with a controller and a cabled provisioning NIC.
     */
    private function rackedServer(string $bmcAddress = '192.0.2.10', bool $withNic = true): DedicatedServer
    {
        $server = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile(self::PROFILE)
            ->create();

        BmcEndpoint::factory()->forServer($server)->at($bmcAddress)->create();

        if ($withNic) {
            ServerComponent::factory()->forServer($server)->nic('aa:bb:cc:dd:ee:02')->create();
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(array $overrides = []): ProvisioningJob
    {
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'dedicated',
        ]);

        return ProvisioningJob::factory()->create(array_merge([
            'service_id' => $service->id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::ProvisionDedicated->value,
            'provider' => 'fake',
            'status' => 'running',
            'payload' => [
                'hardware_profile' => self::PROFILE,
                'datacenter_id' => $this->datacenter->id,
                'ip_pool_id' => $this->pool->id,
                'os_install_profile_id' => $this->osProfile->id,
                'hostname' => 'ded-01',
                'ipv4_count' => 1,
            ],
        ], $overrides));
    }

    /**
     * Stand in for the boot server, which marks the authorisation completed
     * when the installer reports success.
     *
     * Hooked to the model's own save so that the handler's first poll finds
     * the row already finished — the alternative, letting the loop spin, is
     * what the timeout test covers instead.
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
}
