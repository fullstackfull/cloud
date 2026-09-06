<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\DriftRecorded;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reconciliation writes here, every half hour, for as long as a disagreement
 * lasts. What it must not do is turn one unnoticed orphan into forty-eight
 * rows a day, and it must never quietly put things right by itself.
 */
final class RecordDriftTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    private RecordDrift $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->record = app(RecordDrift::class);
    }

    #[Test]
    public function a_repeat_sighting_updates_the_existing_row_instead_of_duplicating_it(): void
    {
        $first = $this->sight(['power_state' => 'running']);

        $this->travel(5)->minutes();

        $second = $this->sight(['power_state' => 'stopped']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ResourceDrift::query()->count());
        $this->assertSame(2, $second->occurrences);

        // The observation is refreshed and the clock moves; the first sighting
        // stays put, because how long this has been wrong is the fact an
        // operator triages on.
        $this->assertSame('stopped', $second->observed['power_state'] ?? null);
        $this->assertTrue($second->last_seen_at->greaterThan($second->first_seen_at));
    }

    #[Test]
    public function a_third_sighting_keeps_counting_rather_than_alerting_again(): void
    {
        Event::fake([DriftRecorded::class]);

        $this->sight();
        $this->sight();
        $this->sight();

        $this->assertSame(3, ResourceDrift::query()->sole()->occurrences);

        // Announced once. An alert channel that cries wolf forty-eight times a
        // day is one nobody reads on the day it matters.
        Event::assertDispatchedTimes(DriftRecorded::class, 1);
    }

    #[Test]
    public function distinct_resources_are_distinct_drifts(): void
    {
        $this->sight(reference: 'vm-100');
        $this->sight(reference: 'vm-200');

        $this->assertSame(2, ResourceDrift::query()->count());
    }

    #[Test]
    public function a_different_disagreement_about_the_same_resource_is_its_own_row(): void
    {
        $this->sight(kind: DriftKind::StateMismatch);
        $this->sight(kind: DriftKind::SpecMismatch);

        // "It is the wrong size" and "it is switched off" are two problems
        // with two different answers.
        $this->assertSame(2, ResourceDrift::query()->count());
    }

    #[Test]
    public function a_resolved_drift_that_reappears_starts_a_new_row(): void
    {
        $original = $this->sight();
        $original->update([
            'status' => DriftStatus::Resolved,
            'resolution' => 'adopted',
            'resolved_at' => now(),
        ]);

        $reappeared = $this->sight();

        // The resolution that did not hold is history worth keeping separate
        // from the problem that came back.
        $this->assertNotSame($original->id, $reappeared->id);
        $this->assertSame(2, ResourceDrift::query()->count());
        $this->assertSame(1, $reappeared->occurrences);
    }

    #[Test]
    public function severity_is_never_downgraded_by_a_later_sighting(): void
    {
        $this->sight(severity: DriftSeverity::Critical);
        $drift = $this->sight(severity: DriftSeverity::Info);

        // A pass that was less sure is not evidence that the problem got
        // smaller.
        $this->assertSame(DriftSeverity::Critical, $drift->severity);
    }

    #[Test]
    public function nothing_is_ever_healed_automatically(): void
    {
        $service = Service::factory()->active()->create();

        $this->record->execute(
            provider: 'fake',
            resourceType: 'virtual_machine',
            kind: DriftKind::MissingAtProvider,
            providerReference: 'vm-100',
            serviceId: $service->id,
            expected: ['power_state' => 'running'],
            observed: null,
        );

        /*
         * The provider says the customer's machine is not there. The tempting
         * remedy — rebuild it, or terminate the service — is exactly the one
         * that destroys a live server when an API pages badly or a node is
         * briefly unreachable. The platform records, alerts, and waits for a
         * person.
         */
        $this->assertSame(ServiceStatus::Active, $service->fresh()?->status);
        $this->assertSame(0, ProvisioningJob::query()->count());
        $this->assertSame(DriftStatus::Open, ResourceDrift::query()->sole()->status);
        $this->assertFalse((bool) config('provisioning.reconciliation.auto_heal'));
    }

    #[Test]
    public function an_observation_carrying_a_credential_is_stored_without_it(): void
    {
        $drift = $this->record->execute(
            provider: 'fake',
            resourceType: 'virtual_machine',
            kind: DriftKind::StateMismatch,
            providerReference: 'vm-100',
            observed: ['api_key' => 'live-key-abcdef123456', 'power_state' => 'running'],
        );

        $stored = (string) DB::table('resource_drifts')->where('id', $drift->id)->value('observed');

        $this->assertStringNotContainsString('live-key-abcdef123456', $stored);
        $this->assertSame('[redacted]', $drift->fresh()?->observed['api_key'] ?? null);
        $this->assertSame('running', $drift->fresh()?->observed['power_state'] ?? null);
    }

    /**
     * @param  array<string, mixed>|null  $observed
     */
    private function sight(
        ?array $observed = ['power_state' => 'running'],
        string $reference = 'vm-100',
        DriftKind $kind = DriftKind::OrphanAtProvider,
        DriftSeverity $severity = DriftSeverity::Warning,
    ): ResourceDrift {
        return $this->record->execute(
            provider: 'fake',
            resourceType: 'virtual_machine',
            kind: $kind,
            providerReference: $reference,
            observed: $observed,
            severity: $severity,
        );
    }
}
