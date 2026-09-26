<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Contracts\Queue\ShouldQueue;
use Lynomia\Modules\Provisioning\Application\Listeners\AlertOnCriticalDrift;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Critical drift has to reach a person by a route that exists end to end.
 *
 * ===========================================================================
 * WHAT WAS BROKEN
 * ===========================================================================
 *
 * A drift row reaches an operator by two legs, and every hop of both was
 * broken at once:
 *
 *   A. row → metric → scrape → rule → Alertmanager route → receiver.
 *      Both drift series were exported and no rule read either of them.
 *   B. row → event → listener → log file → Alloy → Loki → ruler.
 *      The listener wrote into `storage/logs/lynomia.json`; Alloy tailed
 *      `/var/log/lynomia/lynomia.json`, a path nothing in the repository
 *      creates; and the Loki ruler was wired to Alertmanager over an empty,
 *      unmounted rules directory.
 *
 * ===========================================================================
 * WHO HOLDS WHICH HOP
 * ===========================================================================
 *
 * Every assertion about the Prometheus, Alertmanager and Loki files — that the
 * drift rules exist, exactly what they evaluate, that Alertmanager's route
 * tree selects `pagerduty-critical` for the page, and that a Loki ruler is not
 * wired without rule files — lives in `infrastructure/scripts/validate-monitoring.py`,
 * which parses those files with PyYAML and runs in CI's infrastructure job
 * with its own self-test. It is deliberately not here: a hand-written YAML
 * reader in PHP is a second, weaker model of the same files, and a model of a
 * file can be wrong in ways the file never is. The route walk there is itself
 * a model of Alertmanager, and its docstring says how it is bounded: what it
 * cannot read the way Alertmanager v0.28 reads it, it refuses.
 *
 * What stays here is what only the application can answer — where Laravel
 * actually writes the structured log, measured from a booted config rather
 * than read from source, and whether the listener can be queued — plus the
 * two Ansible values the log path is derived from, read with a line regex
 * each (`control_plane_release_dir`, `lynomia_base_dir`).
 */
final class CriticalDriftReachesAnOperatorTest extends TestCase
{
    private const string ALLOY = '/infrastructure/monitoring/alloy/config.alloy';

    private const string ROLE_DEFAULTS = '/infrastructure/ansible/roles/control_plane/defaults/main.yml';

    private const string GROUP_VARS = '/infrastructure/ansible/group_vars/all.yml';

    #[Test]
    public function alloy_tails_the_file_the_structured_channel_writes_on_a_deployed_host(): void
    {
        /*
         * Derived on both sides, so a change to either alone is red: the
         * application side is the booted channel's path taken relative to the
         * application root; the host side is the release directory the
         * control_plane role declares and runs Horizon and cron from
         * (the CI/CD pipeline, not the role, puts the release there),
         * resolved from the Ansible defaults rather than typed here. Neither
         * literal appears in this test.
         */
        $written = (string) config('logging.channels.structured.path');
        $root = rtrim(base_path(), '/').'/';

        $this->assertStringStartsWith(
            $root,
            $written,
            'The structured channel writes outside the application root, so its location on a deployed host cannot be derived from the release directory.',
        );

        $expected = $this->releaseDirectory().'/'.substr($written, strlen($root));

        $this->assertSame(
            $expected,
            $this->alloyControlPlanePath(),
            'Alloy tails a different file from the one the structured channel writes on a control-plane host. '
            .'Every line the application logs — including the critical-drift line — lands in a file nothing ships. '
            .'Point `__path__` in alloy/config.alloy at the release directory the control_plane role declares, or move the channel; change both or neither.',
        );
    }

    #[Test]
    public function the_structured_log_is_written_under_storage_which_the_application_can_write(): void
    {
        /*
         * Agreement is not writability: both sides could be moved together to
         * a path the application cannot create, and the test above would stay
         * green. `storage/` is where Laravel expects to write, and it is under
         * the release directory, inside `lynomia_base_dir`, which the
         * control_plane role gives to `lynomia_owner_user` — the user PHP-FPM
         * runs as by default (`php_fpm_user`). A root-owned
         * path such as `/var/log/lynomia` is one that user cannot `mkdir`,
         * and nothing in this repository creates it. (Which directories a
         * release makes writable is the pipeline's business, not this
         * repository's; this holds the path to the conventional one.)
         */
        $this->assertStringStartsWith(
            rtrim(storage_path(), '/').'/',
            (string) config('logging.channels.structured.path'),
            'The structured channel writes outside storage/, the directory Laravel expects to write to. A root-owned path such as /var/log/lynomia is one the application user cannot create.',
        );
    }

    #[Test]
    public function the_critical_drift_listener_is_not_queued(): void
    {
        /*
         * The listener's own docblock gives the reason: one log line must not
         * be lost behind a queue that is itself unhealthy. The testing queue
         * connection is `sync`, so adding `implements ShouldQueue` leaves every
         * behavioural test green — this is the only thing that notices.
         */
        $this->assertFalse(
            is_subclass_of(AlertOnCriticalDrift::class, ShouldQueue::class),
            'AlertOnCriticalDrift must not be queued: a critical-drift line lost behind an unhealthy queue is the failure it exists to avoid.',
        );
    }

    private function alloyControlPlanePath(): string
    {
        $config = $this->read(self::ALLOY);

        $this->assertSame(
            1,
            preg_match('/local\.file_match\s+"control_plane"\s*\{(.*?)\n\}/s', $config, $block),
            'alloy/config.alloy has no local.file_match "control_plane" block. If the application log is tailed by another component now, move this assertion with it.',
        );
        $this->assertSame(
            1,
            preg_match_all('/^\s*__path__\s*=\s*"([^"]+)"/m', $block[1], $paths),
            'The control_plane file_match must name exactly one __path__.',
        );

        return $paths[1][0];
    }

    private function releaseDirectory(): string
    {
        $defaults = $this->read(self::ROLE_DEFAULTS);
        $all = $this->read(self::GROUP_VARS);

        $this->assertSame(
            1,
            preg_match('/^control_plane_release_dir:\s*"?([^"\n]+?)"?\s*$/m', $defaults, $release),
            'The control_plane role no longer declares control_plane_release_dir in its defaults.',
        );
        $this->assertSame(
            1,
            preg_match('/^lynomia_base_dir:\s*"?([^"\n]+?)"?\s*$/m', $all, $base),
            'group_vars/all.yml no longer declares lynomia_base_dir.',
        );

        $resolved = str_replace('{{ lynomia_base_dir }}', $base[1], $release[1]);

        $this->assertStringNotContainsString('{{', $resolved, 'The release directory refers to a variable this test does not resolve: '.$release[1]);

        return rtrim($resolved, '/');
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 4).$relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
