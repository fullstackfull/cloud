<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidHostnameException;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Push one recorded PTR to the DNS provider.
 *
 * Carries the record's id rather than the model, so the worker reads the row as
 * it is when it runs: a customer who changed their mind twice in ten seconds
 * gets the hostname they last asked for, not the one that happened to be
 * serialised into the first message.
 *
 * ---------------------------------------------------------------------------
 * One attempt, and why
 * ---------------------------------------------------------------------------
 *
 * `$tries = 1`. The two ways this fails need opposite handling and neither of
 * them is "try again":
 *
 *  - A **refusal** is an answer. The provider will refuse the same name just as
 *    firmly in thirty seconds; the record is marked failed, with the provider's
 *    message redacted before it is stored, and the customer can see that their
 *    name was not accepted.
 *
 *  - A **timeout** is not an answer. The platform stopped waiting; the provider
 *    may have published the record a moment later. Retrying is not free — it is
 *    how one address ends up with a record written twice from two different
 *    attempts — so the row is left pending with the failure noted, and a person
 *    decides. This is the same rule the provisioning engine follows for a build
 *    that timed out, for the same reason.
 *
 * Re-publishing after a fix is a new request from the customer, or an operator
 * dispatching this job again deliberately. Neither is this class's decision.
 */
final class PublishReverseDnsRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** See the class docblock: neither failure mode is retried. */
    public int $tries = 1;

    public function __construct(
        private readonly string $recordId,
    ) {}

    public function handle(ReverseDnsProviderFactory $providers, SecretRedactor $redactor): void
    {
        $record = ReverseDnsRecord::query()
            ->with('ipAddress')
            ->find($this->recordId);

        // The record (or its address) went away between the request and the
        // worker — a released address, an operator deleting the record. There
        // is nothing to publish and nothing to report.
        if ($record === null) {
            return;
        }

        /** @var IpAddress $ipAddress */
        $ipAddress = $record->ipAddress;

        /*
         * Re-checked here, not merely at the front door.
         *
         * SetReverseDns refuses to record a name for an address the caller no
         * longer holds, but that check happened before this job was queued and
         * the two are separated by however long the queue is. In between, the
         * service can be cancelled, the customer can be suspended or an
         * operator can reclaim the block: the assignment is stamped released,
         * the address goes into quarantine and is handed to somebody else, and
         * the hostname sitting in this row is now the *previous* holder's.
         * Publishing it at that point is precisely what the front-door check
         * exists to prevent — their company name in a stranger's mail headers —
         * only later, and from a worker nobody is watching.
         *
         * Nothing is recorded as failed: the name was not refused and the
         * customer has nothing to fix. The row is on its way out (release marks
         * it `removing`) and the address surface no longer shows it to anyone.
         */
        if (! $this->addressIsStillHeld($ipAddress)) {
            return;
        }

        $address = IpAddressValue::fromString($ipAddress->address);

        try {
            /*
             * Validated again here, at the last point before the call. The row
             * was written by an action that validates, but this job is also the
             * thing an operator re-dispatches against a row that has been sitting
             * in the database for a month, and the provider must never be handed
             * a name that has not been through these rules.
             */
            $hostname = Hostname::fromString($record->hostname);
        } catch (InvalidHostnameException $e) {
            $record->recordFailure($e->getMessage());

            return;
        }

        try {
            $providers->make()->publish($address, $hostname);
        } catch (ReverseDnsProviderException $e) {
            if ($e->isIndeterminate()) {
                /*
                 * Left pending on purpose. "Failed" would be a claim the
                 * platform cannot support — the record may well be live — and a
                 * customer reading it would set the name again, which is the
                 * retry this job refuses to perform on their behalf.
                 */
                $record->forceFill([
                    'last_error' => $redactor->redactString($e->getMessage()),
                ])->save();

                return;
            }

            // recordFailure redacts before it stores: a zone client that fails
            // quotes the request it sent, and that request carried the token.
            $record->recordFailure($e->getMessage());

            return;
        }

        $record->forceFill([
            'status' => ReverseDnsStatus::Active,
            'last_error' => null,
        ])->save();
    }

    /**
     * Whether anybody currently holds this address.
     *
     * A live assignment — one with no `released_at` — is the only thing that
     * makes a PTR somebody's to publish. The partial unique index on
     * `ip_assignments (ip_address_id) WHERE released_at IS NULL` means there is
     * at most one, so this is a single indexed existence check rather than a
     * scan of the address's history.
     */
    private function addressIsStillHeld(IpAddress $ipAddress): bool
    {
        return IpAssignment::query()
            ->where('ip_address_id', $ipAddress->getKey())
            ->whereNull('released_at')
            ->exists();
    }
}
