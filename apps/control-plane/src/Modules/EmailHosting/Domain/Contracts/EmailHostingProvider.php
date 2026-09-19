<?php

declare(strict_types=1);

namespace Lynomia\Modules\EmailHosting\Domain\Contracts;

use Lynomia\Modules\EmailHosting\Domain\DTOs\DkimRecord;
use Lynomia\Modules\EmailHosting\Domain\DTOs\Mailbox;
use Lynomia\Modules\EmailHosting\Domain\DTOs\MailboxUsage;
use Lynomia\Modules\EmailHosting\Domain\DTOs\MailDomain;
use Lynomia\Modules\Notifications\Domain\Contracts\TransactionalEmailProvider;

/**
 * A mail platform the platform would sell mailboxes on.
 *
 * ===========================================================================
 * A TYPED SEAT, NOT A PRODUCT — AND NOT THE PLATFORM'S OWN MAIL
 * ===========================================================================
 *
 * No implementation, no driver, no product for sale: `Product::EmailHosting`
 * is prepared and capped below production. This is customer mailboxes —
 * IMAP, webmail, quotas, DKIM for the customer's domain — and it is a
 * different thing from the transactional mail this platform sends its own
 * customers, which is {@see TransactionalEmailProvider}
 * and one method. The two are separate categories in the provider
 * catalogue because a relay that can send an invoice cannot host a
 * mailbox, and a mail platform is not what the platform's notifications
 * go through.
 *
 * A mailbox password travels in and never out: `createMailbox` and
 * `resetMailboxPassword` take one, no method returns one, and the platform
 * shows it to the customer once.
 */
interface EmailHostingProvider
{
    public function createMailDomain(string $customerRef, string $domain): MailDomain;

    public function deleteMailDomain(string $customerRef, string $domain): void;

    public function createMailbox(string $customerRef, string $address, string $password, int $quotaBytes): Mailbox;

    public function deleteMailbox(string $customerRef, string $address): void;

    public function resetMailboxPassword(string $customerRef, string $address, string $password): void;

    public function changeQuota(string $customerRef, string $address, int $quotaBytes): Mailbox;

    public function createAlias(string $customerRef, string $alias, string $target): void;

    public function deleteAlias(string $customerRef, string $alias): void;

    public function createForwarder(string $customerRef, string $address, string $forwardTo): void;

    public function deleteForwarder(string $customerRef, string $address): void;

    /** A single-use sign-in URL at the webmail, minted for one mailbox. Never retried. */
    public function webmail(string $customerRef, string $address): string;

    public function usage(string $customerRef, string $address): MailboxUsage;

    public function suspend(string $customerRef, string $domain): MailDomain;

    public function unsuspend(string $customerRef, string $domain): MailDomain;

    /** Remove the domain and every mailbox under it. The platform confirms first; this does not. */
    public function terminate(string $customerRef, string $domain): void;

    /** The public key to publish in the customer's zone. */
    public function dkim(string $customerRef, string $domain): DkimRecord;
}
