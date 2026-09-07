<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Application\Queries;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * The one place an API token query is scoped, and it is scoped twice.
 *
 * **To the login**, because a token is a personal credential: it authenticates
 * as the user who created it, so a colleague on the same account must not be
 * able to list or revoke it. The relation is the user's own `tokens()`, so
 * somebody else's token is not a row this query can return.
 *
 * **To the acting customer**, because a token is bound to one account. A user
 * who administers two accounts sees, while acting for one of them, only the
 * tokens issued for that one. Without this constraint the endpoint would hand
 * an operator working on account A a credential that acts on account B — the
 * exact widening that ResolveActingCustomer refuses to allow at request time.
 *
 * The customer id comes from ActingCustomer and never from the request, so this
 * is a scope rather than a check: `->whereKey($id)->firstOrFail()` on what is
 * returned here 404s for another tenant's id instead of loading the row and
 * relying on a comparison afterwards.
 *
 * The scope reads `customer_id` directly because the relation it wants —
 * `$customer->apiTokens()` — belongs on Customer, and the Identity module is
 * owned elsewhere. Keeping it in this single class means adding that relation
 * later is a one-line change here rather than an audit of every call site.
 */
final class CustomerApiTokens
{
    /**
     * @return MorphMany<PersonalAccessToken, User>
     */
    public static function for(User $user, Customer $customer): MorphMany
    {
        /** @var MorphMany<PersonalAccessToken, User> $tokens */
        $tokens = $user->tokens()->where('customer_id', $customer->getKey());

        return $tokens;
    }
}
