<?php

declare(strict_types=1);

namespace Tests\Support;

use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressInstaller;
use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingAccountResult;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodeHealth;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallation;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;

/**
 * A controlled panel that writes down every WordPress install it was asked for.
 *
 * ---------------------------------------------------------------------------
 * Why the install request is the thing worth recording (F-45)
 * ---------------------------------------------------------------------------
 *
 * The administrator password a WordPress install is handed is kept nowhere by
 * design: not on the site row, not on the job, and not by the controlled
 * panel, which records the installation and never the credential. So no test
 * could say what the installer received — and for as long as nobody could,
 * it received the ten characters `[redacted]`, because the password travelled
 * in a job payload the redactor rewrites on the way into the row.
 *
 * What the installer was *handed* is the only place that defect is
 * observable, so that is what this keeps. It is a decorator rather than a
 * second simulator: every answer comes from the real controlled provider
 * underneath, whose decision to keep no password is untouched, and nothing
 * here decides anything about the panel's behaviour.
 */
final class RecordingWordPressInstaller implements HostingProvider, WordPressInstaller
{
    /** @var list<WordPressInstallRequest> every install, in order */
    public array $installs = [];

    public function __construct(private readonly FakeHostingProvider $inner) {}

    public function installWordPress(HostingNode $node, WordPressInstallRequest $request): WordPressInstallation
    {
        $this->installs[] = $request;

        return $this->inner->installWordPress($node, $request);
    }

    public function wordPressInstallation(HostingNode $node, string $username, string $domain): WordPressInstallation
    {
        return $this->inner->wordPressInstallation($node, $username, $domain);
    }

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
        return $this->inner->licenceStatus($node);
    }

    public function createSsoSession(HostingNode $node, string $username): SsoSession
    {
        return $this->inner->createSsoSession($node, $username);
    }
}
