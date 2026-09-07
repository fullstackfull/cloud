<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\Enums;

/**
 * How a notification reaches somebody.
 *
 * Two are implemented. The rest are declared because the extension point is
 * the point: a channel added later must not require the twenty listeners that
 * send notifications to learn about it, and an enum with three unimplemented
 * cases says where the seam is more clearly than a comment does.
 *
 * An unimplemented channel has no binding in the registry, so asking for one
 * fails at the composition root rather than silently succeeding at nothing.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';

    // Declared, not implemented. See the class docblock.
    case Sms = 'sms';
    case WhatsApp = 'whatsapp';
    case Push = 'push';

    public function isImplemented(): bool
    {
        return match ($this) {
            self::InApp, self::Email => true,
            self::Sms, self::WhatsApp, self::Push => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function implemented(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isImplemented()));
    }
}
