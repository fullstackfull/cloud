<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Services;

use Lynomia\Modules\Infrastructure\Domain\DTOs\ComponentDefinition;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ProfileDefinition;
use Lynomia\Modules\Infrastructure\Domain\Enums\PlanRisk;

/**
 * Every component the platform can put on a machine, and every profile it
 * can build, in source.
 *
 * In source rather than in an admin form for the same reason the provider
 * catalogue is: a component names an Ansible role, and an operator typing a
 * role name is an operator typing an argument to a playbook. An architecture
 * test checks that every role named here exists in infrastructure/ansible,
 * so a component pointing at a role nobody wrote fails the build rather than
 * the run.
 *
 * The risk on each component is a statement about what the role does to a
 * machine that already has customers on it. It is what the plan takes the
 * worst of, and what decides the safety classification a plan requires.
 *
 * `accepts` is the whole of the override surface. A key not listed for a
 * component in the profile is refused at assignment, before it is stored.
 */
final readonly class SoftwareCatalogue
{
    /**
     * @return list<ComponentDefinition>
     */
    public function components(): array
    {
        return [
            new ComponentDefinition('common', 'Base system', 'base', 'common', PlanRisk::Low, verification: null, accepts: ['timezone'], description: 'Packages, time, users and the management SSH key every platform host has.'),
            new ComponentDefinition('hardening', 'Host hardening', 'security', 'hardening', PlanRisk::Moderate, verification: 'service:fail2ban', dependsOn: ['common'], accepts: ['ssh_port'], description: 'SSH policy, nftables baseline, fail2ban, auditd.'),
            new ComponentDefinition('nginx', 'Nginx', 'web', 'nginx', PlanRisk::Moderate, verification: 'service:nginx', dependsOn: ['common'], accepts: ['server_name'], description: 'The reverse proxy in front of the control plane.'),
            new ComponentDefinition('php_fpm', 'PHP-FPM', 'runtime', 'php_fpm', PlanRisk::Moderate, verification: 'service:php8.4-fpm', dependsOn: ['common'], accepts: ['pm_max_children'], description: 'The PHP runtime the control plane runs on.'),
            new ComponentDefinition('control_plane', 'Lynomia control plane', 'application', 'control_plane', PlanRisk::High, verification: 'port:443', dependsOn: ['nginx', 'php_fpm'], accepts: ['app_url'], description: 'The API, Horizon workers and scheduler.'),
            new ComponentDefinition('postgresql', 'PostgreSQL', 'database', 'postgresql', PlanRisk::High, verification: 'service:postgresql', dependsOn: ['common'], accepts: ['max_connections'], description: 'The platform database. High: a wrong change here is a wrong change to every record.'),
            new ComponentDefinition('redis', 'Redis', 'cache', 'redis', PlanRisk::Moderate, verification: 'service:redis-server', dependsOn: ['common'], accepts: ['maxmemory'], description: 'Queues, cache and sessions.'),
            new ComponentDefinition('monitoring', 'Monitoring stack', 'observability', 'monitoring', PlanRisk::Moderate, verification: 'port:9090', dependsOn: ['common'], accepts: ['retention_days'], description: 'Prometheus, Alertmanager, Grafana, Loki and Alloy.'),
            new ComponentDefinition('proxmox', 'Proxmox VE post-install', 'hypervisor', 'proxmox', PlanRisk::High, requiresReboot: true, verification: 'port:8006', dependsOn: ['common'], accepts: [], description: 'Repositories, subscription notice, ZFS ARC and the platform API token role. High and reboots: every VM on the node is affected.'),
            new ComponentDefinition('pbs', 'Proxmox Backup Server', 'backup', 'pbs', PlanRisk::High, verification: 'port:8007', dependsOn: ['common'], accepts: ['datastore_path'], description: 'The backup target. High: a wrong change here is a wrong change to the copies.'),
            new ComponentDefinition('hosting', 'Hosting node preflight', 'hosting', 'hosting', PlanRisk::Moderate, verification: null, dependsOn: ['common'], accepts: [], description: 'What the platform requires of a cPanel or DirectAdmin node before it will place accounts on it.'),
            new ComponentDefinition('pxe', 'PXE boot service', 'provisioning', 'pxe', PlanRisk::High, verification: 'port:69', dependsOn: ['common', 'hardening'], accepts: [], description: 'iPXE and DHCP on the provisioning VLAN only. High: DHCP on the wrong network takes down a rack.'),
        ];
    }

    /**
     * @return list<ProfileDefinition>
     */
    public function profiles(): array
    {
        return [
            new ProfileDefinition('control-plane', 'Control plane', 'control_plane', 'control-plane.yml', ['common', 'hardening', 'php_fpm', 'nginx', 'control_plane'], 'The API host: web, runtime, application.'),
            new ProfileDefinition('database', 'Database', 'database', 'database.yml', ['common', 'hardening', 'postgresql'], 'A PostgreSQL primary or replica.'),
            new ProfileDefinition('redis', 'Redis', 'redis', 'redis.yml', ['common', 'hardening', 'redis'], 'Queues, cache and sessions on their own host.'),
            new ProfileDefinition('monitoring', 'Monitoring', 'monitoring', 'monitoring.yml', ['common', 'hardening', 'monitoring'], 'The observability stack.'),
            new ProfileDefinition('proxmox-hypervisor', 'Proxmox hypervisor', 'proxmox', 'proxmox-postinstall.yml', ['common', 'proxmox'], 'A bare-metal Proxmox VE node after the vendor installer.'),
            new ProfileDefinition('backup-server', 'Backup server', 'pbs', 'pbs-configure.yml', ['common', 'hardening', 'pbs'], 'A Proxmox Backup Server on its own storage.'),
            new ProfileDefinition('hosting-node', 'Hosting node', 'hosting', 'hosting-preflight.yml', ['common', 'hosting'], 'A cPanel or DirectAdmin node, checked before accounts are placed.'),
            new ProfileDefinition('pxe', 'PXE boot host', 'pxe', 'pxe.yml', ['common', 'hardening', 'pxe'], 'The provisioning VLAN boot service.'),
        ];
    }

    public function component(string $key): ?ComponentDefinition
    {
        foreach ($this->components() as $component) {
            if ($component->key === $key) {
                return $component;
            }
        }

        return null;
    }

    public function profile(string $key): ?ProfileDefinition
    {
        foreach ($this->profiles() as $profile) {
            if ($profile->key === $key) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * The components of a profile, in order, resolved.
     *
     * @return list<ComponentDefinition>
     */
    public function componentsOf(ProfileDefinition $profile): array
    {
        return array_values(array_filter(array_map(fn (string $key): ?ComponentDefinition => $this->component($key), $profile->components)));
    }
}
