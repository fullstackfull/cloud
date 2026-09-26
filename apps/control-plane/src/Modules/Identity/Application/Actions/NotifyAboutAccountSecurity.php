<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use DateTimeInterface;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\LoginActivity;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Throwable;

/**
 * Tells the holder of an account what just happened to its security.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 *
 * Four messages were declared, written in English and Arabic, and raised by
 * nothing (F-46): a password changed, a second factor switched on, a second
 * factor switched off, a sign-in from somewhere new. They are the standard
 * way the holder of an account notices that somebody else is using it — the
 * attacker has the session, and the holder has their mailbox — and every
 * screen that performs them existed and told nobody.
 *
 * ---------------------------------------------------------------------------
 * One person, one message
 * ---------------------------------------------------------------------------
 *
 * These are about a login, and a notification belongs to a customer account,
 * so each is raised once, in the account the person joined first, naming them
 * as its user. Once rather than once per account, because a person on three
 * accounts who changes their password must get one email and not three. The
 * user is named so that the email goes to them rather than to the account's
 * billing address — often a finance team, who did not do it — and so that the
 * inbox shows it to them alone (see NotificationsVisibleTo).
 *
 * A person who belongs to no account — an operator, an invitee who has not
 * accepted yet — is told nothing, because there is no account to hold the
 * row: `notifications.customer_id` is required. That is the boundary this
 * repair did not cross, and it is stated rather than implied. Nothing here
 * refuses or throws: the change the person made has already happened, and
 * failing to announce it must not undo or block it.
 *
 * ---------------------------------------------------------------------------
 * What "somewhere new" means
 * ---------------------------------------------------------------------------
 *
 * The platform keeps no device cookie, so a place is what the sign-in history
 * records: the address and the browser. A sign-in is new when no earlier
 * successful sign-in by this person came from the same address with the same
 * browser. Either alone would miss the case that matters — a stolen password
 * used from the attacker's own machine usually differs in address, and a
 * common browser string is shared by millions — at the price of one message
 * each time a person's address changes. The very first sign-in an account
 * makes is not announced: there is nothing for it to be new against, and the
 * person has just created the account.
 */
final readonly class NotifyAboutAccountSecurity
{
    public function __construct(
        private NotifyCustomer $notify,
    ) {}

    public function passwordChanged(User $user): void
    {
        $this->tell(
            $user,
            NotificationType::PasswordChanged,
            'password-changed:'.$user->id.':'.$this->instant($user->password_changed_at),
        );
    }

    /**
     * Only once enrolment is confirmed: a secret that has been issued and not
     * proved protects nothing yet.
     */
    public function twoFactorEnabled(User $user): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        $this->tell(
            $user,
            NotificationType::TwoFactorEnabled,
            'two-factor-enabled:'.$user->id.':'.$this->instant($user->two_factor_confirmed_at),
        );
    }

    /**
     * @param  DateTimeInterface  $enrolledAt  when the enrolment being ended was confirmed; it names
     *                                         which one ended, since the row no longer says
     */
    public function twoFactorDisabled(User $user, DateTimeInterface $enrolledAt): void
    {
        $this->tell(
            $user,
            NotificationType::TwoFactorDisabled,
            'two-factor-disabled:'.$user->id.':'.$this->instant($enrolledAt),
        );
    }

    public function signedIn(User $user, LoginActivity $signIn): void
    {
        if ($signIn->outcome !== LoginOutcome::Success) {
            return;
        }

        $earlier = LoginActivity::query()
            ->where('user_id', $user->id)
            ->where('outcome', LoginOutcome::Success->value)
            ->whereKeyNot($signIn->getKey());

        if (! (clone $earlier)->exists()) {
            return;
        }

        $seenBefore = (clone $earlier)
            ->where('ip_address', $signIn->ip_address)
            ->where('user_agent', $signIn->user_agent)
            ->exists();

        if ($seenBefore) {
            return;
        }

        $this->tell(
            $user,
            NotificationType::NewSignIn,
            'new-sign-in:'.$signIn->getKey(),
            ['location' => (string) $signIn->ip_address],
        );
    }

    /**
     * @param  array<string, scalar>  $data
     */
    private function tell(User $user, NotificationType $type, string $idempotencyKey, array $data = []): void
    {
        $customerId = CustomerMember::query()
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->value('customer_id');

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        try {
            $this->notify->execute(
                customerId: $customerId,
                type: $type,
                idempotencyKey: $idempotencyKey,
                data: $data,
                link: '/security',
                userId: (string) $user->id,
            );
        } catch (Throwable $e) {
            // Reported, never thrown into the sign-in or the password change
            // that has already happened; see the class docblock.
            report($e);
        }
    }

    private function instant(?DateTimeInterface $at): string
    {
        return $at === null ? 'unknown' : $at->format('U.u');
    }
}
