<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Http\Controllers\TeamController;

/*
 * Who belongs to the acting customer's account.
 *
 * Inside the business group, so every route here is authenticated, verified,
 * throttled and resolved to exactly one account before the controller runs.
 * There is no customer id in any path: the account is the one the caller is
 * acting for, and a second way of naming it would be a second way of getting
 * it wrong.
 *
 * The invitee's own endpoints are NOT here — they are in api_v1.php, outside
 * this group, because somebody accepting their first invitation belongs to no
 * account and the middleware that resolves one would refuse them.
 */

Route::get('team/members', [TeamController::class, 'members'])->name('team.members');

Route::patch('team/members/{member}', [TeamController::class, 'changeRole'])
    ->whereUlid('member')
    ->name('team.members.role');

Route::delete('team/members/{member}', [TeamController::class, 'removeMember'])
    ->whereUlid('member')
    ->name('team.members.remove');

Route::get('team/invitations', [TeamController::class, 'invitations'])->name('team.invitations');

Route::post('team/invitations', [TeamController::class, 'invite'])
    ->middleware('throttle:team-invitations')
    ->name('team.invitations.create');

Route::post('team/invitations/{invitation}/resend', [TeamController::class, 'resendInvitation'])
    ->whereUlid('invitation')
    ->middleware('throttle:team-invitations')
    ->name('team.invitations.resend');

Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])
    ->whereUlid('invitation')
    ->name('team.invitations.revoke');

Route::post('team/transfer-ownership', [TeamController::class, 'transferOwnership'])
    ->name('team.transfer_ownership');
