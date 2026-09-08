<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Services;

use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use RuntimeException;

/**
 * The one customer account the current request is acting for.
 *
 * Resolved once, by middleware, from the authenticated principal - never from
 * anything in the request body. A customer id that arrives as a form field or a
 * query parameter is a request to act on somebody else's account, and the only
 * safe way to treat it is not to look at it.
 *
 * Bound as a scoped singleton, so it is one instance per request and is
 * discarded between requests even under a long-lived worker.
 */
final class ActingCustomer
{
    private ?Customer $customer = null;

    public function set(Customer $customer): void
    {
        $this->customer = $customer;
    }

    /**
     * The acting customer.
     *
     * Throws rather than returning null: every caller is inside a route that
     * declared it needs one, so a missing customer here is a wiring mistake -
     * a route in the wrong middleware group - and it should surface as a 500
     * during development rather than as a silently unscoped query in
     * production.
     */
    public function get(): Customer
    {
        if ($this->customer === null) {
            throw new RuntimeException(
                'No acting customer has been resolved for this request. '
                .'The route is missing the acting-customer middleware.'
            );
        }

        return $this->customer;
    }

    public function id(): string
    {
        return (string) $this->get()->getKey();
    }
}
