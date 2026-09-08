<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The check that decides whether a panel may be installed, now runnable.
 *
 * PreflightHostingNode was written, tested at the action level, and had no
 * caller. Every condition it refuses on produces a node that *looks*
 * installed and is broken in a way discovered later, by customers, on a
 * machine that by then has customers on it. A check nobody can run is a check
 * that gets skipped on the day somebody is in a hurry, which is the day it
 * matters.
 */
final class PreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_clean_machine_is_cleared_to_install(): void
    {
        $this->artisan('hosting:preflight', [
            'panel' => 'fake',
            '--facts' => $this->factsFile([
                'hostname' => 'node1.example.com',
                'os_id' => 'almalinux',
                'os_version' => '9',
                'forward_addresses' => ['203.0.113.5'],
                'reverse_hostnames' => ['node1.example.com'],
                'licence' => ['valid' => true, 'product' => 'fake'],
            ]),
        ])->assertExitCode(0);
    }

    #[Test]
    public function a_machine_that_already_serves_web_traffic_is_refused(): void
    {
        // A panel installed over an existing httpd inherits a configuration
        // neither product owns, and the node still lists accounts afterwards.
        $this->artisan('hosting:preflight', [
            'panel' => 'cpanel',
            '--facts' => $this->factsFile([
                'hostname' => 'node1.example.com',
                'os_id' => 'almalinux',
                'os_version' => '9',
                'conflicting_services' => ['httpd'],
                'licence' => ['valid' => true, 'product' => 'cpanel'],
            ]),
        ])->assertExitCode(1);
    }

    #[Test]
    public function a_bare_hostname_is_refused(): void
    {
        // The panel signs its own services and stamps outgoing mail with this
        // name. A bare label cannot be certified and cannot pass a receiving
        // mail server's checks.
        $this->artisan('hosting:preflight', [
            'panel' => 'cpanel',
            '--facts' => $this->factsFile([
                'hostname' => 'node1',
                'os_id' => 'almalinux',
                'os_version' => '9',
                'licence' => ['valid' => true, 'product' => 'cpanel'],
            ]),
        ])->assertExitCode(1);
    }

    #[Test]
    public function a_licence_the_collector_could_not_check_is_not_treated_as_valid(): void
    {
        /*
         * The whole point of the optional field. A collector that could not
         * reach the vendor must not have its silence read as a licence: the
         * panel installs perfectly and then refuses to serve, leaving a node
         * that looks ready and takes no accounts.
         */
        $facts = [
            'hostname' => 'node1.example.com',
            'os_id' => 'almalinux',
            'os_version' => '9',
            'forward_addresses' => ['203.0.113.5'],
            'reverse_hostnames' => ['node1.example.com'],
        ];

        $this->artisan('hosting:preflight', ['panel' => 'cpanel', '--facts' => $this->factsFile($facts)])
            ->assertExitCode(1);

        // And an explicit "we asked and the answer was no" is refused too, so
        // the refusal above is not an artefact of the key being absent.
        $facts['licence'] = ['valid' => false, 'product' => 'cpanel', 'detail' => 'expired'];

        $this->artisan('hosting:preflight', ['panel' => 'cpanel', '--facts' => $this->factsFile($facts)])
            ->assertExitCode(1);
    }

    #[Test]
    public function an_unknown_panel_is_refused_rather_than_guessed_at(): void
    {
        $this->artisan('hosting:preflight', [
            'panel' => 'not-a-panel',
            '--facts' => $this->factsFile(['hostname' => 'node1.example.com']),
        ])->assertExitCode(2);
    }

    #[Test]
    public function unreadable_facts_stop_the_install_rather_than_passing_it(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'facts');
        file_put_contents((string) $path, 'not json at all');

        try {
            // Never exit 0. A malformed facts file must not be the thing that
            // lets an install proceed unchecked.
            $this->artisan('hosting:preflight', ['panel' => 'cpanel', '--facts' => $path])
                ->assertExitCode(2);
        } finally {
            @unlink((string) $path);
        }
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function factsFile(array $facts): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'facts');

        file_put_contents($path, json_encode($facts, JSON_THROW_ON_ERROR));

        // Removed when the process ends rather than in a finally: the command
        // reads it during the assertion, and several tests write more than one.
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
