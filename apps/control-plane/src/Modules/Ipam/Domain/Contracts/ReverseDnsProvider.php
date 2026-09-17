<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Contracts;

use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;

/**
 * Whatever publishes PTR records for the platform's address space.
 *
 * Two properties of this interface are load-bearing.
 *
 * **It takes value objects, not strings.** An adapter therefore cannot be
 * handed a hostname that was never validated: the only way to build a
 * {@see Hostname} is through its own rules, so "validate before it goes
 * anywhere near a provider call" is expressed in the signature rather than
 * remembered at each call site.
 *
 * **It has no retry, and nothing a customer can reach that removes a record.**
 * A failed publish is reported to the caller with the two outcomes
 * distinguished — see {@see ReverseDnsProviderException} — and what to do about
 * an indeterminate one is a decision for the platform, never for the adapter.
 *
 * `clear()` is the platform's, not the customer's. It exists because an
 * address that changes hands must not keep answering with the last holder's
 * hostname — their company name in a stranger's mail headers, their identity
 * in somebody else's traceroute — and because the platform used to record that
 * intent and never act on it: `IpAllocator::withdrawReverseDns()` marked the
 * row `removing` and nothing anywhere carried the removal out. There is no
 * customer-facing route to it; it is reached only by the sweep that drains
 * withdrawn records.
 */
interface ReverseDnsProvider
{
    /**
     * Publish (or replace) the PTR for one address.
     *
     * Implementations must be idempotent for a given address and hostname:
     * the same call twice leaves one record, not two.
     *
     * @throws ReverseDnsProviderException
     */
    public function publish(IpAddressValue $address, Hostname $hostname): void;

    /**
     * Remove the PTR for one address, if there is one.
     *
     * Implementations must be idempotent and must treat "there was no record"
     * as success: the platform calls this to make a statement about the end
     * state — this address answers for nobody — and an adapter that threw
     * because the work was already done would turn a finished withdrawal into
     * a row that is retried for ever.
     *
     * @throws ReverseDnsProviderException
     */
    public function clear(IpAddressValue $address): void;
}
