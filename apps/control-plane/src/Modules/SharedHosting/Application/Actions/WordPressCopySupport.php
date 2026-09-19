<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Http\Responses\ErrorCatalogue;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressStagingProvider;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\WordPressRefusedException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSiteOperation;
use Throwable;

/**
 * Whether a site can be copied or pushed, and if not, why not — asked
 * once and answered the same way on the row and at every route.
 *
 * Three things have to be true: the site is on a hosting account on a
 * node whose panel implements the staging interface, the site is a
 * finished, verified one, and no copy or push involving it is running.
 * The screen shows the answer as buttons that are there or not with the
 * reason; the routes refuse with the same reason.
 */
final readonly class WordPressCopySupport
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @return array{staging: bool, clone: bool, push_to_production: bool, reason: ?string}
     */
    public function describe(WordPressSite $site): array
    {
        try {
            $this->provider($site);
        } catch (WordPressRefusedException $e) {
            // The catalogue's sentence in the request's language, never the exception's.
            return ['staging' => false, 'clone' => false, 'push_to_production' => false, 'reason' => ErrorCatalogue::message($e->errorCode(), $e->context(), $e->getMessage())];
        }

        $inFlight = $this->operationInFlight($site);

        return [
            'staging' => ! $inFlight && $site->kind->canBeCopied() && ! $this->hasStagingCopy($site),
            'clone' => ! $inFlight && $site->kind->canBeCopied(),
            'push_to_production' => ! $inFlight && $site->kind->canBePushedToProduction() && $site->parent_site_id !== null,
            'reason' => $inFlight ? ErrorCatalogue::message('wordpress.operation_in_flight') : null,
        ];
    }

    /**
     * @return array{provider: WordPressStagingProvider, node: HostingNode, account: HostingAccount}
     *
     * @throws WordPressRefusedException
     */
    public function resolve(WordPressSite $site): array
    {
        return $this->provider($site);
    }

    public function operationInFlight(WordPressSite $site): bool
    {
        return WordPressSiteOperation::query()
            ->where(fn ($query) => $query->where('wordpress_site_id', $site->getKey())->orWhere('target_site_id', $site->getKey()))
            ->whereIn('state', [WordPressOperationState::Requested->value, WordPressOperationState::Running->value, WordPressOperationState::Indeterminate->value])
            ->exists();
    }

    public function hasStagingCopy(WordPressSite $site): bool
    {
        return WordPressSite::query()
            ->where('parent_site_id', $site->getKey())
            ->where('kind', 'staging')
            ->whereNotIn('state', ['removed'])
            ->exists();
    }

    /**
     * @return array{provider: WordPressStagingProvider, node: HostingNode, account: HostingAccount}
     *
     * @throws WordPressRefusedException
     */
    private function provider(WordPressSite $site): array
    {
        if (! $site->state->isUsable() || ! $site->installed) {
            throw WordPressRefusedException::becauseTheSiteIsNotReady($site->domain, $site->state->value);
        }

        // Explicit queries, not relation properties: the row is often one
        // of a listed collection, where lazy loading is refused.
        $account = $site->hostingAccount()->first();

        if (! $account instanceof HostingAccount) {
            throw WordPressRefusedException::becauseThePanelCannotCopy('none');
        }

        $node = $account->node()->first();

        if (! $node instanceof HostingNode) {
            throw WordPressRefusedException::becauseThePanelCannotCopy('none');
        }

        try {
            $provider = $this->providers->for($node);
        } catch (Throwable) {
            throw WordPressRefusedException::becauseThePanelCannotCopy($node->panel->value);
        }

        if (! $provider instanceof WordPressStagingProvider) {
            throw WordPressRefusedException::becauseThePanelCannotCopy($provider->panel()->value);
        }

        return ['provider' => $provider, 'node' => $node, 'account' => $account];
    }
}
