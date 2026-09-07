<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;

final class ApiTokenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    public function boot(): void
    {
        /*
         * A token that has been revoked or has expired must not authenticate,
         * even though its row still exists for the audit trail.
         *
         * The CIDR allow-list is enforced in the same place, because it is the
         * one point every token-authenticated request passes through: leaving
         * it to the endpoints would make an advertised control depend on each
         * of them remembering it.
         */
        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $token, bool $isValid): bool {
                if (! $isValid || ! $token->isUsable()) {
                    return false;
                }

                $address = request()->ip();

                if (! $token->allowsRequestFrom($address)) {
                    return false;
                }

                /*
                 * The address is recorded here because this is the only place
                 * every token-authenticated request passes through, and because
                 * the whole justification for revoking a token by recording it
                 * rather than deleting the row is that "which token did this,
                 * and from where?" stays answerable afterwards. A column that
                 * is advertised in the API and never written is not a forensic
                 * trail; it is a claim.
                 *
                 * Written only when it changes. Sanctum already touches
                 * last_used_at on every request, and a token polled once a
                 * second from one address does not need a second write of the
                 * same string - but a token that starts appearing from a new
                 * address is exactly the event worth having a row for.
                 */
                if (is_string($address) && $address !== $token->last_used_ip) {
                    $token->forceFill(['last_used_ip' => $address])->saveQuietly();
                }

                return true;
            }
        );
    }
}
