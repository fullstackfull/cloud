<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\Contracts;

use Lynomia\Modules\Notifications\Domain\ValueObjects\RenderedNotification;

/**
 * Whatever carries the mail this platform sends its own customers.
 *
 * One method, because that is everything the platform asks of it: send
 * one rendered message to one address. Bounce handling, templates,
 * lists and analytics are a provider's features and not this platform's
 * needs; an interface that promised them would be promising a product.
 *
 * The catalogued `smtp` driver is this interface implemented over the
 * framework's own mail transport — whatever `MAIL_MAILER` names — and it
 * is deliberately not testable from the Control Center: there is no
 * relay to test against, and a tester that "connected" to a log mailer
 * would be the first fake to reach production. A transactional email
 * provider is therefore never REAL_INFRA_VERIFIED on this build, and the
 * shared requirement every product carries stays blocked on it.
 */
interface TransactionalEmailProvider
{
    public function name(): string;

    /**
     * @return string the address the message was handed to the transport for
     */
    public function send(string $address, RenderedNotification $rendered, ?string $link): string;
}
