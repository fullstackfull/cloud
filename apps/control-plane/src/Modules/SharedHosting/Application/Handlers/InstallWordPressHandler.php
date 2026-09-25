<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Handlers;

use Illuminate\Support\Str;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressInstaller;
use Lynomia\Modules\SharedHosting\Domain\DTOs\WordPressInstallRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;

/**
 * The second step of a WordPress order: put WordPress in the account.
 *
 * ===========================================================================
 * WHY THIS IS A SEPARATE JOB FROM CREATING THE ACCOUNT
 * ===========================================================================
 *
 * Because they fail differently and a customer should not lose both.
 *
 * Creating a hosting account is capacity work: it can be placed on another
 * node, and it either happens or it does not. Installing WordPress is toolkit
 * work against an account that already exists: when it fails, the customer
 * still has hosting they can use, and the honest outcome is a site that says
 * "the install did not finish" rather than an order rolled back to nothing.
 *
 * It also lets the two carry different retry rules, which they need: a create
 * that timed out must never be retried, and a *read* of whether WordPress is
 * there is safe to repeat all day.
 *
 * ===========================================================================
 * THE TIMEOUT RULE, AGAIN, AND WHAT IT COSTS HERE
 * ===========================================================================
 *
 * An installer that stops answering has often finished the work. Installing
 * again over the top of it is not a harmless retry: it rewrites wp-config,
 * re-seeds the database, and takes with it whatever the customer wrote in the
 * hour they had the site. So an indeterminate install ends the job — the site
 * goes to `indeterminate`, and reconciliation asks the panel what is actually
 * there.
 *
 * That is why the handler asks the panel first, before installing at all: the
 * cheapest way to avoid installing twice is to look.
 *
 * ===========================================================================
 * THE ADMINISTRATOR PASSWORD IS MINTED HERE, AND NEVER READ FROM THE JOB (F-45)
 * ===========================================================================
 *
 * It used to arrive in the payload. The listener that queues this job minted
 * it and put it there, and the payload column is cast through
 * RedactedJsonCast, whose redactor matches `password` inside `admin_password`
 * — so what was stored, and what this handler read back and handed to the
 * installer, was the ten characters `[redacted]`. Every site this path built
 * would have had the same publicly known administrator password, and the
 * install succeeded.
 *
 * Two changes, and each covers what the other cannot:
 *
 *  - the password is minted here, at the moment of the install, handed to the
 *    installer and dropped. See ADMIN_PASSWORD_LENGTH.
 *
 *  - a job whose payload carries `admin_password` at all is refused, Permanent,
 *    before anything else is read. The cast stops a credential being kept,
 *    and says the loud failure is the handler's to supply; this is it. The
 *    test is the key and not the value on purpose. A value test — refuse the
 *    placeholder, refuse a blank — has an arm that can never fire (the
 *    redactor turns a blank into the placeholder too) and a hole: were
 *    `password` ever edited out of the redaction list, a live credential
 *    would sit in the column in the clear and pass straight through. The
 *    invariant is that a credential is never persisted in order to be sent to
 *    a provider, and the key is what states it.
 */
