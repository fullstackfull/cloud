<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Ipam\Application\Jobs\PublishReverseDnsRecord;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidHostnameException;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsUnavailableException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * Record the PTR a customer wants for one of their addresses, and hand the
 * publishing to a worker.
 *
 * Three decisions are worth stating.
 *
 * **The provider is not called here.** This action writes the intent and
 * returns; {@see PublishReverseDnsRecord} talks to the zone API. A DNS API call
 * inside the request would put a third party's latency in front of the
 * customer's browser, and — worse — a call that timed out mid-request would
 * leave the platform with no good answer to give: it cannot say the record was
 * set, cannot say it was not, and must not try again to find out. Out of band,
 * the same timeout is a row somebody can look at.
 *
 * **The hostname is validated twice, and never trusted once.** The form request
 * rejects a bad name with a 422 naming the field; this action builds a
 * {@see Hostname} regardless, because an operator tool or a console command
 * reaching this method is entitled to the same refusal — and because the
 * provider interface will not accept anything else.
 *
 * **There is no idempotency key.** Not an omission: `PUT` of one hostname onto
 * one address is already idempotent, the row is keyed by address by a unique
 * index, and repeating the request converges on the same single record rather
 * than making a second one. A key would be ceremony that promises nothing the
 * operation does not already guarantee.
 */
final class SetReverseDns
{
    /**
     * @throws InvalidHostnameException
     * @throws ReverseDnsUnavailableException
     */
    public function execute(IpAssignment $assignment, string $hostname): ReverseDnsRecord
    {
        /*
         * Live-ness is checked even though the customer surface only ever hands
         * this a live assignment. A released row's address has very likely been
         * handed on; publishing this customer's hostname onto it would put their
         * name on somebody else's machine.
         */
        if (! $assignment->isLive()) {
            throw ReverseDnsUnavailableException::assignmentReleased((string) $assignment->getKey());
        }

        // Loaded explicitly rather than assumed: the pool's scope is what
        // decides whether a PTR is publishable at all, and lazy loading is
        // disabled outside production.
        $assignment->loadMissing('ipAddress.subnet.ipPool');

        /** @var IpAddress $address */
        $address = $assignment->ipAddress;

        /** @var Subnet $subnet */
        $subnet = $address->subnet;

        /** @var IpPool $pool */
        $pool = $subnet->ipPool;

        if (! $pool->scope->isInternetRouted()) {
            // Private and management space has no delegated PTR zone. Refused
            // here rather than discovered as a provider rejection minutes later,
            // by which time the customer has been told the change was accepted.
            throw ReverseDnsUnavailableException::notInternetRouted($pool->scope);
        }

        $validated = Hostname::fromString($hostname);

        $record = DB::transaction(function () use ($address, $validated): ReverseDnsRecord {
            /*
             * Locked rather than upserted blind. `reverse_dns_records` is unique
             * on ip_address_id, so two requests racing on one address would have
             * one of them fail on the constraint; taking the row first makes the
             * second request an update and the customer's last word the one that
             * is published.
             */
            $existing = ReverseDnsRecord::query()
                ->where('ip_address_id', $address->getKey())
                ->lockForUpdate()
                ->first();

            $attributes = [
                'hostname' => $validated->value(),
                // Back to pending on every change: the record is a request
                // until the provider has confirmed it, and leaving a stale
                // `active` on a hostname nobody has published yet is how a
                // customer reads their own change back as already live.
                'status' => ReverseDnsStatus::Pending,
                // The previous attempt's error belongs to the previous
                // hostname. Keeping it would attach an old refusal to a new
                // name.
                'last_error' => null,
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return $existing;
            }

            return ReverseDnsRecord::create($attributes + ['ip_address_id' => $address->getKey()]);
        });

        /*
         * Dispatched after the transaction commits. A worker that picked the
         * job up first would read the row as it was before this request — or
         * not find it at all — and would publish the previous hostname.
         */
        PublishReverseDnsRecord::dispatch((string) $record->getKey())->afterCommit();

        return $record;
    }
}
