<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\DTOs\SsoSession;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingNodeNotConfiguredException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPanelSessionFailedException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPanelSessionUnavailableException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\UnknownHostingPanelException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Broker one short-lived session into a customer's own control panel.
 *
 * The session is created server-side through the vendor's own session endpoint
 * — WHM's create_user_session, DirectAdmin's login keys — and the URL it
 * returns carries a one-time token the panel invalidates on use or expiry. The
 * customer's panel password is never sent to a browser because the platform
 * does not hold one: there is no password column on hosting_accounts and there
 * never will be. Replaying a password into a login form would be the
 * alternative, and it would put a credential the platform should not have into
 * the browser, the browser's history, and every extension that can read the
 * page.
 *
 * Four things this action deliberately does not do:
 *
 *  - **It does not check the URL itself.** The adapter does, in
 *    assertSsoUrlBelongsToNode(): a node is exactly the machine a reseller
 *    operates or an attacker has taken, and a session URL is response data
 *    from it. Returned verbatim it is a link the portal invites the customer
 *    to click, pointing at a login page of the node's choosing. That check
 *    lives with the adapter that parsed the answer and knows which endpoint it
 *    dialled; duplicating it here would be a second implementation to drift.
 *
 *  - **It does not retry.** A createSsoSession that timed out has very
 *    possibly been accepted at the panel, and a second attempt is a second
 *    live session for the same account. The platform stopped waiting; the
 *    panel did not stop working.
 *
 *  - **It does not persist the session.** The URL is the credential for its
 *    lifetime. It is returned to the caller and then forgotten — not logged,
 *    not cached, not written to an audit row. What may be recorded is that
 *    somebody asked for a session and when, which is what
 *    {@see SsoSession::describe()} is for.
 *
 *  - **It does not fall back.** A refusal from the panel is reported as a
 *    provider failure. There is no "if SSO is unavailable, reset the password
 *    and return it instead" path, because that would mint a durable credential
 *    to work around the absence of an ephemeral one.
 *
 * What it does do, and what nothing else in this module has to, is translate a
 * panel failure into something a customer may read. Every adapter records the
 * node's hostname, the function it called and the panel's own words in the
 * exception context so that an operator can find the machine; the shared
 * renderer publishes a DomainException's context as `error.details`. This is
 * the only place in the module where those two facts meet a customer-facing
 * response, so it is the place that has to keep them apart — see
 * {@see HostingPanelSessionFailedException}. The provider's exception is
 * chained, never discarded: the log keeps everything.
 */
final readonly class IssueHostingPanelSession
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @throws HostingPanelSessionUnavailableException
     * @throws HostingPanelSessionFailedException
     */
    public function execute(HostingAccount $account): SsoSession
    {
        $id = (string) $account->getKey();

        if ($account->status === HostingAccountStatus::Pending) {
            throw HostingPanelSessionUnavailableException::notReady($id, $account->status);
        }

        if ($account->status === HostingAccountStatus::Suspended) {
            throw HostingPanelSessionUnavailableException::suspended($id);
        }

        if (! $account->status->existsAtPanel()) {
            throw HostingPanelSessionUnavailableException::gone($id, $account->status);
        }

        $node = $account->node()->firstOrFail();

        if (! $node->status->holdsAccounts()) {
            throw HostingPanelSessionUnavailableException::nodeOffline($id);
        }

        try {
            return $this->providers->for($node)->createSsoSession($node, $account->username);
        } catch (HostingProviderException $e) {
            /*
             * Not retried, and nothing is compensated — including when the
             * failure is indeterminate. A createSsoSession the platform
             * stopped waiting for may well have been accepted, and a second
             * attempt would be a second live session for the same account,
             * only one of which anybody will ever spend. There is also nothing
             * to release: no capacity was reserved and no row was written.
             *
             * Except when the cause was our own configuration. Every adapter
             * converts HostingNodeNotConfiguredException into this type before
             * it leaves — on purpose, so that a missing config key is not
             * flagged indeterminate and does not quarantine a working node —
             * which means catching the original type here would catch nothing.
             * Left as an ordinary provider failure it becomes "the panel could
             * not be reached, please try again shortly": advice that will never
             * come true, under an error code that sends support to look at a
             * machine that is fine.
             */
            if ($e->getPrevious() instanceof HostingNodeNotConfiguredException) {
                throw HostingPanelSessionFailedException::platformMisconfigured($id, $e);
            }

            throw HostingPanelSessionFailedException::panelUnreachable($id, $e);
        } catch (UnknownHostingPanelException $e) {
            // The node row names a panel this build cannot drive. Its context
            // names the node and the config key its root API token is read
            // from, which is precisely what must not travel.
            throw HostingPanelSessionFailedException::platformMisconfigured($id, $e);
        }
    }
}
