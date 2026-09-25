<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Puts a suspended account back into service.
 *
 * The other half of what makes suspension the right answer to an unpaid
 * invoice: it is undone by one call, and the customer is back exactly where
 * they were — same files, same mail, same databases, same node, same address.
 * Nothing here restores anything, because nothing was destroyed.
 *
 * The suspension reason is cleared along with the timestamp, which also stops
 * the retention clock: an account that is serving again is not waiting to be
 * released.
 *
 * ---------------------------------------------------------------------------
 * The service's window is called off here too
 * ---------------------------------------------------------------------------
 *
 * The account's clock is not the only one. Its service carries its own
 * `retention_ends_at`, stamped by BeginRetentionWindow, and the nightly sweep
 * reads that one. The subscription listener cancels it when a customer pays;
 * the operator's "put it back by hand" endpoint did not, so an account put
 * back by hand sat behind a service still holding a closed, customer-chosen
 * window. Before F-18 the sweep then destroyed it — the account had no
 * `suspended_at`, and a missing date was read as an elapsed window. F-18 made
 * the termination refuse, which turns that into a sweep failure every night
 * rather than a deleted site; cancelling the window here stops the shape
 * being made at all. Every caller of this action gets it, so the listener's
 * own cancel() is now a harmless repeat.
 *
 * ---------------------------------------------------------------------------
 * Repairing rows that drifted before this shipped
 * ---------------------------------------------------------------------------
 *
 * This action returns early for an account that is not Suspended, so it cannot
 * heal one that is already Active behind a closed window. Those rows are found
 * by hand, and the query is run as written by
 * TheRetentionSweepTest::the_repair_query_finds_exactly_the_drifted_rows, which
 * pins the returned set exactly:
 *
 * ```sql
 * -- Active accounts behind a closed, customer-chosen window nothing has
 * -- started ending: each one fails the retention sweep every night.
 * SELECT s.id AS service_id,
 *        s.retention_ends_at,
 *        ha.id AS hosting_account_id,
 *        ha.username,
 *        hn.hostname,
 *        hn.panel
 *   FROM services s
 *   JOIN hosting_accounts ha ON ha.service_id = s.id
 *   JOIN hosting_nodes hn ON hn.id = ha.hosting_node_id
 *  WHERE s.status = 'suspended'
 *    AND s.retention_ends_at IS NOT NULL
 *    AND s.retention_ends_at <= NOW()
 *    AND s.ended_reason = 'customer_cancelled'
 *    AND s.termination_requested_at IS NULL
 *    AND ha.status = 'active';
 * ```
 *
 * For each row, first — and this step is not optional — confirm at `hostname`
 * on that panel that `username` exists and is serving. Only then call the
 * window off: `app(BeginRetentionWindow::class)->cancel($service)` for that
 * service id.
 *
 * Five of the six predicates each exclude a shape nothing else does: a service
 * that is not suspended; a window still running; a window for non-payment,
 * which the sweep never acts on; a service already on its way out; and an
 * account that is not Active. The last one matters most. A Suspended account
 * behind a closed window is also what a termination leaves when it reached the
 * panel and died before writing its row, and cancelling that window would file
 * a customer whose data is gone as a returning one; a Pending or Failed account
 * is serving nothing, and cancel() is the wrong repair for it — that row fails
 * the sweep too, and nothing yet owns what it means. `retention_ends_at IS NOT
 * NULL` is the sixth: it survives ablation, because `<= NOW()` already
 * excludes a NULL, and it is kept because the sweep's own query carries it
 * beside its `<=`. A service with no hosting_accounts row at all also fails
 * the sweep nightly, and this join cannot see it; that is a separate concern.
 *
 * There is deliberately no `AND ha.terminated_at IS NULL`. It exists to stop
 * somebody restoring it, and it is four facts. ReserveHostingNodeCapacity
 * re-arms a row by setting its status and clears neither `terminated_at` nor
 * `suspended_at`, so a row can be Active and carry a termination stamp. The
 * clause would therefore exclude a row rebuilt from a Terminated one — a shape
 * no supported flow produces today. The same rebuilt shape is reachable from a
 * Failed build, which carries no stamp, and the clause never touched that
 * one; the manual step above is what guards both. And it did remove rows that
 * are wanted — Active, drifted, stamped, failing the sweep every night — so
 * restoring it trades a real row for an unreachable one and closes nothing.
 * Nor does the query filter on `hn.panel`: it is selected for the manual step,
 * and narrowing on it returns nothing on a real estate.
 */
final readonly class UnsuspendHostingAccount
{
    public function __construct(
        private HostingProviderFactory $providers,
        private BeginRetentionWindow $retention,
    ) {}

    /**
     * @throws HostingProviderException
     */
    public function execute(HostingAccount $account): HostingAccount
    {
        if ($account->status !== HostingAccountStatus::Suspended) {
            return $account;
        }

        $node = $account->node()->firstOrFail();

        $this->providers->for($node)->unsuspendAccount($node, $account->username);

        $account->forceFill([
            'status' => HostingAccountStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        // Serving again, so its service is not waiting to be released either.
        // After the panel and the row, like everything else here: a window
        // called off for an account still suspended at the panel would be a
        // customer nobody is billing and nobody will ever clean up.
        $service = $account->service()->first();

        if ($service !== null) {
            $this->retention->cancel($service);
        }

        return $account;
    }
}
