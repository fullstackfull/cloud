<?php

declare(strict_types=1);

namespace Lynomia\Http\Responses;

/**
 * What a customer is told about a failure the platform stored in words.
 *
 * Several models keep `failure_reason`: whatever the provider, the panel or
 * the registrar said when a job did not go through, with credentials redacted
 * before it was stored. It is the right record for support and it is the
 * wrong sentence for a customer — it names nodes, drivers, API endpoints and
 * HTTP statuses of systems the customer cannot see, and it is always in
 * English. Provisioning already solved this for its jobs with an enum
 * (CustomerFailureReason in that module); this is the same rule for the
 * models that carry prose: the fact that something failed is published, the
 * provider's words are not, and the sentence is the catalogue's in the
 * request's language.
 *
 * `null` in, `null` out — the field still says whether there is a failure.
 */
final class CustomerFailureReason
{
    public static function describe(?string $stored, string $code): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        return ErrorCatalogue::message($code, [], 'The last operation did not complete.');
    }
}
