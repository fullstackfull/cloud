<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Exceptions\ReverseDnsProviderException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\ReverseDnsProviderFactory;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Take back the PTRs of addresses that have changed hands.
 *
 * ---------------------------------------------------------------------------
 * The intent that nothing acted on
 * ---------------------------------------------------------------------------
 *
 * `IpAllocator::withdrawReverseDns()` marks a released address's record
 * `removing`, and its own comment said why nothing followed: "Publishing and
 * withdrawing PTRs needs a DNS provider client that this module does not yet
 * have, so this leaves a row a reconciler (or an operator) can act on rather
 * than a record that silently stays live."
 *
 * There was no reconciler. Every address this platform ever released kept
 * answering with the last holder's hostname: their company name in a
 * stranger's mail headers, their identity in somebody else's traceroute, and a
 * forward/reverse mismatch that makes the new customer's mail bounce. The
 * quarantine that holds an address back between customers exists to stop
 * exactly that inheritance, and the PTR walked straight through it.
 *
 * ---------------------------------------------------------------------------
 * Why the row is deleted rather than kept
 * ---------------------------------------------------------------------------
 *
 * `reverse_dns_records` is unique on `ip_address_id` — one row per address for
 * the life of the address, across every customer that ever holds it — so a row
 * left behind in any state is the previous holder's hostname sitting in the
 * relation the next holder's assignment eager-loads. There is no state that
 * means "this used to say something and no longer does"; the absence of a row
 * is that state, and it is also what lets the next customer name the address
 * without colliding with a dead one.
 *
 * ---------------------------------------------------------------------------
 * What a failure leaves behind
 * ---------------------------------------------------------------------------
 *
 * A refusal or a timeout leaves the row `removing` with the reason recorded,
 * so the sweep tries again on its next run and an operator can see how long it
 * has been trying. That is the right default here and the opposite of the rule
 * for publishing: re-publishing a name twice can put a record somewhere it was
 * not wanted, while asking twice for a record to be gone converges on gone.
 */
final readonly class WithdrawReverseDnsRecords
{
    public function __construct(
        private ReverseDnsProviderFactory $providers,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @return array{considered: int, withdrawn: int, failed: int}
     */
    public function execute(int $limit = 100): array
    {
        $withdrawn = 0;
        $failed = 0;

        $records = ReverseDnsRecord::query()
            ->with('ipAddress')
            ->where('status', ReverseDnsStatus::Removing->value)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($records as $record) {
            try {
                $this->withdraw($record) ? $withdrawn++ : $failed++;
            } catch (Throwable $e) {
                $failed++;

                Log::error('A withdrawn PTR could not be taken back from the DNS provider.', [
                    'reverse_dns_record_id' => $record->getKey(),
                    'ip_address_id' => $record->ip_address_id,
                    'exception' => $e::class,
                    // The message is not logged here: a DNS client quotes the
                    // request it sent, and that request carried the zone
                    // token. It is redacted where it is stored, below.
                ]);
            }
        }

        return ['considered' => $records->count(), 'withdrawn' => $withdrawn, 'failed' => $failed];
    }

    private function withdraw(ReverseDnsRecord $record): bool
    {
        $ipAddress = $record->ipAddress;

        if ($ipAddress === null) {
            /*
             * The address row is gone, so there is no name to derive and
             * nothing this action can ask the provider about. The row goes
             * with it: a PTR record pointing at an address the platform no
             * longer models is not something a sweep can resolve, and leaving
             * it would keep the previous holder's hostname in the database for
             * ever.
             */
            $record->delete();

            return true;
        }

        try {
            $this->providers->make()->clear(IpAddressValue::fromString($ipAddress->address));
        } catch (ReverseDnsProviderException $e) {
            /*
             * Left `removing`, whichever kind of failure it was, because both
             * of them mean the same thing here: the record may still be live.
             * A refusal will be refused again and needs a person; a timeout is
             * safe to ask about again, and asking twice for a record to be
             * gone converges. The next run does both.
             */
            $record->forceFill([
                'last_error' => $this->redactor->redactString($e->getMessage()),
            ])->save();

            return false;
        }

        $record->delete();

        return true;
    }
}
