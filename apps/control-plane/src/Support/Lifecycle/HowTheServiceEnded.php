<?php

declare(strict_types=1);

namespace Lynomia\Support\Lifecycle;

use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * What ending one service actually did, in the terms both of its callers need.
 *
 * The operator's route answers with it and writes it into the audit trail;
 * the retention sweep writes it into its own audit entry and log line. Before
 * this they were two dispatch tables over the same three kinds — the route's
 * own `if dedicated … else VPS`, which sent every hosting service down the VPS
 * path, and EndOfService's — and they had already drifted apart.
 *
 * Four endings, and they are genuinely different facts:
 *
 *  - a VPS whose destroy is QUEUED — not done: the machine goes when a worker
 *    succeeds, and the service reaches `terminated` then;
 *  - a dedicated server DECOMMISSIONED to maintenance, holding the customer's
 *    disks until an operator says they are erased;
 *  - a hosting account TERMINATED at the panel, and the service with it;
 *  - NOTHING BUILT: no row and no build that could have left anything, so the
 *    service ended here and no provider was asked anything.
 */
final readonly class HowTheServiceEnded
{
    private function __construct(
        public string $detail,
        public ?string $provisioningJobId = null,
        public ?string $dedicatedServerId = null,
        public ?string $dedicatedServerSerial = null,
        public ?string $dedicatedServerStatus = null,
        public ?string $hostingAccountId = null,
        public ?string $hostingUsername = null,
    ) {}

    public static function destroyQueued(ProvisioningJob $job): self
    {
        return new self(
            detail: 'queued provisioning job '.$job->getKey(),
            provisioningJobId: (string) $job->getKey(),
        );
    }

    public static function decommissioned(DedicatedServer $server): self
    {
        return new self(
            // Deliberately not "returned to stock". It is in maintenance,
            // holding the customer's disks, until somebody says otherwise.
            detail: 'decommissioned dedicated server '.$server->getKey().' to maintenance',
            dedicatedServerId: (string) $server->getKey(),
            dedicatedServerSerial: $server->serial,
            dedicatedServerStatus: $server->status->value,
        );
    }

    public static function accountTerminated(HostingAccount $account): self
    {
        return new self(
            detail: 'terminated hosting account '.$account->getKey(),
            hostingAccountId: (string) $account->getKey(),
            hostingUsername: $account->username,
        );
    }

    public static function nothingWasBuilt(): self
    {
        return new self(detail: 'nothing was built for this service, so nothing was destroyed');
    }

    /** Whether the ending is still in a worker's hands. */
    public function isQueued(): bool
    {
        return $this->provisioningJobId !== null;
    }

    /**
     * The facts worth keeping beside the decision in the audit trail.
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return array_filter([
            'detail' => $this->detail,
            'provisioning_job_id' => $this->provisioningJobId,
            'dedicated_server_id' => $this->dedicatedServerId,
            'serial' => $this->dedicatedServerSerial,
            'hosting_account_id' => $this->hostingAccountId,
            'username' => $this->hostingUsername,
        ], static fn (?string $value): bool => $value !== null);
    }
}
