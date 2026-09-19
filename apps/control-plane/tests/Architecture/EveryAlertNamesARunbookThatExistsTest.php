<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An alert that names a runbook nobody wrote pages somebody at three in
 * the morning with a dead link. Every `runbook:` in the Prometheus rules
 * has to be a file in the repository, and every alert the platform's own
 * business rules raise has to name one — the node, platform and probe
 * alerts predate the runbook convention and are listed here so that the
 * list can only shrink.
 */
final class EveryAlertNamesARunbookThatExistsTest extends TestCase
{
    private const string REPO = __DIR__.'/../../../..';

    /** Alerts written before runbooks were a rule. Remove a name once it has one; never add one. */
    private const array WITHOUT_A_RUNBOOK_YET = [
        'RevenueRecognitionStalled', 'ComputeNodeCapacityExhausted',
        'NodeCpuSaturated', 'NodeMemoryHigh', 'NodeMemoryCritical', 'NodeDiskAlmostFull', 'NodeDiskWillFillIn4Hours',
        'FilesystemReadOnly', 'RaidArrayDegraded', 'SmartHealthFailing',
        'DatabaseUnavailable', 'DatabaseExporterMissing',
        'EndpointDown', 'EndpointSlow', 'ProbeFailingWithHttpError',
        'CertificateExpiringSoon', 'CertificateExpiringCritical', 'CertificateExpired',
    ];

    #[Test]
    public function every_named_runbook_exists_and_no_new_alert_arrives_without_one(): void
    {
        $files = glob(self::REPO.'/infrastructure/monitoring/prometheus/rules/*.yml') ?: [];
        $this->assertNotSame([], $files, 'No rule files were found, so this proves nothing.');

        $missing = [];
        $unnamed = [];
        $seen = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $blocks = preg_split('/^\s*- alert:\s*/m', $source) ?: [];

            foreach (array_slice($blocks, 1) as $block) {
                $name = trim(strtok($block, "\n") ?: '?');
                $seen[] = $name;

                if (preg_match('/runbook:\s*(\S+)/', $block, $m) !== 1) {
                    if (! in_array($name, self::WITHOUT_A_RUNBOOK_YET, true)) {
                        $unnamed[] = basename($file).': '.$name;
                    }

                    continue;
                }

                if (! is_file(self::REPO.'/'.$m[1])) {
                    $missing[] = $name.' -> '.$m[1];
                }
            }
        }

        $this->assertSame([], $unnamed, "Alerts with no runbook:\n  ".implode("\n  ", $unnamed));
        $this->assertSame([], $missing, "Runbooks that do not exist:\n  ".implode("\n  ", $missing));
        $this->assertSame([], array_diff(self::WITHOUT_A_RUNBOOK_YET, $seen), 'The grandfather list names an alert that no longer exists.');
    }
}
