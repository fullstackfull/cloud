<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ServiceStatusChanged;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Listeners\EndTheSubscriptionWithItsService;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Billing\Concerns\SettlesAPaidUpgradeOnAMachine;

/**
 * A paid upgrade whose resize never grew the machine, and whose service then
 * ended, is returned to the wallet - once, whichever of the service's end,
 * the wind-up of its subscription, the close of its job or the job's own
 * failure comes first.
 *
 * The re-audit's final pass (R10-M): a 27.000 KWD upgrade paid from the
 * wallet was settled (`delivered_at` stamped), its resize stopped in review on
 * node capacity, and the service was terminated and the job closed. The
 * wind-up read `delivered_at` as delivered, the close moved no money, and the
 * 27.000 stayed with the platform, while the customer had been told it was
 * "held until the change is applied, or returned if it cannot be". A resize
 * that failed outright came to the same. Each test below failed on the base
 * for that reason; the retry that delivers is the guard on the other side.
 */
final class APaidChangeItsServiceEndedBeforeDeliveringIsReturnedTest extends BillingApiTestCase
{
    use SettlesAPaidUpgradeOnAMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpThePaidUpgradeWorld();
    }

    #[Test]
    public function a_paid_resize_in_review_is_returned_when_its_service_is_terminated_and_the_close_after_returns_nothing(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $this->resize->refresh()->status);
        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'held while the service lives');

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet(), 'the service ended with the change undelivered: the payment goes back');
        $this->assertReturnedOnce();

        // Closing the job afterwards returns nothing more.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated; machine gone.'])
            ->assertOk();

        $this->assertSame(ProvisioningJobStatus::Cancelled, $this->resize->refresh()->status);
        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function closing_the_job_returns_it_when_the_subscription_has_not_been_wound_up_and_the_wind_up_after_returns_nothing(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();

        // The service ended and nothing heard it: the subscription is live.
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);
        $this->assertFalse($this->subscription->refresh()->status->isTerminal());
        $this->assertSame(100_000 - self::PAID, $this->wallet());

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated; machine gone.'])
            ->assertOk();

        $this->assertSame(100_000, $this->wallet(), 'the close takes the last pointer off the list, so it returns the payment');
        $this->assertReturnedOnce();

        // The subscription is wound up afterwards: nothing more.
        app(EndTheSubscriptionWithItsService::class)->handle(new ServiceStatusChanged(
            serviceId: (string) $this->machine->service_id,
            orderId: null,
            from: ServiceStatus::Active,
            to: ServiceStatus::Terminated,
        ));

        $this->assertTrue($this->subscription->refresh()->status->isTerminal());
        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function a_resize_that_fails_outright_after_its_service_ended_is_returned_when_it_fails(): void
    {
        $this->aPaidUpgradeSettled();

        // The service ends while the resize is still queued: nothing is known yet.
        $this->terminateTheService();
        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'a resize still queued may yet run');

        // The machine is gone from the hypervisor's books; the resize fails outright.
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Failed, $this->resize->refresh()->status);

        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function a_paid_resize_that_fails_outright_on_a_live_service_is_held_then_returned_when_the_service_ends(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Failed, $this->resize->refresh()->status);

        // Live: held for an operator (docs/runbooks/provisioning-stuck.md §6).
        $this->assertSame(100_000 - self::PAID, $this->wallet());
        $this->assertNull($this->change->refresh()->returned_at);

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    #[Test]
    public function what_an_operator_already_returned_of_a_held_change_is_not_returned_again_when_the_service_ends(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();

        /*
         * This used to refund the capture by hand, and assert only that the
         * end did not return it again. The raw refund left the change in play
         * (B1, the verification of round ten M) and is now refused; the
         * operator returns a held change with return-payment instead.
         */
        /** @var Transaction $charge */
        $charge = Transaction::query()->where('invoice_id', $this->invoice->id)->where('provider', 'wallet')->sole();
        $this->actingAs($this->operator())
            ->postJson('/api/admin/transactions/'.$charge->id.'/refunds', ['amount_minor' => self::PAID, 'reason' => 'Paid resize failed; returned by hand.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.refund_of_a_paid_change_in_play');
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'Paid resize failed; returned by an operator.'])
            ->assertOk();
        $this->assertSame(100_000, $this->wallet());

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet(), 'what the invoice no longer holds is not returned twice');
        $this->assertSame(self::PAID, (int) DB::table('wallet_transactions')->where('invoice_id', $this->invoice->id)
            ->where('kind', WalletTransactionKind::Topup->value)->sum('amount_minor'));
        $this->assertSame(0, $this->returnNotices(), 'the end returned nothing, so it says nothing');
    }

    #[Test]
    public function a_retry_that_delivers_is_not_returned_when_the_service_ends_afterwards(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();

        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 131072]);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/retry', ['evidence' => 'Node grown; retrying the paid resize.'])
            ->assertOk();
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $this->resize->refresh()->status);
        $this->assertSame(16384, $this->machine->refresh()->memory_mib);

        $this->terminateTheService();

        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'delivered, so kept');
        $this->assertNull($this->change->refresh()->returned_at);
        $this->assertSame(0, $this->returnNotices());
    }

    #[Test]
    public function the_close_and_the_wind_up_asked_in_either_order_return_it_once(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);

        $return = app(ReturnAnUpgradeTheEndPrevented::class);

        // Asked again and again, from each side: the invoice's lock and what
        // it still holds decide, not the order.
        $first = DB::transaction(fn (): int => $return->execute((string) $this->invoice->id));
        $second = DB::transaction(fn (): int => $return->execute((string) $this->invoice->id));
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated; machine gone.'])
            ->assertOk();
        $third = DB::transaction(fn (): int => $return->execute((string) $this->invoice->id));

        $this->assertSame([self::PAID, 0, 0], [$first, $second, $third]);
        $this->assertSame(100_000, $this->wallet());
        $this->assertReturnedOnce();
    }

    private function assertReturnedOnce(): void
    {
        $this->assertSame(self::PAID, (int) DB::table('wallet_transactions')
            ->where('invoice_id', $this->invoice->id)
            ->where('kind', WalletTransactionKind::Topup->value)
            ->sum('amount_minor'), 'returned to the wallet against the invoice, once');

        $change = $this->change->refresh();
        $this->assertNotNull($change->returned_at);
        $this->assertNotNull($change->return_reason);

        $audits = AuditEntry::query()
            ->where('action', AuditAction::PlanChanged->value)
            ->where('context->reason', ReturnAnUpgradeTheEndPrevented::AUDIT_REASON)
            ->get();
        $this->assertCount(1, $audits);
        $this->assertSame(self::PAID, $audits->first()->context['returned_to_wallet_minor']);
        $this->assertSame((string) $this->invoice->id, $audits->first()->context['proration_invoice_id']);

        $this->assertSame(1, $this->returnNotices());
    }
}
