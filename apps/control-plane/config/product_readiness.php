<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Rehearsed prepared products
    |--------------------------------------------------------------------------
    |
    | Products whose software is written and tested but which are not in the
    | approved launch scope, and whose sale the controlled simulation is still
    | allowed to walk end to end outside production.
    |
    | This list cannot make a product sellable in production: the service that
    | reads it refuses there before it is consulted, and refuses for any
    | product whose software state is not `prepared`. Its only effect is that
    | a test or a staging walk-through may exercise a lifecycle the platform
    | has built and does not yet offer for sale, which is the only way that
    | software stays covered while it waits for a provider contract.
    |
    | Emptying this list does not break production. It withdraws the rehearsal,
    | and the suites that rehearse those lifecycles fail — which is the honest
    | signal that the software is no longer exercised anywhere.
    |
    */

    'rehearsed_products' => [
        'wordpress',
        'domains',
    ],

];
