<?php

declare(strict_types=1);

/*
 * The reusable half of the OpenAPI document.
 *
 * Hand-written, because it describes meaning rather than shape: that money is
 * an integer of minor units and never a float, that a 409 on a provisioning
 * operation means the platform is still waiting for a provider, that a 404 on
 * another tenant's id is the answer and not a mistake. None of that is
 * derivable from a route table.
 *
 * The schemas here are asserted against the real resources by
 * tests/Feature/Api/OpenApiSpecificationTest.php, so a field added to a
 * resource and not to this file fails the build rather than drifting.
 */

$ulid = [
    'type' => 'string',
    'pattern' => '^[0-9a-hjkmnp-tv-z]{26}$',
    'description' => 'A lowercase ULID. Every identifier this API issues is one.',
    // Crockford base32 without i, l, o or u — the example has to obey the
    // pattern above it, and a validator is the only thing that notices when
    // it does not.
    'examples' => ['01jq8m2v9k3d7f5h1n0p2r4s6t'],
];

$timestamp = [
    'type' => ['string', 'null'],
    'format' => 'date-time',
    'description' => 'ISO 8601 with an offset. Always UTC on the wire.',
];

$money = [
    'type' => 'object',
    'description' => <<<'TEXT'
    An exact amount. Never a floating point number, in either direction.

    `minor_units` is the authoritative field and is an integer: 9000 fils, not
    9.0 dinars. `amount` is a decimal *string* for display, and must not be
    parsed into a double and fed back into arithmetic — a KWD total beyond a
    few million fils starts losing precision as a double, and an invoice wrong
    by one fil is an invoice the customer is right to dispute.
    TEXT,
    'required' => ['minor_units', 'currency', 'amount'],
    'additionalProperties' => false,
    'properties' => [
        'minor_units' => ['type' => 'integer', 'examples' => [9000]],
        'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'examples' => ['KWD']],
        'amount' => ['type' => 'string', 'examples' => ['9.000']],
    ],
];

$error = [
    'type' => 'object',
    'description' => <<<'TEXT'
    Every failure on this API, in one shape.

    Branch on `error.code`, never on the status or on the prose. The code is
    stable across releases and across locales; the message is written for a
    person and is translated by the portal from the code, not displayed
    verbatim.

    `error.details` is present only where it helps a client act — the field
    that failed validation, the permission that was missing. It never names the
    platform's own infrastructure: not a node, not a datastore, not a
    configuration key. Those go to the log.

    `error.request_id` correlates the failure with the platform's logs. Quoting
    it to support is faster than reproducing the problem.
    TEXT,
    'required' => ['error'],
    'additionalProperties' => false,
    'properties' => [
        'error' => [
            'type' => 'object',
            'required' => ['code', 'message'],
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'examples' => ['auth.forbidden', 'validation.failed', 'resource.not_found'],
                ],
                'message' => ['type' => 'string'],
                'details' => ['type' => 'object', 'additionalProperties' => true],
                'request_id' => ['type' => 'string'],
            ],
        ],
    ],
];

$paginationMeta = [
    'type' => 'object',
    'description' => <<<'TEXT'
    The same envelope on every collection this API returns.

    `per_page` is what was served, not what was asked for: a request beyond
    `max_per_page` is clamped and served rather than refused, so a client that
    asks for a thousand gets a hundred and a working response.
    TEXT,
    'required' => ['page', 'per_page', 'total', 'last_page', 'max_per_page'],
    'properties' => [
        'page' => ['type' => 'integer', 'minimum' => 1],
        'per_page' => ['type' => 'integer', 'minimum' => 1],
        'total' => ['type' => 'integer', 'minimum' => 0],
        'last_page' => ['type' => 'integer', 'minimum' => 1],
        'max_per_page' => ['type' => 'integer', 'minimum' => 1],
    ],
];

