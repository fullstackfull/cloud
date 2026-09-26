<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Http\Middleware\ThrottleAfterAccountResolution;
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

Route::get('team/roles', [TeamController::class, 'roles'])->name('team.roles');

Route::get('team/members', [TeamController::class, 'members'])->name('team.members');

Route::patch('team/members/{member}', [TeamController::class, 'changeRole'])
    ->whereUlid('member')
    ->name('team.members.role');

Route::delete('team/members/{member}', [TeamController::class, 'removeMember'])
    ->whereUlid('member')
    ->name('team.members.remove');

Route::get('team/invitations', [TeamController::class, 'invitations'])->name('team.invitations');

/*
 * Both roads that put an invitation in the post spend one budget per account.
 *
 * Not `throttle:team-invitations`. The limiter keys on the acting account,
 * and the router's priority sort runs any ThrottleRequests before `customer`
 * has resolved it — the budget silently became one per administrator.
 * ThrottleAfterAccountResolution runs the same limiter where it is declared.
 * TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest holds both halves
 * of that for these two routes by name, and for any other route whose
 * controller method uses InvitationMailer in a form its scan reads: a
 * parameter of that type; the class's name, in code or in a string; or a
 * property of that type, on the method's class or the routed one, read with
 * `->`, `?->` or `::`, with whitespace and comments beside the operator
 * dropped and a name that is an expression counted. Its docblock lists those
 * forms, which of them its fixture holds, and the escapes attack has found —
 * a send reached through a helper, a service or a job, among others. Those
 * escapes are not the boundary; nobody has established one. What was
 * measured is the occupancy: when this was written, outside the mailer's own
 * two files the only use of it in src/ and app/ was TeamController's typed
 * `$mailer`, read only as `$this->mailer->send(…)` in these two routes'
 * actions (`grep -rlw -e InvitationMailer -e InvitationMail src app`: 3
 * files; `grep -rn -e '->mailer' src app`: 2 lines), so use outside the
 * scan's forms was 0 sites.
 */
Route::post('team/invitations', [TeamController::class, 'invite'])
    ->middleware(ThrottleAfterAccountResolution::class.':team-invitations')
    ->name('team.invitations.create');

Route::post('team/invitations/{invitation}/resend', [TeamController::class, 'resendInvitation'])
    ->whereUlid('invitation')
    ->middleware(ThrottleAfterAccountResolution::class.':team-invitations')
    ->name('team.invitations.resend');

Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])
    ->whereUlid('invitation')
    ->name('team.invitations.revoke');

Route::post('team/transfer-ownership', [TeamController::class, 'transferOwnership'])
    ->name('team.transfer_ownership');
