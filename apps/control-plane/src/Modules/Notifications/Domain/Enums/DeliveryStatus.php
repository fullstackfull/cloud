<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\Enums;

/**
 * How far a notification got down one channel.
 *
 * `Sent` means the platform handed it to something that accepted it — an SMTP
 * server, the inbox table. It does not mean read, and it does not mean
 * delivered to a human: a mail server accepting a message and a customer
 * seeing it are days and several failure modes apart, and the platform only
 * ever knows the first.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::Failed => true,
            self::Pending, self::Queued => false,
        };
    }
}
