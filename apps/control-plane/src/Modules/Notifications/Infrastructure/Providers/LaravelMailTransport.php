<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Providers;

use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Notifications\Domain\Contracts\TransactionalEmailProvider;
use Lynomia\Modules\Notifications\Domain\ValueObjects\RenderedNotification;
use Lynomia\Modules\Notifications\Infrastructure\Mail\NotificationMail;

/**
 * The `smtp` driver: the platform's transactional mail, through whatever
 * mailer the deployment configured.
 *
 * This is an adapter over configuration the platform already had, not a
 * client for a vendor. The relay, its port, its credentials and its TLS
 * are `MAIL_*` on the deployment; nothing here reads them, and nothing
 * here can prove they work — which is why the driver is catalogued as
 * untestable and a provider row for it stays blocked. A row exists so
 * the readiness engine has something to point at: "no email provider" and
 * "an email provider nobody has proven" are different answers.
 */
final readonly class LaravelMailTransport implements TransactionalEmailProvider
{
    public const string NAME = 'smtp';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(string $address, RenderedNotification $rendered, ?string $link): string
    {
        Mail::to($address)->send(new NotificationMail($rendered, $link));

        return $address;
    }
}
