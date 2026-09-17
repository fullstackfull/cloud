<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Providers;

use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Shared\Infrastructure\Simulation\ControlledSimulationStore;
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
 *
 * With `dns.fake_reverse.state_path` set it remembers across processes, because
 * a PTR is published by a worker and read back by a request, and a simulator
 * that forgets at that boundary can prove the contract and not the workflow.
 * Unset, which is the default, it keeps everything in memory.
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

    /** Where this remembers between processes, or null to keep it in memory. */
    private readonly ?ControlledSimulationStore $store;

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

        $this->store = ControlledSimulationStore::fromConfig('dns.fake_reverse.state_path');
    }

    public function publish(IpAddressValue $address, Hostname $hostname): void
    {
        $this->restore();

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

        $this->remember();
    }

    /** The hostname this fake believes is published for an address, if any. */
    public function publishedFor(string $address): ?string
    {
        $this->restore();

        return $this->published[$address] ?? null;
    }

    public function publishedCount(): int
    {
        $this->restore();

        return count($this->published);
    }

    /**
     * What the last process left, when there is a file to read it from.
     *
     * Read at the start of every operation rather than once in the constructor,
     * because the file is the truth whenever there is one: two processes are
     * both writing it, and an instance that read it at construction would
     * answer from a picture that was already old.
     */
    private function restore(): void
    {
        $state = $this->store?->read();

        if ($state === null) {
            return;
        }

        /** @var array<string, string> $published */
        $published = $state['published'] ?? [];

        $this->published = $published;
    }

    private function remember(): void
    {
        $this->store?->write(['published' => $this->published]);
    }
}
