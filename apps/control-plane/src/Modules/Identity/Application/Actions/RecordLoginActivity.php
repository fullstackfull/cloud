<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\LoginActivity;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Appends an authentication event to the login history.
 *
 * Failures are recorded even when no account matches the address, because the
 * pattern of attempts against non-existent accounts is exactly what reveals
 * credential-stuffing. The attempted address is stored; the attempted password
 * never is.
 *
 * A success is also the one place every completed sign-in passes through —
 * the password-only path and the second-factor path alike — so it is where a
 * sign-in from somewhere new is announced to the person (F-46). Announced
 * here, after the row exists, so the history the check reads is the history
 * the person sees on their security page.
 */
final readonly class RecordLoginActivity
{
    public function __construct(
        private NotifyAboutAccountSecurity $security,
    ) {}

    public function execute(
        LoginOutcome $outcome,
        Request $request,
        ?User $user = null,
        ?string $emailAttempted = null,
    ): LoginActivity {
        $activity = LoginActivity::create([
            'user_id' => $user?->id,
            'email_attempted' => $emailAttempted !== null ? strtolower(trim($emailAttempted)) : null,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'context' => [
                'request_id' => Context::get('request_id'),
            ],
            'created_at' => now(),
        ]);

        if ($outcome === LoginOutcome::Success && $user !== null) {
            $this->security->signedIn($user, $activity);
        }

        return $activity;
    }
}
