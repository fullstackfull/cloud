<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure;

use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\SharedHosting\Domain\Contracts\HostingProvider;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\UnknownHostingPanelException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\CpanelHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\DirectAdminHostingProvider;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;

/**
 * Builds the adapter for one node, resolved from hosting_nodes.panel.
 *
 * Resolution is per row rather than from a single configured driver, because a
 * fleet legitimately runs both panels at once: customers buy "cPanel hosting"
 * or "DirectAdmin hosting" by name, migrations run for months, and an
 * acquisition arrives with somebody else's nodes. Asking for "the hosting
 * provider" without saying which node would be meaningless, and the one shape
 * of that mistake that matters — an operation aimed at a node running the
 * other panel — is what this signature makes impossible to express.
 *
 * Adapters are memoised per panel rather than per node: unlike the connection,
 * which carries one node's endpoint and credential, the adapter itself is
 * stateless about which node it is talking to and builds a connection per
 * call. A usage sweep across forty nodes therefore constructs two objects, not
 * forty.
 */
final class HostingProviderFactory
{
    /** @var array<string, HostingProvider> */
    private array $resolved = [];

    /** @var array<string, HostingProvider> */
    private array $overrides = [];

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @throws UnknownHostingPanelException
     */
    public function for(HostingNode $node): HostingProvider
    {
        $override = $this->overrides[(string) $node->getKey()] ?? null;

        if ($override !== null) {
            return $override;
        }

        return $this->forPanel($node->panel);
    }

    /**
     * @throws UnknownHostingPanelException
     */
    public function forPanel(HostingPanel $panel): HostingProvider
    {
        if (isset($this->resolved[$panel->value])) {
            return $this->resolved[$panel->value];
        }

        return $this->resolved[$panel->value] = match ($panel) {
            HostingPanel::Cpanel => new CpanelHostingProvider($this->redactor),
            HostingPanel::DirectAdmin => new DirectAdminHostingProvider($this->redactor),
            HostingPanel::Fake => new FakeHostingProvider,
            // Reached when the panel enum gains a case ahead of its adapter,
            // which is how a node row comes to name a panel the running code
            // cannot drive.
            default => throw UnknownHostingPanelException::named($panel->value, [
                HostingPanel::Cpanel->value,
                HostingPanel::DirectAdmin->value,
                HostingPanel::Fake->value,
            ]),
        };
    }

    /**
     * Replace the adapter for one node for the lifetime of the container.
     *
     * Exists for tests that need a panel to misbehave — a create that times
     * out, an account listing that throws — which cannot be arranged through
     * configuration alone. Keyed by node rather than by panel so that a test
     * can make one node in a fleet fail while the rest keep working, which is
     * the situation the scheduler and the handler actually have to survive.
     */
    public function swap(HostingNode $node, HostingProvider $provider): void
    {
        $this->overrides[(string) $node->getKey()] = $provider;
    }
}
