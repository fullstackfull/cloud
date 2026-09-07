<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — a check that could not run is not a check that passed.
 *
 * Two Ansible roles read state with `failed_when: false` and then gated a
 * safety decision on that command's exit code, so a command that could not run
 * produced the permissive answer:
 *
 *  - roles/proxmox_cluster ran `pvecm status` and set
 *    `proxmox_cluster_current_name` to '' whenever rc != 0. Every guard, and
 *    both irreversible `pvecm` commands, are gated on that value being ''. A
 *    node that is already a cluster member with corosync degraded is therefore
 *    indistinguishable from a standalone node, and the role's own "already in a
 *    different cluster" assertion — the one that exists to stop /etc/pve, and
 *    with it the definition of every customer machine on the node, being
 *    replaced — cannot fire in exactly the case it was written for.
 *
 *  - roles/postgresql ran `pg_controldata` and raised its "this cluster has no
 *    data checksums" failure only when rc == 0, so a control file that could not
 *    be read passed the policy check with a green run. The same file already
 *    handles the analogous `pending_restart` case correctly, so this was an
 *    asymmetry rather than a stated policy.
 *
 * Each role now distinguishes "no" from "could not tell". These assertions read
 * the Ansible tree because that tree has no test runner of its own.
 */
final class FailOpenExitCodeGuardsInInfrastructureRolesTest extends TestCase
{
    #[Test]
    public function a_degraded_proxmox_node_is_not_read_as_a_node_in_no_cluster(): void
    {
        $tasks = $this->ansible('roles/proxmox_cluster/tasks/main.yml');

        $this->assertStringContainsString(
            '/etc/pve/corosync.conf',
            $tasks,
            'roles/proxmox_cluster decides cluster membership only from `pvecm status`, which '
            .'runs with `failed_when: false`. /etc/pve/corosync.conf is the unambiguous '
            .'membership signal and roles/proxmox already reads it the same way.',
        );

        $guard = $this->taskBlock($tasks, 'Refuse to act on a node whose cluster membership could not be determined');

        $this->assertNotNull(
            $guard,
            'Nothing refuses a node whose cluster membership could not be read, so `pvecm add` '
            .'stays reachable on a node that already holds a cluster identity.',
        );

        $this->assertStringContainsString('proxmox_cluster_status.rc == 0', $guard);
        $this->assertStringContainsString('stat.exists', $guard);

        // And it has to run before the value every later guard is gated on is computed.
        $this->assertLessThan(
            (int) mb_strpos($tasks, 'proxmox_cluster_current_name: >-'),
            (int) mb_strpos($tasks, 'Refuse to act on a node whose cluster membership could not be determined'),
        );
    }

    #[Test]
    public function an_unreadable_postgres_control_file_is_not_a_passing_checksum_policy(): void
    {
        $tasks = $this->ansible('roles/postgresql/tasks/main.yml');

        $guard = $this->taskBlock($tasks, "Refuse to report success when the cluster's checksum state could not be read");

        $this->assertNotNull(
            $guard,
            'roles/postgresql reports a cluster without data checksums only when pg_controldata '
            .'succeeded, so a control file that could not be read passes the policy check '
            .'silently — the exact case the `pending_restart` check below it refuses.',
        );

        $this->assertStringContainsString('ansible.builtin.fail', $guard);
        $this->assertStringContainsString('postgresql_controldata.rc | default(1) != 0', $guard);
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
