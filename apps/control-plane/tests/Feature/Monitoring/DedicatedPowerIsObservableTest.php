<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Dedicated\Application\Actions\DedicatedIdempotencyKey;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Monitoring\Application\Collectors\DedicatedCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The path that physically cycles a customer's machine has to be on the
 * dashboard called "dedicated".
 *
 * The sixteen collectors that came before this one read the rest of the
 * platform, and none of them read `dedicated_power_operations`. Dedicated was
 * not wholly unmonitored — rebuilds are exported by kind and a dedicated
 * dashboard exists — which made the gap worse rather than better: an operator
 * reading that dashboard had no cue that power requests were absent from it.
 * In particular a power operation that
 * ended `indeterminate` — a reset the platform may have sent and cannot say
 * whether it landed — was recorded and watched by nothing.
 *
 * What is proved here is the collector. That a booted application actually
 * runs it is proved from the application itself, with no provider registered
 * by hand, in `TheApplicationActuallyRegistersItsCollectorsTest` and
 * `EveryAlertMetricHasAProducerTest`.
 */
final class DedicatedPowerIsObservableTest extends TestCase
{
    use RefreshDatabase;

    private const string OPERATIONS = 'lynomia_dedicated_power_operation_total';

    private const string ABANDONED = 'lynomia_dedicated_power_claims_abandoned';

    #[Test]
    public function every_action_and_outcome_is_published_even_at_zero(): void
    {
        /*
         * A series that appears only once something has gone wrong is a series
         * no rule can be written against in advance.
         */
        $operations = $this->samples(self::OPERATIONS);

        $expected = [];

        foreach (DedicatedPowerAction::cases() as $action) {
            foreach (PowerOperationOutcome::cases() as $outcome) {
                $expected[$action->value.'/'.$outcome->value] = 0.0;
            }
        }

        $this->assertSame($expected, $operations);

        $abandoned = [];

        foreach (DedicatedPowerAction::cases() as $action) {
            $abandoned[$action->value] = 0.0;
        }

        $this->assertSame($abandoned, $this->samples(self::ABANDONED));
    }

    #[Test]
    public function each_power_request_is_counted_under_its_action_and_outcome(): void
    {
        $server = DedicatedServer::factory()->create();

        $this->operation($server, DedicatedPowerAction::Cycle, PowerOperationOutcome::Indeterminate);
        $this->operation($server, DedicatedPowerAction::Cycle, PowerOperationOutcome::Indeterminate);
        $this->operation($server, DedicatedPowerAction::Cycle, PowerOperationOutcome::Accepted);
        $this->operation($server, DedicatedPowerAction::On, PowerOperationOutcome::Refused);
        $this->operation($server, DedicatedPowerAction::Off, PowerOperationOutcome::Claimed);

        $operations = $this->samples(self::OPERATIONS);

        $this->assertSame(2.0, $operations['cycle/indeterminate']);
        $this->assertSame(1.0, $operations['cycle/accepted']);
        $this->assertSame(1.0, $operations['on/refused']);
        $this->assertSame(1.0, $operations['off/claimed']);
        $this->assertSame(0.0, $operations['on/indeterminate']);
        $this->assertSame(5.0, array_sum($operations));
    }

    #[Test]
    public function a_claim_past_its_lease_is_abandoned_and_one_inside_it_is_not(): void
    {
        $lease = (int) config('dedicated.power.claim_lease_minutes');
        $this->assertGreaterThan(0, $lease, 'the claim lease is not configured');

        $server = DedicatedServer::factory()->create();

        // A request still talking to a slow controller: in flight, not abandoned.
        $this->operation($server, DedicatedPowerAction::Cycle, PowerOperationOutcome::Claimed, minutesAgo: $lease - 1);

        // Two whose worker is gone. Nothing will ever settle them but the sweep.
        $this->operation($server, DedicatedPowerAction::Cycle, PowerOperationOutcome::Claimed, minutesAgo: $lease + 1);
        $this->operation($server, DedicatedPowerAction::On, PowerOperationOutcome::Claimed, minutesAgo: 60 * 24);

        // Old, and settled: not a claim at all.
        $this->operation($server, DedicatedPowerAction::Off, PowerOperationOutcome::Indeterminate, minutesAgo: 60 * 24);

        $this->assertSame(
            ['on' => 1.0, 'off' => 0.0, 'cycle' => 1.0],
            $this->samples(self::ABANDONED),
        );
    }

    #[Test]
    public function the_series_count_does_not_grow_with_the_operations(): void
    {
        /*
         * Nothing per server, customer, endpoint or management address. A
         * reset storm across the fleet is precisely when a per-machine label
         * would create thousands of series at once.
         */
        $before = $this->seriesCount();

        $server = DedicatedServer::factory()->create();

        foreach (range(1, 30) as $i) {
            $this->operation(
                $server,
                DedicatedPowerAction::cases()[$i % 3],
                PowerOperationOutcome::cases()[$i % 4],
                minutesAgo: $i * 7,
            );
        }

        $this->assertSame($before, $this->seriesCount());
    }

    /**
     * @return array<string, float> label values joined by "/" => value
     */
    private function samples(string $family): array
    {
        $metric = $this->family($family);

        $samples = [];

        foreach ($metric->samples as $sample) {
            $samples[implode('/', $sample->labels)] = $sample->value;
        }

        return $samples;
    }

    private function family(string $name): Metric
    {
        foreach (app(DedicatedCollector::class)->collect() as $metric) {
            if ($metric->name === $name) {
                return $metric;
            }
        }

        $this->fail(sprintf('%s is not published at all.', $name));
    }

    private function seriesCount(): int
    {
        return array_sum(array_map(
            static fn (Metric $metric): int => count($metric->samples),
            app(DedicatedCollector::class)->collect(),
        ));
    }

    private function operation(
        DedicatedServer $server,
        DedicatedPowerAction $action,
        PowerOperationOutcome $outcome,
        int $minutesAgo = 1,
    ): void {
        DedicatedPowerOperation::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'action' => $action,
            'idempotency_key' => DedicatedIdempotencyKey::for($server, 'power:'.$action->value, bin2hex(random_bytes(8))),
            'outcome' => $outcome,
            'requested_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
        ]);
    }
}
