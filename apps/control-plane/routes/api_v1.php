<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Http\Controllers\EmailVerificationController;
use Lynomia\Modules\Identity\Http\Controllers\InvitationController;
use Lynomia\Modules\Identity\Http\Controllers\LoginController;
use Lynomia\Modules\Identity\Http\Controllers\PasswordResetController;
use Lynomia\Modules\Identity\Http\Controllers\ProfileController;
use Lynomia\Modules\Identity\Http\Controllers\RegistrationController;
use Lynomia\Modules\Identity\Http\Controllers\RegistrationOptionsController;
use Lynomia\Modules\Identity\Http\Controllers\SessionController;
use Lynomia\Modules\Identity\Http\Controllers\TwoFactorController;
use Lynomia\Modules\Notifications\Http\Controllers\NotificationPreferenceController;

/*
|--------------------------------------------------------------------------
| Customer API — /api/v1
|--------------------------------------------------------------------------
|
| Serves both the customer portal (Sanctum SPA cookie session) and the public
| customer API (scoped personal access tokens). One implementation of every
| operation, so anything a customer can do in the UI they can also automate.
|
*/

/*
 * What the registration form may offer: the accepted countries, the currencies
 * this platform bills in, and the currency recommended for each country.
 *
 * Public because it is read before an account exists, and cacheable because it
 * is configuration rather than anybody's data. The same lists validate the
 * submission, so the form cannot offer a choice the server will not accept and
 * a browser cannot submit one it was never offered.
 */
Route::get('registration/options', RegistrationOptionsController::class)
    ->middleware('throttle:60,1,registration-options:')
    ->name('registration.options');

Route::middleware('guest')->group(function (): void {
    Route::post('register', RegistrationController::class)
        ->middleware('throttle:register')
        ->name('register');

    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::post('password/forgot', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:password-reset')
        ->name('password.forgot');

    Route::post('password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset')
        ->name('password.reset');
});

/*
 * Email verification is confirmed through a signed URL, so it is reachable
 * without an authenticated session: a customer may well click the link in a
 * different browser from the one they registered in.
 */
Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1,email-verify:'])
    ->name('verification.verify');

/*
 * `throttle:api` is applied here rather than to the framework `api` group so
 * that Laravel's middleware priority runs authentication first: the limiter in
 * RateLimitServiceProvider keys on the acting user and honours a personal
 * access token's own ceiling, both of which need a resolved user. Without it
 * every authenticated endpoint — including the ones that verify the account
 * password — accepts requests as fast as the network allows.
 */
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::post('email/verify/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1,email-verify-resend:')
        ->name('verification.resend');

    Route::get('me', [ProfileController::class, 'show'])->name('me');
    Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
    Route::put('me/password', [ProfileController::class, 'updatePassword'])->name('me.password');

    // Active sessions and their revocation.
    Route::get('me/sessions', [SessionController::class, 'index'])->name('me.sessions');
    Route::delete('me/sessions/{session}', [SessionController::class, 'destroy'])->name('me.sessions.destroy');
    Route::delete('me/sessions', [SessionController::class, 'destroyOthers'])->name('me.sessions.destroy_others');

    Route::get('me/login-activity', [SessionController::class, 'loginActivity'])->name('me.login_activity');

    // Two-factor authentication.
    Route::post('me/two-factor', [TwoFactorController::class, 'enable'])->name('me.2fa.enable');
    Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('me.2fa.confirm');
    Route::delete('me/two-factor', [TwoFactorController::class, 'disable'])->name('me.2fa.disable');
    Route::post('me/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->name('me.2fa.recovery_codes');

    /*
     * Which optional messages this person wants. Here rather than in the
     * business group because a preference belongs to a login, not to an
     * account: two people on one customer read different mail, and somebody
     * who belongs to no account yet still has security mail to receive.
     */
    Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'index'])
        ->name('me.notification_preferences.index');
    Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('me.notification_preferences.update');

    /*
     * The invitee's side of a team invitation.
     *
     * Here rather than in the business group on purpose: the person accepting
     * their first invitation belongs to no account yet, and the middleware
     * that resolves an acting customer would refuse exactly the people these
     * routes exist for. `verified` is likewise absent from the group — the
     * acceptance itself checks that the address is verified, and refusing
     * before that would tell an unverified caller nothing about why.
     *
     * The token is the selector and it is not a ULID: it is 64 hex characters
     * from the CSPRNG, constrained here so that a malformed one is a 404 from
     * the router rather than a database lookup.
     */
    Route::get('invitations/{token}', [InvitationController::class, 'show'])
        ->where('token', '[0-9a-f]{64}')
        ->middleware('throttle:invitations')
        ->name('invitations.show');

    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->where('token', '[0-9a-f]{64}')
        ->middleware('throttle:invitations')
        ->name('invitations.accept');

    Route::post('invitations/{token}/decline', [InvitationController::class, 'decline'])
        ->where('token', '[0-9a-f]{64}')
        ->middleware('throttle:invitations')
        ->name('invitations.decline');
});

