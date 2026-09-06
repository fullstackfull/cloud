<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The framework's reset notification, queued.
 *
 * `POST /password/forgot` deliberately answers the same whether or not the
 * address has an account, so that it cannot be used to find out. Sending the
 * mail inside the request gives that away again through the clock: a known
 * address costs a token insert plus a full SMTP transaction, an unknown one
 * returns immediately having touched neither. The status code is constant; the
 * wall-clock time was not.
 *
 * Queueing also stops a mail-server outage from turning a public endpoint into
 * a 5xx.
 */
final class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
