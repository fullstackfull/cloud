<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Domain\Enums\CompensationAction;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The one branch that decides whether a customer's address is safe to give
 * away.
 */
final class CompensateFailedJobTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    private CompensateFailedJob $compensate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compensate = app(CompensateFailedJob::class);
    }

    /**
     * @return array<string, array{0: FailureClass}>
     */
    public static function classesThatBuiltNothing(): array
    {
        return [
            'transient' => [FailureClass::Transient],
            'permanent' => [FailureClass::Permanent],
            'capacity' => [FailureClass::Capacity],
        ];
    }

    #[Test]
    #[DataProvider('classesThatBuiltNothing')]
    public function a_failure_that_built_nothing_releases_its_reservations(FailureClass $failureClass): void
    {
        $job = ProvisioningJob::factory()->failedWith($failureClass)->create();

        $outcome = $this->compensate->execute($job, $failureClass);

        // Nothing exists at the provider, so holding the address would deny it
        // to the next customer for no reason at all.
        $this->assertSame(CompensationAction::Released, $outcome->action);
        $this->assertTrue($this->releaser->released($job));
        $this->assertFalse($this->releaser->quarantined($job));
    }

    #[Test]
    public function a_timeout_quarantines_and_never_releases(): void
    {
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create([
            'remote_job_id' => 'UPID:node:0001',
        ]);

        $outcome = $this->compensate->execute($job, FailureClass::Timeout);

        /*
         * The provider accepted the work and we stopped waiting. A machine may
         * be running right now with this job's address configured on it.
         * Releasing hands that address to the next customer, and the resulting
         * conflict is invisible until two customers are simultaneously broken.
         */
        $this->assertSame(CompensationAction::Quarantined, $outcome->action);
        $this->assertTrue($this->releaser->quarantined($job));
        $this->assertFalse($this->releaser->released($job));
    }

    #[Test]
    public function the_decision_is_written_onto_the_job_with_its_reason(): void
    {
        $job = ProvisioningJob::factory()->failedWith(FailureClass::Timeout)->create();

        $this->compensate->execute($job, FailureClass::Timeout);

        $recorded = $job->fresh()?->result['compensation'] ?? [];

        // Months later the only way to explain why an address is still held is
        // to point at the row that says a timeout put it there.
        $this->assertSame('quarantined', $recorded['action'] ?? null);
        $this->assertSame('timeout', $recorded['failure_class'] ?? null);
        $this->assertStringContainsString('may exist at the provider', (string) ($recorded['reason'] ?? ''));
        $this->assertArrayHasKey('at', $recorded);
    }
}
