<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Contracts;

use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingAccountResult;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodeHealth;
use Lynomia\Modules\SharedHosting\Domain\DTOs\RemoteAccount;
use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * Everything the platform is allowed to ask a control panel to do.
 *
 * Stated entirely in domain types: nothing above this line may accept an HTTP
 * response, catch a transport exception, or know that WHM answers in JSON
 * while DirectAdmin answers in url-encoded form. That constraint is what makes
 * ordering, billing and provisioning free of panel-specific branches — only
 * the adapters have them — and what makes adding a third panel an additive
 * change rather than a rewrite.
 *
 * The node is passed to every method rather than bound into the adapter,
 * because a hosting adapter is not a singleton the way a payment adapter is:
 * every node has its own endpoint, its own certificate policy and its own root
 * API token. The adapter is one object per panel; the connection is built per
 * call from the node row and the credential that row references.
 *
 * Four rules bind every implementation:
 *
 *  - failures throw {@see HostingProviderException}, never a client exception,
 *    and never with a credential in the message or the context. A WHM root API
 *    token is root on a machine holding several hundred customers' websites,
 *    databases and mail;
 *
 *  - a panel's own success flag is what decides success, not the HTTP status.
 *    WHM answers a rejected createacct with HTTP 200 and `metadata.result: 0`,
 *    and DirectAdmin answers one with HTTP 200 and `error=1`. An adapter that
 *    trusts the status code reports an account as created that does not exist,
 *    which is the classic integration bug in both products and the one this
 *    interface exists to make impossible to repeat quietly;
 *
 *  - a timeout is reported as indeterminate, never as a failure. createAccount
 *    builds a home directory, a mail store, a database user and a DNS zone
 *    before the panel answers; a request the platform stopped waiting for may
 *    well have been accepted, and retrying it is how a customer ends up with
 *    two accounts;
 *
 *  - the read methods never mutate. accountUsage(), listAccounts(),
 *    nodeHealth() and licenceStatus() are what reconciliation, billing and the
 *    licence sweep run on a schedule against the whole fleet, and a read path
 *    with a side effect there would be a fleet-wide side effect.
 */
interface HostingProvider
{
    /**
     * The panel this adapter speaks. It is the registry key and is persisted
     * in hosting_nodes.panel, so it must never change once a row exists.
     */
    public function panel(): HostingPanel;

    /**
     * Open an account.
     *
     * The result echoes the panel's own account name back rather than the
     * requested one: a panel that shortened or altered it has just made the
     * platform's copy wrong, and every later call would address an account
     * that does not exist.
     *
     * @throws HostingProviderException
     */
    public function createAccount(HostingNode $node, CreateAccountRequest $request): HostingAccountResult;

    /**
     * Stop serving an account while preserving all of it.
     *
     * Suspension keeps files, mail, databases and DNS exactly as they are and
     * is undone by one call. The reason is passed through to the panel so that
     * the customer sees why when they log in, and so an operator inspecting the
     * node does not have to guess.
     *
     * @throws HostingProviderException
     */
    public function suspendAccount(HostingNode $node, string $username, string $reason): void;

    /**
     * @throws HostingProviderException
     */
    public function unsuspendAccount(HostingNode $node, string $username): void;

    /**
     * Remove an account and everything belonging to it.
     *
     * Irreversible at the panel. Nothing in this module calls it without the
     * retention window having elapsed or an explicit override.
     *
     * @throws HostingProviderException
     */
    public function terminateAccount(HostingNode $node, string $username): void;

    /**
     * Move an account onto a different panel package.
     *
     * $packageName is the name as the PANEL knows it — hosting_packages
     * .panel_package_name — not the platform's slug.
     *
     * @throws HostingProviderException
     */
    public function changePackage(HostingNode $node, string $username, string $packageName): void;

    /**
     * Set an account's panel password.
     *
     * The platform never keeps the value it sets: this is how a reset is
     * performed, not how a password is stored.
     *
     * @throws HostingProviderException
     */
    public function changePassword(HostingNode $node, string $username, string $password): void;

    /**
     * What one account is using.
     *
     * Every measurement in the result is nullable, and callers must treat a
     * missing figure as "the panel did not say" rather than as zero. A panel
     * mid-restart answers with an empty summary, and writing that as zero
     * lifts every quota the platform enforces and bills the customer for
     * nothing.
     *
     * @throws HostingProviderException
     */
    public function accountUsage(HostingNode $node, string $username): AccountUsage;

    /**
     * Every account the panel holds.
     *
     * This is the input to reconciliation: accounts the node has that the
     * platform has no row for, and rows the platform has that the node does
     * not. Both are billing errors, in opposite directions, and neither is
     * visible from inside the platform alone.
     *
     * @return list<RemoteAccount>
     *
     * @throws HostingProviderException
     */
    public function listAccounts(HostingNode $node): array;

    /**
     * The node's own account of its load and disk.
     *
     * @throws HostingProviderException
     */
    public function nodeHealth(HostingNode $node): NodeHealth;

    /**
     * What the vendor says about this node's licence.
     *
     * Reported, never worked around. cPanel/WHM, DirectAdmin, CloudLinux and
     * LiteSpeed are commercial products, and an implementation of this method
     * that inferred, extended or emulated a licence would be a licensing
     * circumvention rather than an integration. A node whose answer is invalid
     * takes no accounts.
     *
     * @throws HostingProviderException
     */
    public function licenceStatus(HostingNode $node): LicenceStatus;

    /**
     * Broker a short-lived panel session for a customer.
     *
     * Created server-side through the vendor's official session endpoint. The
     * customer's panel password is never sent to the browser and is never held
     * by the platform in the first place, so there is nothing here that could
     * be replayed into a login form.
     *
     * @throws HostingProviderException
     */
    public function createSsoSession(HostingNode $node, string $username): SsoSession;
}
