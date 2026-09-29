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
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A panel that really builds the account, and then the answer never arrives.
 *
 * ---------------------------------------------------------------------------
 * A decorator, not a simulator — and that is the point
 * ---------------------------------------------------------------------------
 *
 * The controlled provider's `TIMEOUT_MARKER` refuses *before* it records
 * anything, which rehearses the benign half of a lost answer: the platform
 * stopped waiting and nothing was built. The other half is the one that costs
 * something. The inner provider here creates the account for real — it holds
 * it, under the name and with the password it was handed — and only then does
 * the platform's side of the conversation fail as indeterminate.
 *
 * That is the state F-04's last clause is about: a live account holding a
 * credential the platform generated, set, and by design kept nowhere. The only
 * way out of it is to set a new one, which is what `changePassword` is for.
 *
 * Every other call is passed straight through, and the requests it was handed
 * are recorded so a test can say what reached the machine.
 */
final class PanelThatLosesItsAnswer implements HostingProvider
{
    /** @var list<CreateAccountRequest> */
    public array $creates = [];

    /** @var list<array{username: string, password: string}> */
    public array $passwordChanges = [];

    public function __construct(private readonly HostingProvider $inner) {}

    public function panel(): HostingPanel
    {
        return $this->inner->panel();
    }

    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult
    {
        $this->creates[] = $request;

        // Built. The panel holds it from here on.
        $this->inner->createAccount($node, $request);

        // And the platform never hears so.
        throw HostingProviderException::requestFailed(
            $this->inner->panel()->value,
            'create_account',
            ['node' => $node->hostname, 'username' => $request->username],
            indeterminate: true,
        );
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

        $this->passwordChanges[] = ['username' => $username, 'password' => $password];
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
