<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingAccountResult;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodeHealth;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A controlled panel whose licence is valid on the panel's side.
 *
 * The controlled provider answers the licence from the node row, which is
 * right for the suite's licensing paths and circular for a node an operator
 * has just registered: the row says nothing is licensed because nothing has
 * asked the panel yet, so the controlled panel says the same, and a sync can
 * never make the node placeable. A real panel answers from its vendor, not
 * from our row. This stands in for that answer and nothing else; every other
 * call is the controlled provider's own.
 */
final class LicensedPanel implements HostingProvider
{
    public function __construct(private readonly HostingProvider $inner) {}

    public function panel(): HostingPanel
    {
        return $this->inner->panel();
    }

    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult
    {
        return $this->inner->createAccount($node, $request);
    }

    public function suspendAccount(HostingNode $node, string $username, string $reason): void
    {
        $this->inner->suspendAccount($node, $username, $reason);
    }

    public function unsuspendAccount(HostingNode $node, string $username): void
    {
        $this->inner->unsuspendAccount($node, $username);
    }

    public function terminateAccount(HostingNode $node, string $username): void
    {
        $this->inner->terminateAccount($node, $username);
    }

    public function changePackage(HostingNode $node, string $username, string $packageName): void
    {
        $this->inner->changePackage($node, $username, $packageName);
    }

    public function changePassword(HostingNode $node, string $username, string $password): void
    {
        $this->inner->changePassword($node, $username, $password);
    }

    public function accountUsage(HostingNode $node, string $username): AccountUsage
    {
        return $this->inner->accountUsage($node, $username);
    }

    public function listAccounts(HostingNode $node): array
    {
        return $this->inner->listAccounts($node);
    }

    public function nodeHealth(HostingNode $node): NodeHealth
    {
        return $this->inner->nodeHealth($node);
    }

    public function licenceStatus(HostingNode $node): LicenceStatus
    {
        return new LicenceStatus(
            valid: true,
            product: 'controlled-panel',
            state: 'active',
            expiresAt: CarbonImmutable::now()->addYear(),
            detail: 'the controlled panel reports its licence as active',
        );
    }

    public function createSsoSession(HostingNode $node, string $username): SsoSession
    {
        return $this->inner->createSsoSession($node, $username);
    }
}
