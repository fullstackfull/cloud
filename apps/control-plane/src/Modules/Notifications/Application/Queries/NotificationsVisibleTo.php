<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Queries;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * What one person on a customer account may read in its inbox.
 *
 * The inbox is the account's: a server built, an invoice issued, a ticket
 * answered are facts about the account, and everybody on it who may see its
 * services sees them. The one exception is a security notification that names
 * a person — their password changed, their second factor switched off, their
 * sign-in from an address they never used (F-46). That is about a login, not
 * the account. In a colleague's inbox "your password was changed" is a false
 * alarm about their own password, and a sign-in notice would hand them
 * somebody else's address. So it is shown to the person it names and to
 * nobody else on the account.
 *
 * Only the security category is narrowed. A ticket notification also names a
 * person — the one who opened it, so the email reaches them — and it stays the
 * account's, because a colleague picking up a ticket needs to see it answered.
 *
 * The list, the unread count, marking read and the dashboard's unread figure
 * all read through here, so no two of them can disagree about what a person
 * has not read yet.
 */
final readonly class NotificationsVisibleTo
{
    /**
     * @return Builder<Notification>
     */
    public function query(string $customerId, ?string $viewerId): Builder
    {
        return Notification::query()
            ->where('customer_id', $customerId)
            ->where(static function (Builder $visible) use ($viewerId): void {
                $visible->where('category', '!=', NotificationCategory::Security->value)
                    ->orWhereNull('user_id');

                if ($viewerId !== null && $viewerId !== '') {
                    $visible->orWhere('user_id', $viewerId);
                }
            });
    }

    public function unreadCount(string $customerId, ?string $viewerId): int
    {
        return $this->query($customerId, $viewerId)->whereNull('read_at')->count();
    }
}
