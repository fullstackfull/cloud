<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Lynomia\Modules\Dedicated\Application\Actions\ChangeDedicatedServerPower;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;
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
 * The gap that was carried here, and what closed it
 * ---------------------------------------------------------------------------
 *
 * Gap 6 §28 recorded the verdict and Gap 7 demonstrated it through the real
 * software path: a dedicated power request carried no idempotency key, nothing
 * deduped it, and two identical requests reached the controller twice. The
 * second reset interrupted the boot the first one started, and the final power
 * state was identical either way — which is why counting provider calls is
 * the only assertion that ever caught it.
 *
 * Gap 8 built the place the promise could be kept:
 * `dedicated_power_operations`, one row per intent, claimed under a unique
 * index before the controller is called. The two tests below are the pair that
 * matters — one identity reaches the chassis once, two identities reach it
 * twice — because a fix that deduplicated a verb forever would have broken
 * the customer who genuinely reboots twice in a morning.
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
    public function two_requests_carrying_one_idempotency_key_reach_the_controller_once(): void
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

        $first = $power->execute($server, DedicatedPowerAction::Cycle, clientKey: 'reboot-now-1');
        $second = $power->execute($server->fresh(), DedicatedPowerAction::Cycle, clientKey: 'reboot-now-1');

        /*
         * One reset. Counting the calls is still the only assertion that
         * catches this — `power_state` reads `on` whether the chassis was
         * reset once or twice, which is exactly how the defect survived two
         * phases.
         *
         * `calls` counts state-changing calls only — `powerState` is a read
         * and the contract forbids a read from mutating — so one intent is one
         * call, and the verb list says which.
         */
        $this->assertSame(1, $counting->calls, 'the chassis was reset twice for one intent');

        // `power_on` rather than `reset`, because the chassis starts off and
        // `cycle` reads it before acting: "make it boot" on a stopped machine
        // is a power-on. The verb is asserted so a future change that silently
        // turned this into a reset of a running machine would be caught here.
        $this->assertSame(['power_on'], $counting->operations);
        $this->assertSame(PowerState::On, $server->fresh()?->power_state);

        // And the replay is the first request's own answer, not a fresh one.
        $this->assertSame($first->operation, $second->operation);
        $this->assertSame($first->endpointId, $second->endpointId);
        $this->assertSame($first->resultingPowerState, $second->resultingPowerState);

        // One row, settled, because one intent was recorded.
        $record = DedicatedPowerOperation::query()->sole();

        $this->assertSame(PowerOperationOutcome::Accepted, $record->outcome);
        $this->assertSame((string) $server->getKey(), $record->dedicated_server_id);
    }

    #[Test]
    public function two_different_keys_are_two_intentional_reboots_and_both_execute(): void
    {
        /*
         * The positive twin, and the reason the key is part of the identity
         * rather than the verb being deduplicated on its own. A customer who
         * reboots at nine and again at noon meant both, and a platform that
         * swallowed the second would be broken in a way that is much harder to
         * notice than a double reset.
         */
        [$server, $endpoint] = $this->committedChassis();

        $this->app->singleton(DedicatedProviderFactory::class);

        $counting = new CountingDedicatedProvider(new FakeDedicatedProvider);

        app(DedicatedProviderFactory::class)->swap($endpoint, $counting);

        $power = app(ChangeDedicatedServerPower::class);

        $power->execute($server, DedicatedPowerAction::Cycle, clientKey: 'reboot-at-nine');
        $power->execute($server->fresh(), DedicatedPowerAction::Cycle, clientKey: 'reboot-at-noon');

        $this->assertSame(2, $counting->calls);

        // The first boots the stopped chassis; the second resets the machine
        // that is now running. Two verbs, because two intents against a
        // machine in two different states are two different operations.
        $this->assertSame(['power_on', 'reset'], $counting->operations);
        $this->assertSame(2, DedicatedPowerOperation::query()->count());
    }

    #[Test]
    public function a_request_with_no_key_at_all_still_reaches_the_controller(): void
    {
        /*
         * The action is reachable from a console command and a support tool as
         * well as from the customer endpoint, and an operator acting directly
         * has no client request to identify. That path keeps working and
         * records nothing — which is honest: there is no intent to remember,
         * so there is no promise to keep.
         *
         * The customer-facing endpoint requires the header; this is the seam
         * below it.
         */
        [$server, $endpoint] = $this->committedChassis();

        $this->app->singleton(DedicatedProviderFactory::class);

        $counting = new CountingDedicatedProvider(new FakeDedicatedProvider);

        app(DedicatedProviderFactory::class)->swap($endpoint, $counting);

        app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::Cycle);

        $this->assertSame(1, $counting->calls);
        $this->assertSame(0, DedicatedPowerOperation::query()->count());
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
