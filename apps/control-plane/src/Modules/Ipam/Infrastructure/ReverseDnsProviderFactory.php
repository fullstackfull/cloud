<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure;

use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;
use Lynomia\Modules\Ipam\Domain\Exceptions\UnknownReverseDnsDriverException;
use Lynomia\Modules\Ipam\Infrastructure\Providers\FakeReverseDnsProvider;

/**
 * Builds the adapter named by `config('billing.providers.dns')`.
 *
 * A factory rather than a container binding, because the platform has nowhere
 * to put one: the module owns no service provider, and every other provider
 * family here (compute, dedicated, hosting) is resolved the same way — from
 * configuration, at the point of use, memoised for the life of the container.
 *
 * This build ships one driver. `DNS_PROVIDER=cloudflare` is a legal setting
 * that this code cannot honour, and it raises rather than silently falling back
 * to the fake: a fallback would publish nothing while reporting every record as
 * live, which is the single failure this whole module is arranged to avoid.
 */
final class ReverseDnsProviderFactory
{
    private ?ReverseDnsProvider $resolved = null;

    /**
     * The drivers this build contains.
     *
     * Declared once and read by two callers: make(), below, and the
     * production boot guard, which refuses to start a deployment configured
     * for a driver that is not in this list. Two lists would eventually
     * disagree, and the way you would find out is a deployment that booted
     * clean and failed on its first PTR.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return [FakeReverseDnsProvider::NAME];
    }

    /**
     * @throws UnknownReverseDnsDriverException
     */
    public function make(): ReverseDnsProvider
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $driver = (string) config('billing.providers.dns', FakeReverseDnsProvider::NAME);

        return $this->resolved = match ($driver) {
            FakeReverseDnsProvider::NAME => new FakeReverseDnsProvider,
            // Reached when a deployment is configured for a provider whose
            // adapter this build does not contain. ProviderRegistryServiceProvider
            // already refuses to boot production on the fake; this is the other
            // half of the same guard.
            default => throw UnknownReverseDnsDriverException::named($driver, self::drivers()),
        };
    }

    /**
     * Replace the adapter for the life of the container.
     *
     * For tests that need the provider to behave in a way configuration cannot
     * express. The fake's markers cover the ordinary refusal and timeout paths,
     * so this is a seam of last resort rather than the usual route.
     */
    public function swap(ReverseDnsProvider $provider): void
    {
        $this->resolved = $provider;
    }
}
