<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Domain\Exceptions\UnknownDnsDriverException;
use Lynomia\Modules\Dns\Infrastructure\Providers\CloudflareDnsProvider;
use Lynomia\Modules\Dns\Infrastructure\Providers\FakeDnsProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Builds the forward-DNS adapter named by `config('billing.providers.dns')`.
 *
 * One configuration key drives both this and the reverse-DNS factory, because
 * an operator who has configured Cloudflare has configured Cloudflare — and
 * two keys would let a deployment hold a forward provider and a reverse
 * provider that disagree about which account they are talking to.
 *
 * They remain two factories, and two contracts, because the capabilities are
 * genuinely different: holding a domain says nothing about holding the reverse
 * delegation for the addresses it points at.
 */
final class DnsProviderFactory
{
    private ?DnsProvider $resolved = null;

    /**
     * The drivers this build contains.
     *
     * Read here and by the production boot guard. Two lists would eventually
     * disagree, and the way anyone would find out is a deployment that booted
     * clean and failed on its first record.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return [FakeDnsProvider::NAME, CloudflareDnsProvider::NAME];
    }

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @throws UnknownDnsDriverException
     */
    public function make(): DnsProvider
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $driver = strtolower(trim((string) config('billing.providers.dns', FakeDnsProvider::NAME)));

        return $this->resolved = match ($driver) {
            FakeDnsProvider::NAME => new FakeDnsProvider,
            CloudflareDnsProvider::NAME => new CloudflareDnsProvider($this->redactor),
            default => throw UnknownDnsDriverException::named($driver, self::drivers()),
        };
    }

    /**
     * Replace the adapter for the life of the container.
     *
     * For tests that need the provider to behave in a way configuration cannot
     * express. The fake's markers cover the ordinary refusal and timeout paths,
     * so this is a seam of last resort rather than the usual route.
     */
    public function swap(DnsProvider $provider): void
    {
        $this->resolved = $provider;
    }
}
