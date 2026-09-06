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
         */
        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $token, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                return $token->isUsable();
            }
        );
    }
}