/**
 * A paginated collection of one schema.
 *
 * @param  string  $ref  the component name of the item schema
 * @return array<string, mixed>
 */
$page = static fn (string $ref): array => [
    'type' => 'object',
    'required' => ['data', 'meta'],
    'properties' => [
        'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$ref]],
        'meta' => ['$ref' => '#/components/schemas/PaginationMeta'],
    ],
];

/**
 * A single resource in the envelope every non-collection response uses.
 *
 * @return array<string, mixed>
 */
$single = static fn (string $ref): array => [
    'type' => 'object',
    'required' => ['data'],
    'properties' => ['data' => ['$ref' => '#/components/schemas/'.$ref]],
];

return [
    'page' => $page,
    'single' => $single,

    'securitySchemes' => [
        'sessionCookie' => [
            'type' => 'apiKey',
            'in' => 'cookie',
            'name' => 'lynomia_session',
            'description' => <<<'TEXT'
            The portal's own authentication: a Sanctum cookie session.

            The cookie is HttpOnly, so no script on the page can read it — which
            is the whole reason the portal does not use a bearer token. Every
            mutating request must also carry `X-XSRF-TOKEN`, read from the
            `XSRF-TOKEN` cookie that `GET /sanctum/csrf-cookie` sets.

            Intended for the first-party portal. An integration should use a
            personal access token instead.
            TEXT,
        ],
        'personalAccessToken' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => <<<'TEXT'
            A personal access token, created at `POST /api/v1/me/api-tokens`.

            Shown once, at creation, and never again — the platform stores only
            a hash. A token carries abilities and, optionally, its own rate
            limit and expiry; a request outside a token's abilities is refused
            with `auth.forbidden` rather than silently narrowed.

            A token scoped to one billing account decides which account the
            request acts on, and an `X-Customer-Id` header naming a different
            one is refused rather than obeyed.
            TEXT,
        ],
    ],

    'parameters' => [
        'page' => [
            'name' => 'page',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        ],
        'perPage' => [
            'name' => 'per_page',
            'in' => 'query',
            'required' => false,
            'description' => 'Clamped to the collection\'s maximum rather than refused.',
            'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 25],
        ],
        'customerId' => [
            'name' => 'X-Customer-Id',
            'in' => 'header',
            'required' => false,
            'description' => <<<'TEXT'
            Which billing account to act on, for a principal that belongs to
            more than one.

            Omit it and the platform uses the sole account you belong to; send
            it with more than one membership and it selects. Membership is
            re-checked on every request, so a header naming an account you have
            left is refused. A token already scoped to an account wins: a
            header naming a different one is refused rather than obeyed.
            TEXT,
            'schema' => ['type' => 'string', 'pattern' => '^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$'],
        ],
        'idempotencyKey' => [
            'name' => 'Idempotency-Key',
            'in' => 'header',
            'required' => false,
            'description' => <<<'TEXT'
            Makes a repeat of this request safe.

            A retry carrying the same key returns the first result rather than
            performing the operation again. Without one, a client that retries
            after a timeout may cause the operation twice — and for anything
            that reaches a provider, twice means a second machine, a second
            charge, or a second backup running over the same disks.
            TEXT,
            'schema' => ['type' => 'string', 'maxLength' => 255],
        ],
    ],

    'headers' => [
        'RateLimitRemaining' => [
            'description' => 'Requests left in the current window.',
            'schema' => ['type' => 'integer'],
        ],
        'RetryAfter' => [
            'description' => 'Seconds to wait before retrying. Present on 429.',
            'schema' => ['type' => 'integer'],
        ],
        'RequestId' => [
            'description' => 'Correlates this response with the platform\'s logs. On every response.',
            'schema' => ['type' => 'string'],
        ],
    ],

    'schemas' => [
        'Ulid' => $ulid,
        'Timestamp' => $timestamp,
        'Money' => $money,
        'Error' => $error,
        'PaginationMeta' => $paginationMeta,
    ],
];
