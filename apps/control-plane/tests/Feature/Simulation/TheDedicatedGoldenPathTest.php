<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Dedicated\Application\Actions\ChangeDedicatedServerPower;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CountingDedicatedProvider;

/**
 * A physical machine's power path, and the gap in it that Gap 7 must not hide.
 *
 * ---------------------------------------------------------------------------
 * What is proved here
 * ---------------------------------------------------------------------------
 *
 * The delivery, reinstall and return of a chassis are already proved by
 * `TheWholeLifeOfADedicatedServerTest`, and a power state set in one process
 * and read by a worker in another is proved by
 * {@see AControlledProviderRemembersAcrossProcessesTest}. What this file adds
 * is the power path itself and the duplicate request the platform does not
 * yet stop.
 *
 * ---------------------------------------------------------------------------
 * The carried gap
 * ---------------------------------------------------------------------------
 *
 * Gap 6 §28 recorded the verdict: a dedicated power request carries no
 * idempotency key, nothing dedupes it, and two identical requests reach the
 * controller twice. Gap 7's job was to demonstrate that through the real
 * software path rather than through the simulator's own behaviour, and that is
 * the second test below.
 *
 * It asserts the *present* behaviour — two provider calls — and its name says
 * so, because a test that asserted one call would fail today and a test that
 * quietly asserted two without saying why would read as a promise. This is a
 * REAL CODE GAP, CARRIED TO GAP 8.
 *
 * The controller adds nothing to this: `DedicatedServerController::power()`
 * authorises, looks the machine up and calls the action. The VPS path has
 * `VpsIdempotencyKey` and `RequestVpsPowerChange` between the request and the
 * hypervisor; the dedicated path has nothing between the request and the
 * chassis, which is why calling the action twice is the same thing a customer
 * pressing a button twice does.
 */
#[Group('golden-path')]
final class TheDedicatedGoldenPathTest extends GoldenPathHarness
{
    #[Test]
    public function a_power_request_reaches_the_controller_and_the_platform_records_what_it_said(): void
    {
        [$server, $endpoint] = $this->committedChassis();

        $operation = app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::On);

        $this->assertTrue($operation->accepted);
        $this->assertSame(PowerState::On, $operation->resultingPowerState);

        // The platform's own record moved, because the controller reported a
        // state it had observed.
        $this->assertSame(PowerState::On, $server->fresh()?->power_state);

        // And the controller agrees, asked separately.
        $this->assertSame(
            PowerState::On,
            app(DedicatedProviderFactory::class)->for($endpoint)->hardwareHealth($endpoint)->powerState,
        );
    }

    #[Test]
    public function a_graceful_shutdown_is_never_written_as_off_because_nothing_observed_it(): void
    {
        /*
         * An ACPI request is accepted by a controller whether or not anything
         * in the operating system is listening. A host with a hung kernel
         * stays up for hours, and a platform that wrote `off` here would show
         * a customer a machine that is still serving traffic as switched off.
         */
        [$server] = $this->committedChassis(['power_state' => PowerState::On]);

        $operation = app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::Off);

        $this->assertTrue($operation->accepted);
        $this->assertSame(PowerState::On, $server->fresh()?->power_state);
    }

    #[Test]
    public function two_identical_power_requests_reach_the_controller_twice_and_that_is_the_carried_gap(): void
    {
        [$server, $endpoint] = $this->committedChassis();

        /*
         * The factory is bound per resolution, deliberately, so that one test
         * swapping a driver does not rebuild the world for the next. A swap
         * therefore has to be made on an instance the action will also get,
         * which is what promoting it to a singleton for this test does.
         */
        $this->app->singleton(DedicatedProviderFactory::class);

        $counting = new CountingDedicatedProvider(new FakeDedicatedProvider);

        app(DedicatedProviderFactory::class)->swap($endpoint, $counting);

        $power = app(ChangeDedicatedServerPower::class);

        $power->execute($server, DedicatedPowerAction::Cycle);
        $power->execute($server->fresh(), DedicatedPowerAction::Cycle);

        /*
         * Two resets. The chassis was interrupted mid-boot by the second one,
         * and the final power state is identical either way — which is exactly
         * why counting the calls is the only assertion that catches this. A
         * test that looked at `power_state` would have found `on` and passed.
         *
         * What a fix looks like, for whoever picks this up in Gap 8: the VPS
         * path's shape. A durable key derived from the machine, the verb and
         * the customer's request, recorded before the provider is called, so
         * that a replay returns the first operation instead of sending a
         * second.
         */
        $this->assertSame(2, $counting->calls, 'the known dedicated power gap has been closed — update this test');
        $this->assertSame(PowerState::On, $server->fresh()?->power_state);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: DedicatedServer, 1: BmcEndpoint}
     */
    private function committedChassis(array $attributes = []): array
    {
        return $this->outsideTheTransaction(static function () use ($attributes): array {
            $server = DedicatedServer::factory()->status(DedicatedServerStatus::Active)->create([
                'power_state' => PowerState::Off,
                ...$attributes,
            ]);

            return [$server, BmcEndpoint::factory()->forServer($server)->create()];
        });
    }
}
