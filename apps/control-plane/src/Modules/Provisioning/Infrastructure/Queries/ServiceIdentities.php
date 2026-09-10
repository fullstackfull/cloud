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
        $ids = array_values(array_unique(array_filter($serviceIds)));

        if ($ids === []) {
            return [];
        }

        $identities = [];

        /*
         * Read through the query builder rather than four models, because the
         * only thing wanted from each table is one column and the models each
         * drag a module's worth of casts and relations with them. Every table
         * here holds `service_id`.
         */
        foreach ([
            'virtual_machines' => 'hostname',
            'hosting_accounts' => 'primary_domain',
            'dedicated_servers' => 'serial',
            'wordpress_sites' => 'domain',
        ] as $table => $column) {
            $rows = DB::table($table)
                ->whereIn('service_id', $ids)
                ->whereNotNull('service_id')
                ->get(['service_id', $column]);

            foreach ($rows as $row) {
                $value = $row->{$column};
                $serviceId = (string) $row->service_id;

                // First writer wins: a service belongs to exactly one resource,
                // and a WordPress site sits on a hosting account whose own
                // identity is already the better answer for the account.
                if (is_string($value) && $value !== '' && ! isset($identities[$serviceId])) {
                    $identities[$serviceId] = $value;
                }
            }
        }

        return $identities;
    }
}
