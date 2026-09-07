<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\ApiKeys\Http\Controllers\ApiTokenController;

/*
 * api-tokens — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * Three routes, and what is missing from the list is the security property.
 *
 * There is no GET on a single token that returns its plaintext, and no
 * "regenerate" that hands one back. The table holds a SHA-256 digest, so the
 * plaintext exists only in the 201 from `store` — a token a customer has lost
 * is replaced, never re-read. Any route that could show it twice would turn
 * every stale browser tab and every proxy log into a second copy of a live
 * credential.
 *
 * DELETE revokes rather than deletes: the row survives with a reason and a
 * timestamp, so `last_used_at` and `last_used_ip` are still there to answer
 * "which token did this?" during the incident that prompted the revocation.
 *
 * All three are under /me because a token is personal: it authenticates as the
 * user who minted it, bound to the account they minted it for, and a colleague
 * on the same account cannot see it here.
 */

Route::get('me/api-tokens', [ApiTokenController::class, 'index'])
    ->name('me.api_tokens.index');

Route::post('me/api-tokens', [ApiTokenController::class, 'store'])
    ->name('me.api_tokens.store');

Route::delete('me/api-tokens/{token}', [ApiTokenController::class, 'destroy'])
    ->name('me.api_tokens.destroy');
