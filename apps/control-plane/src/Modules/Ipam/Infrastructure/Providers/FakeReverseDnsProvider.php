<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Providers;

use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use RuntimeException;

/**
 * A DNS provider that reaches no network and publishes nothing.
 *
 * Its behaviour is a pure function of the hostname it is given, so the paths
 * that matter can be reproduced without an HTTP stub: a name carrying
 * {@see self::REFUSAL_MARKER} is refused as a real provider refuses a name it
 * will not host, and one carrying {@see self::TIMEOUT_MARKER} stops answering.
 * The second is the interesting one — it is the case the platform must not
 * resolve by trying again.
 *
 * It refuses to exist in production. A fake here does not merely fail to set a
 * record: it reports the record as published, which is how a customer's mail
 * starts being rejected by every receiver that checks PTRs while the platform's
 * own dashboard says the name is live.
 */
final class FakeReverseDnsProvider implements ReverseDnsProvider
{
    public const string NAME = 'fake';

    /** A hostname carrying this is refused outright. */
    public const string REFUSAL_MARKER = 'ptr-refused';

    /**
     * A hostname carrying this times out: the call fails with the outcome at
     * the provider unknown. Nothing is recorded as published, deliberately —
     * that is what makes the marker useful, because the caller cannot tell.
     */
    public const string TIMEOUT_MARKER = 'ptr-timeout';

    /**
     * A credential-shaped string the refusal quotes back, because that is what
     * a real zone client does when it fails: it prints the request it sent,
     * headers and all. Nothing stores a provider message without redacting it,
     * and this is what makes that testable rather than assumed.
     */
    private const string ZONE_TOKEN = 'fake-zone-token-0123456789';

    /** @var array<string, string> address => hostname */
    private array $published = [];

    public function __construct()
    {
        // Checked on construction rather than by whoever builds it, so the
        // guard cannot be skipped by a caller that forgot to ask.
        if (app()->isProduction()) {
            throw new RuntimeException(
                'The fake reverse-DNS provider must never be constructed in production: '
                .'it reports PTR records as published without publishing them.'
            );
        }
    }

    public function publish(IpAddressValue $address, Hostname $hostname): void
    {
        if (str_contains($hostname->value(), self::TIMEOUT_MARKER)) {
            throw ReverseDnsProviderException::timedOut($address->value());
        }

        if (str_contains($hostname->value(), self::REFUSAL_MARKER)) {
            /*
             * Quoting the request it sent, credentials and all, is exactly what
             * a real zone client does when it fails — and the reason the record
             * redacts what it stores rather than trusting adapters to be tidy.
             */
            throw ReverseDnsProviderException::refused(
                $address->value(),
                sprintf('PATCH /zones/rdns 401 {"error":"refused"} (Authorization: Bearer %s)', self::ZONE_TOKEN),
            );
        }

        // One record per address: a repeat replaces rather than appends, which
        // is the idempotence the interface promises.
        $this->published[$address->value()] = $hostname->value();
    }

    /** The hostname this fake believes is published for an address, if any. */
    public function publishedFor(string $address): ?string
    {
        return $this->published[$address] ?? null;
    }

    public function publishedCount(): int
    {
        return count($this->published);
    }
}
