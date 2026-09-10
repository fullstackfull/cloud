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

        $identities = $this->for(array_map(
            static fn (Service $service): string => (string) $service->getKey(),
            $models,
        ));

        foreach ($models as $service) {
            $service->setAttribute(
                self::ATTRIBUTE,
                $identities[(string) $service->getKey()] ?? null,
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
