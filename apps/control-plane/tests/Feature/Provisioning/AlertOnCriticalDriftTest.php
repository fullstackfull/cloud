<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first sighting of critical drift says so somewhere a person will see.
 *
 * The drift table and the operator screen existed and the event announcing a
 * first sighting had no listener, so a machine missing from its hypervisor
 * waited for somebody to open the right page. These tests drive the real
 * action, through the real event dispatcher, and assert on the log the
 * platform's alerting reads.
 */
final class AlertOnCriticalDriftTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function critical_drift_is_logged_at_error_with_what_an_operator_needs(): void
    {
        $logged = [];

        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message;
        });

        app(RecordDrift::class)->execute(
            provider: 'proxmox',
            resourceType: 'virtual_machine',
            kind: DriftKind::MissingAtProvider,
            providerReference: '412',
            serviceId: null,
            expected: ['hostname' => 'web-kw-01'],
            observed: null,
            severity: DriftSeverity::Critical,
        );

        $errors = array_values(array_filter(
            $logged,
            static fn (object $message): bool => $message->level === 'error',
        ));

        $this->assertCount(1, $errors, 'Critical drift was recorded and nothing announced it.');
        $this->assertSame('proxmox', $errors[0]->context['provider'] ?? null);
        $this->assertSame('missing_at_provider', $errors[0]->context['kind'] ?? null);
        $this->assertSame('412', $errors[0]->context['provider_reference'] ?? null);
    }

    #[Test]
    public function a_warning_does_not_page_anybody(): void
    {
        /*
         * The severities exist so that an orphan on a node an operator also
         * uses by hand does not wake somebody at three in the morning. A
         * listener that logged every severity at error would undo that in one
         * line.
         */
        $logged = [];

        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message;
        });

        app(RecordDrift::class)->execute(
            provider: 'proxmox',
            resourceType: 'virtual_machine',
            kind: DriftKind::OrphanAtProvider,
            providerReference: '777',
            serviceId: null,
            expected: null,
            observed: ['node' => 'pve-01'],
            severity: DriftSeverity::Warning,
        );

        $this->assertSame([], array_values(array_filter(
            $logged,
            static fn (object $message): bool => $message->level === 'error',
        )));
    }

    #[Test]
    public function seeing_the_same_drift_again_does_not_alert_again(): void
    {
        // A reconciler running every half hour would otherwise announce one
        // unresolved orphan forty-eight times a day, which is how an alert
        // channel stops being read.
        $record = fn (): mixed => app(RecordDrift::class)->execute(
            provider: 'proxmox',
            resourceType: 'virtual_machine',
            kind: DriftKind::MissingAtProvider,
            providerReference: '413',
            serviceId: null,
            expected: null,
            observed: null,
            severity: DriftSeverity::Critical,
        );

        $record();

        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message;
        });

        $record();

        $this->assertSame([], array_values(array_filter(
            $logged,
            static fn (object $message): bool => $message->level === 'error',
        )));
    }
}
