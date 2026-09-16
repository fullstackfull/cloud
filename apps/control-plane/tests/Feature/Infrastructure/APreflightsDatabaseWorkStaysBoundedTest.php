<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A global preflight's database work must not grow with the estate.
 *
 * ===========================================================================
 * WHAT GROWS AND WHAT MUST NOT
 * ===========================================================================
 *
 * Network calls grow with the number of providers, necessarily: each one has
 * to be asked. That is the cost of the answer and there is no way around it.
 *
 * Database queries are different. A chain that lazily loaded each provider's
 * credential, licence, machine and capability rows would issue four extra
 * queries per provider, so a twelve-provider estate would cost fifty — and the
 * slowdown would arrive exactly when the estate got big enough to matter,
 * which is when somebody most needs the preflight to answer.
 *
 * So the rows are eager-loaded once at the top of the run and threaded
 * through. This test proves it by measuring, not by reading: one provider
 * against eight, and the difference has to be small and flat.
 */
final class APreflightsDatabaseWorkStaysBoundedTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_PREFLIGHT_BUDGET';

    /**
     * How many queries a preflight may issue per extra provider.
     *
     * Zero would be ideal and is not achievable: the readiness engine's own
     * assembly and the dependency checks run once per run regardless. What
     * matters is that the *per-provider* cost is flat, so the allowance here
     * is per additional provider rather than in total.
     */
    private const int PER_PROVIDER_ALLOWANCE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        putenv(self::VARIABLE.'=a-value');
    }

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);

        parent::tearDown();
    }

    #[Test]
    public function eight_providers_do_not_cost_eight_times_one_providers_queries(): void
    {
        $this->providers(1);
        $one = $this->queriesForAGlobalPreflight();

        $this->providers(7);
        $eight = $this->queriesForAGlobalPreflight();

        $growth = $eight - $one;

        $this->assertLessThanOrEqual(
            self::PER_PROVIDER_ALLOWANCE * 7,
            $growth,
            sprintf(
                'A global preflight cost %d queries for one provider and %d for eight — %d more for seven extra '
                ."rows, which is %.1f per provider.\n\nThe provider rows and their credentials, licences, machines "
                .'and capabilities are meant to be eager-loaded once at the top of the run. Something is loading '
                .'them per check, and the cost will arrive exactly when the estate gets big enough to matter.',
                $one,
                $eight,
                $growth,
                $growth / 7,
            ),
        );
    }

    #[Test]
    public function the_measurement_is_actually_measuring_something(): void
    {
        // The gate on the gate: a counter that returned zero would pass the
        // assertion above for the emptiest possible reason.
        $this->providers(1);

        $this->assertGreaterThan(3, $this->queriesForAGlobalPreflight());
    }

    private function providers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $provider = ProviderInstance::factory()->create([
                'credential_reference_id' => CredentialReference::factory()
                    ->create(['backend_reference' => self::VARIABLE])
                    ->getKey(),
            ]);

            // Capabilities exist so that a lazy load of the relation would show
            // up in the count rather than being optimised away as empty.
            ProviderCapability::factory()->create([
                'provider_instance_id' => $provider->getKey(),
                'capability' => 'records',
            ]);
        }
    }

    private function queriesForAGlobalPreflight(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(InfrastructurePreflightService::class)->run(PreflightRequest::estate(PreflightMode::Simulation));

        $count = count(DB::getRawQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
