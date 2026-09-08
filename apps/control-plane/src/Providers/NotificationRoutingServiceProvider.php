<?php

declare(strict_types=1);

namespace Lynomia\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Points the framework's built-in notification links at this platform's URLs.
 *
 * Laravel's defaults assume server-rendered routes named `password.reset` and
 * `verification.verify`. This application is an API behind a separate SPA, so
 * without these callbacks the framework would generate links to routes that do
 * not exist — a failure that only appears when a customer actually tries to
 * reset their password.
 */
final class NotificationRoutingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * Password reset needs a form, so the link goes to the SPA, which then
         * posts the token to the API.
         */
        ResetPassword::createUrlUsing(
            static fn (CanResetPassword $notifiable, string $token): string => sprintf(
                '%s/password/reset?token=%s&email=%s',
                rtrim((string) config('app.frontend_url'), '/'),
                urlencode($token),
                urlencode($notifiable->getEmailForPasswordReset()),
            )
        );

        /*
         * Email verification needs no form, so the link goes straight to the
         * signed API route, which verifies and then redirects the browser back
         * to the SPA. Keeping the signature on the API side means the SPA never
         * has to handle — or be trusted with — signed-URL validation.
         */
        VerifyEmail::createUrlUsing(
            static fn (MustVerifyEmail&Model $notifiable): string => URL::temporarySignedRoute(
                'api.v1.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            )
        );
    }
}