// Challenge endpoint for a login that has passed the password stage and is
// awaiting a second factor. It is not behind auth:sanctum because no session
// exists yet.
Route::post('login/two-factor', [LoginController::class, 'twoFactorChallenge'])
    ->middleware('throttle:two-factor')
    ->name('login.two_factor');

/*
|--------------------------------------------------------------------------
| Business surface
|--------------------------------------------------------------------------
|
| Every module registers its own routes in its own file, and each of those
| files is a leaf: modules do not reach into each other's routing, and adding a
| module is one line here rather than an edit inside a shared closure.
|
| All of them sit inside the same group, so the four things that must be true of
| every customer-facing business endpoint are true by construction rather than
| by each file remembering:
|
|   auth:sanctum   authenticated
|   verified       the address on the account has been proved
|   throttle:api   bounded, keyed on the acting principal
|   customer       resolved to exactly one customer account
|
| `verified` is here and not on the identity routes above on purpose: signing in
| and managing your own credentials must work before the address is verified —
| otherwise a customer who mistyped their address cannot even reach the resend
| button — but nothing that spends money or provisions hardware should.
|
| The catalogue is the one read that does neither, and it is registered in its
| own group just above without `verified`.
|
*/
/*
 * The catalogue, and only the catalogue, is readable before the address is
 * verified.
 *
 * A customer who has just registered is three minutes into their first
 * session and is looking at prices. Refusing them the catalogue teaches them
 * nothing except that the product does not work; letting them read it costs
 * nothing, because reading a price moves no money and provisions no hardware.
 *
 * What is NOT here is the point of the split: orders, invoices, payments,
 * wallet, services and every other business route keep `verified`, so an
 * unverified account can look and cannot buy. `customer` still applies, so
 * the reader is still resolved to exactly one account and sees exactly the
 * catalogue that account is priced in.
 */
Route::middleware(['auth:sanctum', 'throttle:api', 'customer'])->group(function (): void {
    require __DIR__.'/v1/catalog.php';
});

Route::middleware(['auth:sanctum', 'verified', 'throttle:api', 'customer'])->group(function (): void {
    foreach ([
        'orders',
        'billing',
        'payments',
        'wallet',
        'services',
        'vps',
        'backups',
        'dedicated',
        'hosting',
        'ipam',
        'dns',
        'domains',
        'api-tokens',
        'notifications',
        'support',
        'team',
        'account',
    ] as $module) {
        $file = __DIR__.'/v1/'.$module.'.php';

        // A missing file is a wiring mistake, not something to skip quietly: a
        // module silently absent from the API is the kind of thing that is
        // noticed by a customer rather than by CI.
        if (! is_file($file)) {
            throw new RuntimeException(sprintf('Route module "%s" is listed but %s does not exist.', $module, $file));
        }

        require $file;
    }
});
