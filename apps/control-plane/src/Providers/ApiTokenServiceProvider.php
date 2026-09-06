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

                return $token->allowsRequestFrom(request()->ip());
            }
        );
    }
}
