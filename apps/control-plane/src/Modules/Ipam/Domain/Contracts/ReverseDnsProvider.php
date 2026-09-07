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
 * **It has no retry, and no `remove()` that a customer can reach.** A failed
 * publish is reported to the caller with the two outcomes distinguished — see
 * {@see ReverseDnsProviderException} — and what to do about an indeterminate
 * one is a decision for the platform, never for the adapter.
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
}
