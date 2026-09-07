<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — the boot chain must name the PXE server's address on the
 * provisioning VLAN, never `ansible_host`.
 *
 * roles/pxe exists so that a machine being installed talks to one interface and
 * one network: `dhcp-boot` and the iPXE script's `base` are the two places the
 * platform tells a booting machine where to fetch its installer profile and its
 * unattended answer file from, and those files carry root password hashes and
 * provisioning keys.
 *
 * Both were built from `{{ ansible_host }}` — the MANAGEMENT address. In both
 * committed inventories that address is on a different network from
 * `pxe_provisioning_cidr` (production 203.0.113.91 against 192.0.2.0/24), and
 * the role's own firewall rules open port 80 on `pxe_provisioning_interface`
 * only. So either the machine cannot route to the URL it was handed and a paid
 * dedicated build stalls after DHCP looking like bad hardware, or the address
 * was made reachable and the answer files are served to the management network
 * — the boundary the role's header calls "a cabling mistake but never a
 * configuration mistake".
 *
 * The fix is a required per-host `pxe_provisioning_address`, asserted to be
 * inside `pxe_provisioning_cidr` by the same controller-side containment check
 * that already guards the DHCP range.
 */
final class PxeBootUrlStaysOnTheProvisioningVlanTest extends TestCase
{
    #[Test]
    public function neither_boot_template_names_the_management_address(): void
    {
        foreach (['dnsmasq-provisioning.conf.j2', 'boot.ipxe.j2'] as $template) {
            $body = $this->ansible('roles/pxe/templates/'.$template);

            $this->assertStringNotContainsString(
                'ansible_host',
                $body,
                "{$template} builds a URL from `ansible_host`, which is this host's management "
                .'address — on a different network from the provisioning VLAN, and on an '
                ."interface where the role's own rules drop port 80.",
            );

            $this->assertStringContainsString('pxe_provisioning_address', $body);
        }
    }

    #[Test]
    public function the_role_refuses_a_host_that_has_not_declared_its_provisioning_address(): void
    {
        $defaults = $this->ansible('roles/pxe/defaults/main.yml');
        $tasks = $this->ansible('roles/pxe/tasks/main.yml');

        $this->assertMatchesRegularExpression(
            '/^pxe_provisioning_address:\s*~\s*$/m',
            $defaults,
            'roles/pxe declares no `pxe_provisioning_address`, so the boot chain has no address '
            .'on the provisioning VLAN to name and no default that fails closed.',
        );

        $this->assertStringContainsString(
            '- pxe_provisioning_address is not none',
            $tasks,
            'Nothing refuses a PXE host that never declared its provisioning address.',
        );

        // The containment check must actually receive the address, and must no
        // longer be skippable by leaving the DHCP range undeclared.
        $check = mb_strstr($tasks, 'import ipaddress, sys');
        $this->assertIsString($check);
        $this->assertStringContainsString('"{{ pxe_provisioning_address }}"', mb_substr((string) $check, 0, 900));
    }

    #[Test]
    public function every_inventory_pxe_host_declares_an_address_inside_its_provisioning_network(): void
    {
        $checked = 0;

        foreach (glob($this->ansibleRoot().'/inventories/*/hosts.yml') ?: [] as $path) {
            $body = (string) file_get_contents($path);

            preg_match_all('/^\s*pxe_provisioning_cidr:\s*(\S+)\s*$/m', $body, $cidrs);
            preg_match_all('/^\s*pxe_provisioning_address:\s*(\S+)\s*$/m', $body, $addresses);

            $this->assertSameSize(
                $cidrs[1],
                $addresses[1],
                basename(dirname($path)).' declares a provisioning network for a PXE host without '
                .'declaring that host\'s own address on it. The boot URL then has to come from '
                .'somewhere else, and the only other address is the management one.',
            );

            foreach ($cidrs[1] as $i => $cidr) {
                $this->assertTrue(
                    $this->contains($cidr, $addresses[1][$i]),
                    "{$addresses[1][$i]} is not inside {$cidr} in ".basename(dirname($path)).'.',
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'No inventory PXE host was checked; the paths are wrong.');
    }

    private function contains(string $cidr, string $address): bool
    {
        [$network, $bits] = explode('/', $cidr);

        $mask = -1 << (32 - (int) $bits);

        return (ip2long($network) & $mask) === (ip2long($address) & $mask);
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
