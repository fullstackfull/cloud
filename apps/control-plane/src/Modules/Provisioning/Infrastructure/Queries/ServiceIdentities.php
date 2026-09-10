<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Queries;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The name a customer would use for a service: a hostname, a domain, a serial.
 *
 * A service row carries a label built from the catalogue — "Cloud VPS —
 * Starter" — which is the same string for every customer who bought that
 * plan. What tells two of them apart is the thing that was actually created,
 * and that lives in the module that created it. This query fetches those
 * names for a set of services in one round trip per kind, rather than letting
 * a screen loop and issue a query per row.
 *
 * A service that has no resource yet — ordered, paid, still provisioning —
 * has no identity, and the answer is null rather than a placeholder. "Being
 * created" is a true and useful thing for a screen to say; a fabricated
 * hostname is not.
 */
final class ServiceIdentities
{
    /** The attribute the identity is attached to, read by the resources. */
    public const string ATTRIBUTE = 'identity';

    /**
     * The attribute the resource handle is attached to.
     *
     * A service's own id is not the id of the thing that fulfils it: the
     * machine, the account and the chassis each have their own. A client that
     * built a link from the service id would be pointing at a resource that
     * does not exist, so the handle — the family and the fulfilling row's id —
     * is published rather than left to be guessed.
     */
    public const string HANDLE_ATTRIBUTE = 'resource_handle';

    /**
     * Where each kind of service is actually fulfilled: the table, the
     * customer-facing family it belongs to, and the column holding the name a
     * customer knows it by.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array TABLES = [
        'virtual_machines' => ['vps', 'hostname'],
        'hosting_accounts' => ['hosting', 'primary_domain'],
        'dedicated_servers' => ['dedicated', 'serial'],
        'wordpress_sites' => ['wordpress', 'domain'],
    ];

    /**
     * The identity attached to one service, or null.
     *
     * Read through here by every resource that publishes it, so that "what
     * counts as an identity" is decided once. A service fetched without
     * attach() having run has no attribute, and null is the honest answer
     * rather than an empty string.
     */
    public static function identityOf(Service $service): ?string
    {
        $identity = $service->getAttribute(self::ATTRIBUTE);

        return is_string($identity) && $identity !== '' ? $identity : null;
    }

    /**
     * The `{kind, id}` handle attached to one service, or null.
     *
     * The shape a client needs to build a link, published identically by the
     * service resource, the order's services and a subscription's services:
     * three copies of this extraction would have been three chances for one of
     * them to publish a service id where a machine id belongs.
     *
     * @return array{kind: string, id: string}|null
     */
    public static function handleOf(Service $service): ?array
    {
        $handle = $service->getAttribute(self::HANDLE_ATTRIBUTE);

        if (! is_array($handle)) {
            return null;
        }

        $kind = $handle['kind'] ?? null;
        $id = $handle['id'] ?? null;

        return is_string($kind) && is_string($id) ? ['kind' => $kind, 'id' => $id] : null;
    }

    /**
     * Resolves the identities for a set of services and hangs each one on its
     * own model, so a screen serialising a page of rows issues four queries in
     * total rather than four per row.
     *
     * @param  iterable<Service>  $services
     */
    public function attach(iterable $services): void
    {
        $models = [];

        foreach ($services as $service) {
            $models[] = $service;
        }

        if ($models === []) {
            return;
        }

        $handles = $this->handlesFor(array_map(
            static fn (Service $service): string => (string) $service->getKey(),
            $models,
        ));

        foreach ($models as $service) {
            $handle = $handles[(string) $service->getKey()] ?? null;

            $service->setAttribute(self::ATTRIBUTE, $handle['identity'] ?? null);

            /*
             * Both halves come from the same lookup, so a resource whose
             * identity is known and whose id is not — or the reverse — is not
             * a state a caller has to handle. There is no such state: the row
             * either exists or it does not.
             */
            $service->setAttribute(
                self::HANDLE_ATTRIBUTE,
                $handle === null ? null : ['kind' => $handle['kind'], 'id' => $handle['id']],
            );
        }
    }

    /**
     * @param  list<string>  $serviceIds
     * @return array<string, string> service id => identity
     */
    public function for(array $serviceIds): array
    {
        $identities = [];

        foreach ($this->handlesFor($serviceIds) as $serviceId => $handle) {
            if ($handle['identity'] !== null) {
                $identities[$serviceId] = $handle['identity'];
            }
        }

        return $identities;
    }

    /**
     * The concrete resource behind each service: which family it belongs to,
     * its own id, and the name a customer knows it by.
     *
     * The id is what a link to a resource page needs. A notification about a
     * service carries the service, and a customer clicking it wants the
     * machine — so something has to turn one into the other, and doing it here
     * keeps the knowledge of which table fulfils which kind in the one place
     * that already had it.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, array{kind: string, id: string, identity: string|null}>
     */
    public function handlesFor(array $serviceIds): array
    {
        $ids = array_values(array_unique(array_filter($serviceIds)));

        if ($ids === []) {
            return [];
        }

        $handles = [];

        /*
         * Read through the query builder rather than four models, because the
         * only thing wanted from each table is two columns and the models each
         * drag a module's worth of casts and relations with them. Every table
         * here holds `service_id`.
         *
         * The kind is the customer-facing family — the word in the portal's
         * own addresses — and not the fulfilling module's name.
         */
        foreach (self::TABLES as $table => [$kind, $column]) {
            $rows = DB::table($table)
                ->whereIn('service_id', $ids)
                ->whereNotNull('service_id')
                ->get(['id', 'service_id', $column]);

            foreach ($rows as $row) {
                $serviceId = (string) $row->service_id;

                // First writer wins: a service belongs to exactly one
                // resource, and a WordPress site sits on a hosting account
                // whose own identity is already the better answer for the
                // account.
                if (isset($handles[$serviceId])) {
                    continue;
                }

                $value = $row->{$column};

                $handles[$serviceId] = [
                    'kind' => $kind,
                    'id' => (string) $row->id,
                    'identity' => is_string($value) && $value !== '' ? $value : null,
                ];
            }
        }

        return $handles;
    }
}
