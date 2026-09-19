<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Queries;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The one place a provisioning job is scoped to a customer.
 *
 * The same shape as `CustomerServices`, and for the same reason: what a
 * controller holds is already constrained to the acting customer, so an
 * unscoped job is never in hand. `->whereKey($id)->firstOrFail()` on this
 * builder answers 404 for another tenant's operation instead of fetching the
 * row and checking afterwards — by which point the payload, the provider
 * response and the error text have already been read.
 *
 * A job's `customer_id` is nullable in the schema, because some work belongs
 * to the platform rather than to an account. Those rows are unreachable from
 * here by construction: a null customer matches no customer's id.
 */
final readonly class CustomerOperations
{
    /**
     * @return Builder<ProvisioningJob>
     */
    public static function of(Customer $customer): Builder
    {
        return ProvisioningJob::query()->where('customer_id', $customer->getKey());
    }
}
