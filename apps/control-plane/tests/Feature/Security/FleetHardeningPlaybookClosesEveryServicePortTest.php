<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — applying roles/hardening without collecting a host's inbound
 * rules must refuse, not write a ruleset that drops every service port.
 *
 * roles/hardening writes a COMPLETE nftables ruleset with `policy drop` whose
 * only inbound exceptions come from `hardening_allowed_inbound`, which defaults
 * to `[]`. Every service playbook (control-plane.yml, database.yml, redis.yml,
 * monitoring.yml, proxmox-postinstall.yml, pbs-configure.yml, pxe.yml) unions
 * its roles' `*_inbound_rules` into that variable in a pre_task before applying
 * the role.
 *
 * playbooks/hardening.yml does not, and it targets `all:!dedicated:!firewall` —
 * fourteen hosts in the committed production inventory, including all three
 * control-plane hosts and both shared-hosting nodes. One documented command
 * therefore closed 443 on the public API, 5432 on the database, 8006 on the
 * hypervisors, every web and mail port on nodes full of customer sites, and
 * 9100 on all of them at the same instant, so the monitoring that would have
 * reported it went down with everything else.
 *
 * The fix is a refusal in the role: a host in `hardening_service_groups` that
 * reaches it with no exceptions collected stops the play instead of being
 * firewalled off from its own customers.
 *
 * These assertions read the Ansible tree because that tree has no test runner
 * of its own; this suite is the only place a guard in it can be pinned.
 */
final class FleetHardeningPlaybookClosesEveryServicePortTest extends TestCase
{
    #[Test]
    public function the_hardening_role_refuses_a_service_host_with_no_inbound_exceptions_collected(): void
    {
        $tasks = $this->ansible('roles/hardening/tasks/main.yml');

        $guard = $this->taskBlock($tasks, 'Refuse to build a ruleset that drops this host\'s own service ports');

        $this->assertNotNull(
            $guard,
            'roles/hardening has no task refusing a host that runs a service when no inbound '
            .'exceptions were collected. Without it, any play that applies the role without a '
            .'collection pre_task writes a drop-everything ruleset on a live service host.',
        );

        $this->assertStringContainsString('ansible.builtin.assert', $guard);
        $this->assertStringContainsString('hardening_service_groups', $guard);
        $this->assertStringContainsString('hardening_allowed_inbound', $guard);
        $this->assertStringContainsString('group_names', $guard);
    }

    #[Test]
    public function the_guard_runs_before_the_ruleset_is_written(): void
    {
        $tasks = $this->ansible('roles/hardening/tasks/main.yml');

        $guard = mb_strpos($tasks, 'Refuse to build a ruleset that drops this host\'s own service ports');
        $write = mb_strpos($tasks, 'Write the firewall ruleset');

        $this->assertIsInt($guard);
        $this->assertIsInt($write);
        $this->assertLessThan(
            $write,
            $guard,
            'A check performed after the ruleset has been applied is a check performed from '
            .'outside the building.',
        );
    }

    #[Test]
    public function every_group_whose_playbook_collects_inbound_rules_is_named_as_a_service_group(): void
    {
        $declared = $this->serviceGroups();

        foreach ($this->groupsWhosePlaybookCollectsInboundRules() as $group => $playbook) {
            $this->assertContains(
                $group,
                $declared,
                "`{$playbook}` collects `*_inbound_rules` for the `{$group}` group, so those hosts "
                ."serve ports. `{$group}` must be in `hardening_service_groups` or a play that "
                .'forgets the collection will silently close them.',
            );
        }

        // Not derived: no playbook applies the hardening role to `hosting` on its
        // own, and there is no `hosting_inbound_rules` anywhere in the repository.
        // The panel owns a hosting node's firewall, web server and mail stack.
        $this->assertContains('hosting', $declared);
    }

    /** @return array<string, string> group name => playbook that collects its rules */
    private function groupsWhosePlaybookCollectsInboundRules(): array
    {
        $found = [];

        foreach (glob($this->ansibleRoot().'/playbooks/*.yml') ?: [] as $path) {
            $body = (string) file_get_contents($path);

            if (! str_contains($body, 'hardening_allowed_inbound:')) {
                continue;
            }

            if (preg_match('/^\s*hosts:\s*(\S+)\s*$/m', $body, $m) !== 1) {
                continue;
            }

            // Only plain single-group patterns; anything else is not a group name.
            if (preg_match('/^[a-z0-9_]+$/', $m[1]) === 1) {
                $found[$m[1]] = basename($path);
            }
        }

        $this->assertNotEmpty($found, 'No playbook was found collecting inbound rules; the paths are wrong.');

        return $found;
    }

    /** @return list<string> */
    private function serviceGroups(): array
    {
        $defaults = $this->ansible('roles/hardening/defaults/main.yml');

        if (preg_match('/^hardening_service_groups:\n((?:\s+-\s+\S+\n)+)/m', $defaults, $m) !== 1) {
            $this->fail(
                'roles/hardening/defaults/main.yml declares no `hardening_service_groups`. '
                .'The guard that stops a drop-everything ruleset has nothing to check against.',
            );
        }

        preg_match_all('/-\s+(\S+)/', $m[1], $entries);

        return $entries[1];
    }

    private function taskBlock(string $tasks, string $name): ?string
    {
        $start = mb_strpos($tasks, '- name: '.$name);

        if ($start === false) {
            return null;
        }

        $next = mb_strpos($tasks, "\n- name: ", $start + 1);

        return $next === false
            ? mb_substr($tasks, $start)
            : mb_substr($tasks, $start, $next - $start);
    }

    private function ansible(string $relative): string
    {
        $path = $this->ansibleRoot().'/'.$relative;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function ansibleRoot(): string
    {
        return dirname(base_path(), 2).'/infrastructure/ansible';
    }
}
