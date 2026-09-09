<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Handlers;

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
 */
final readonly class InstallWordPressHandler implements ProvisioningHandler
{
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
                adminPassword: (string) ($payload['admin_password'] ?? ''),
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
