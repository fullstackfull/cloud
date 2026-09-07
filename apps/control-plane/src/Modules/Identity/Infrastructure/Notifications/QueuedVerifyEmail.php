<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The framework's verification notification, queued.
 *
 * Registration answers identically for a free and a taken address, and the same
 * timing argument as the reset mail applies: one path would send, the other
 * would not, and the difference is measurable. Queueing both makes the two
 * indistinguishable from outside.
 */
final class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