final readonly class InstallWordPressHandler implements ProvisioningHandler
{
    /**
     * The length of the administrator password minted for every install.
     *
     * From the CSPRNG, and never derived from anything the customer told us,
     * which is how a "generated" password ends up being the domain name with
     * a number after it.
     *
     * Carried over unchanged from the listener that used to mint it, symbols
     * and all. The panel password leaves symbols out for a reason
     * CreateHostingAccountHandler gives about how two panels encode a form
     * body. What a real WordPress toolkit accepts has never been established
     * in this repository, so the choice is not re-made here on a guess.
     */
    public const int ADMIN_PASSWORD_LENGTH = 24;

    public function __construct(
        private HostingProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    public function kind(): ProvisioningJobKind
    {
        return ProvisioningJobKind::InstallWordPress;
    }

    public function execute(ProvisioningJob $job): ProvisioningResult
    {
        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        if (array_key_exists('admin_password', $payload)) {
            /*
             * Whatever it holds: the redactor's placeholder, which is what the
             * cast leaves, or a live credential, which is what it leaves if
             * the redaction list ever stops matching. Neither is installed
             * with, and the value is not repeated in the failure. Permanent,
             * because the same payload will carry the same key next time; the
             * fix is in whatever wrote it.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'wordpress.credential_in_payload',
                'This install job carries an administrator password in its payload. '
                    .'The password is minted when the install runs and must never be persisted; '
                    .'whatever queued this job put one there.',
                metadata: ['wordpress_site_id' => (string) ($payload['wordpress_site_id'] ?? '')],
            );
        }

        $site = WordPressSite::query()->find((string) ($payload['wordpress_site_id'] ?? ''));

        if (! $site instanceof WordPressSite) {
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'wordpress.unknown_site',
                'No WordPress site exists with the id this job names.',
                metadata: ['wordpress_site_id' => (string) ($payload['wordpress_site_id'] ?? '')],
            );
        }

        if (! $site->state->permitsInstallation()) {
            /*
             * Already installed, already being installed, or in a state no
             * retry may touch. Reported as success rather than failure: the
             * job's purpose is that the site ends up installed, and it is —
             * or it is in a state where installing again would do harm.
             */
            return ProvisioningResult::succeeded(
                providerReference: $site->domain,
                metadata: ['skipped' => 'the site is in a state that does not permit installation'],
            );
        }

        $account = $site->hostingAccount;

        if (! $account instanceof HostingAccount) {
            /*
             * Transient rather than permanent: the account job may simply not
             * have finished. Both jobs are dispatched from the same
             * settlement, and nothing guarantees the order they run in.
             */
            return ProvisioningResult::failed(
                FailureClass::Transient,
                'wordpress.account_not_ready',
                'The hosting account for this site does not exist yet.',
                metadata: ['wordpress_site_id' => (string) $site->getKey()],
            );
        }

        $node = $account->node;

        if (! $node instanceof HostingNode) {
            return ProvisioningResult::failed(
                FailureClass::Transient,
                'wordpress.node_not_ready',
                'The hosting account is not on a node yet.',
                metadata: ['hosting_account_id' => (string) $account->getKey()],
            );
        }

        $provider = $this->providers->for($node);

        if (! $provider instanceof WordPressInstaller) {
            /*
             * The account landed on a node whose panel cannot install
             * WordPress. Permanent for this job and a placement bug rather
             * than a customer's problem: the scheduler should never have put a
             * WordPress order here, and retrying against the same node cannot
             * make the toolkit appear.
             */
            return ProvisioningResult::failed(
                FailureClass::Permanent,
                'wordpress.panel_cannot_install',
                'This account is on a panel that cannot install WordPress.',
                metadata: ['node' => $node->slug, 'panel' => $provider->panel()->value],
            );
        }

        /*
         * Look before installing.
         *
         * A read is safe to repeat and an install is not, so the cheapest
         * protection against installing over somebody's site is to ask first.
         * A read that fails is not fatal here — it only means the platform
         * learns nothing and proceeds as it would have anyway.
         */
        try {
            $existing = $provider->wordPressInstallation($node, $account->username, $site->domain);

            if ($existing->exists) {
                $this->recordInstalled($site, $existing->siteUrl, $existing->version);

                return ProvisioningResult::succeeded(
                    providerReference: $site->domain,
                    metadata: ['already_present' => true],
                );
            }
        } catch (HostingProviderException) {
            // The panel could not tell us. Nothing is decided by that.
        }

        $site->forceFill(['state' => WordPressSiteState::Installing])->save();

        try {
            $installed = $provider->installWordPress($node, new WordPressInstallRequest(
                username: $account->username,
                domain: $site->domain,
                adminUsername: (string) ($payload['admin_username'] ?? 'admin'),
                adminPassword: Str::password(self::ADMIN_PASSWORD_LENGTH),
                adminEmail: (string) ($payload['admin_email'] ?? ''),
                siteTitle: (string) ($payload['site_title'] ?? $site->domain),
                locale: (string) ($payload['locale'] ?? 'en_US'),
            ));
        } catch (HostingProviderException $e) {
            $message = $this->redactor->redactString($e->getMessage());

            if ($e->isIndeterminate()) {
                /*
                 * The install may have happened. The site says so, and the job
                 * ends: a retry here is how a customer's first afternoon of
                 * writing disappears under a fresh install.
                 */
                $site->forceFill([
                    'state' => WordPressSiteState::Indeterminate,
                    'failure_reason' => $message,
                    'review_reason' => 'The toolkit did not answer the installation. '
                        .'WordPress may or may not be installed; check the account before installing again.',
                ])->save();

                return ProvisioningResult::failed(
                    FailureClass::Timeout,
                    'wordpress.install_indeterminate',
                    $message,
                    metadata: $this->redactor->redact($e->context()),
                );
            }

            $site->forceFill([
                'state' => WordPressSiteState::Failed,
                'failure_reason' => $message,
            ])->save();

            /*
             * Transient, because the commonest refusals here are conditions
             * that clear: a toolkit mid-upgrade, a node under load, a database
             * server refusing one more connection. The account exists either
             * way, so a retry costs nothing but a worker.
             */
            return ProvisioningResult::failed(
                FailureClass::Transient,
                'wordpress.install_refused',
                $message,
                metadata: $this->redactor->redact($e->context()),
            );
        }

        $this->recordInstalled($site, $installed->siteUrl, $installed->version);

        return ProvisioningResult::succeeded(providerReference: $site->domain);
    }

    /**
     * Installed, and not yet verified.
     *
     * The state stops at `awaiting_certificate` rather than `ready`, whatever
     * the installer said. `ready` on this platform means the site was fetched
     * and WordPress answered, and that is somebody else's job — see the
     * verification sweep. An installer's success is a claim, not a check.
     */
    private function recordInstalled(WordPressSite $site, string $siteUrl, ?string $version): void
    {
        $site->forceFill([
            'installed' => true,
            'site_url' => $siteUrl,
            'wordpress_version' => $version,
            'state' => WordPressSiteState::AwaitingCertificate,
            'failure_reason' => null,
        ])->save();
    }
}
