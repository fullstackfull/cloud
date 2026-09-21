<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Services;

use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * One way to tell a customer what became of a backup.
 *
 * Three actions now raise these messages — the reconciler, the request, and
 * the inventory sweep — and each had its own copy of the same eight lines:
 * find the service, fall back to its id when it has no label, pass the facts
 * rather than a sentence, link to the same page. Copies drift. One of them
 * would have quietly started sending a different payload, and the sentence a
 * customer reads is assembled from that payload in the language files.
 *
 * What is deliberately NOT here is which message to send. That decision
 * belongs to whoever knows what the row was doing — the reconciler reads it
 * off the operation and the ending together, the request path off the two
 * halves of one catch block — and moving it in here would put the module's
 * most consequential judgement behind a generic helper.
 *
 * The provider's own words never reach this. `failure_reason` is redacted and
 * stored for the operator reading the row; a notification carries facts, and
 * the customer's sentence is written in `lang/`.
 */
final readonly class BackupAnnouncements
{
    public function __construct(
        private NotifyCustomer $notify,
    ) {}

    public function raise(Backup $backup, NotificationType $type, string $idempotencyKey): void
    {
        /** @var ?Service $service */
        $service = $backup->service()->first();
        $label = $service?->label;

        $this->notify->execute(
            customerId: $backup->customer_id,
            type: $type,
            idempotencyKey: $idempotencyKey,
            subject: $backup,
            data: [
                'service' => is_string($label) && $label !== ''
                    ? $label
                    : (string) ($service?->getKey() ?? $backup->service_id),
            ],
            link: '/backups',
        );
    }
}
