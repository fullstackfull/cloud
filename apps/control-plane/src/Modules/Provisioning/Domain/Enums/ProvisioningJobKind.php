<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * The units of work the engine knows how to dispatch.
 *
 * The list is deliberately stated in product terms rather than provider terms.
 * A handler for `create_vps` may talk to Proxmox, to libvirt or to a fake in
 * the test suite; the engine cannot tell, and must not be able to.
 */
enum ProvisioningJobKind: string
{
    case CreateVps = 'create_vps';
    case DestroyVps = 'destroy_vps';
    case Start = 'start';
    case Stop = 'stop';
    case Restart = 'restart';
    /*
     * Two reinstalls, not one, because they are two different pieces of work
     * that happen to share a word. Rebuilding a virtual machine is a config
     * edit and a disk import against a hypervisor API; rebuilding a physical
     * one is a one-time boot override, a PXE handshake and an unattended
     * installer that nothing can watch directly. They fail differently, they
     * are guarded differently, and — since the engine keys handlers by kind —
     * one name would mean one handler receiving both and deciding from the
     * payload which machine it had been given. That decision is exactly the
     * kind that is wrong once and destroys the wrong thing.
     */
    case ReinstallVps = 'reinstall_vps';
    case ReinstallDedicated = 'reinstall_dedicated';
    case Resize = 'resize';
    case Suspend = 'suspend';
    case Unsuspend = 'unsuspend';
    case CreateHostingAccount = 'create_hosting_account';
    case ProvisionDedicated = 'provision_dedicated';

    /**
     * How long the platform waits for this kind of work before it stops
     * waiting. Configured per kind because a dedicated server install and a
     * power-on differ by two orders of magnitude.
     */
    public function defaultTimeoutSeconds(): int
    {
        /** @var array<string, int> $configured */
        $configured = config('provisioning.timeout_seconds', []);

        return (int) ($configured[$this->value] ?? $configured['default'] ?? 900);
    }

    /**
     * Whether this kind brings a new provider resource into existence.
     *
     * This is the property that makes duplicates possible, so it is what
     * decides whether an unclassified failure is treated as "nothing happened"
     * or as "something may exist out there".
     */
    public function createsResource(): bool
    {
        return match ($this) {
            self::CreateVps, self::CreateHostingAccount, self::ProvisionDedicated => true,
            default => false,
        };
    }

    /**
     * The service status this kind leaves behind when it succeeds, or null
     * when the work does not change the service's lifecycle — a restart does
     * not make a service more or less bought.
     */
    public function serviceStatusOnSuccess(): ?ServiceStatus
    {
        return match ($this) {
            self::CreateVps, self::CreateHostingAccount, self::ProvisionDedicated, self::Unsuspend => ServiceStatus::Active,
            self::Suspend => ServiceStatus::Suspended,
            self::DestroyVps => ServiceStatus::Terminated,
            self::Start, self::Stop, self::Restart, self::Resize => null,
            self::ReinstallVps, self::ReinstallDedicated => null,
        };
    }

    /**
     * Whether failing this kind of work means the service itself failed.
     *
     * A failed reboot leaves an active service that could not be rebooted; a
     * failed build leaves a service that was never delivered.
     */
    public function failsServiceOnFailure(): bool
    {
        return $this->createsResource();
    }
}
