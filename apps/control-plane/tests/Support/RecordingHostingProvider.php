<?php

declare(strict_types=1);

namespace Tests\Support;

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
 * A controlled panel that also writes down exactly what it was handed.
 *
 * ---------------------------------------------------------------------------
 * Why the request is the thing worth recording
 * ---------------------------------------------------------------------------
 *
 * F-04's defect was invisible to every test in the tree because the controlled
 * panel never read the password, the contact address or the domain it was
 * given: an empty credential and a `.invalid` name were accepted exactly as a
 * real one was. Asserting on the account row afterwards cannot see it either —
 * the row carries no password by design. What the panel was *handed* is the
 * only place the defect is observable, so that is what this keeps.
 *
 * It is a decorator rather than a second simulator. Every answer comes from
 * the real controlled provider underneath; nothing here decides anything, so
 * nothing here can be wrong about the panel's behaviour.
 */
final class RecordingHostingProvider implements HostingProvider
{
    /** @var list<CreateAccountRequest> every create, in order */
    public array $creates = [];

    /** @var list<array{username: string, password: string}> every password set, in order */
    public array $passwordChanges = [];

    public function __construct(private readonly HostingProvider $inner) {}

    public function panel(): HostingPanel
    {
        return $this->inner->panel();
    }

    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult
    {
        $this->creates[] = $request;

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
        $this->passwordChanges[] = ['username' => $username, 'password' => $password];

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
        return $this->inner->licenceStatus($node);
    }

    public function createSsoSession(HostingNode $node, string $username): SsoSession
    {
        return $this->inner->createSsoSession($node, $username);
    }
}
