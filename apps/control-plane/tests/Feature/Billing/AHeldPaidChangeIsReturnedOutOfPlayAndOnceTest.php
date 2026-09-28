<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewDueSubscriptions;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAHeldPaidChange;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAPlanChangeNoLongerDeliverable;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Billing\Concerns\SettlesAPaidUpgradeOnAMachine;

/**
 * The verification of round ten M rejected 0967196 on two money items, both
 * from its own reservations, and four owed oracles. Each test here names the
 * scenario it pins; H, I, K and L are the verifier's probes, and each was red
 * on 0967196 for the reason given.
 *
 * B1 - a paid change held on a live service (its resize in review or failed)
 * was returned by refunding the capture by hand, which moved the money and
 * left the change in play: the subscription on the large plan and billed at
 * it (H: renewal 90.000 for a 4096 MiB machine, the downgrade refused as
 * `service_busy`), or a downgrade crediting the refunded upgrade a second
 * time (I: 12.000 credited against 3.000 charged, the wallet at 109.000). Now
 * the raw refund is refused while the change is in play, and
 * `POST /api/admin/provisioning/jobs/{job}/return-payment` returns it: the
 * money to the wallet, the plan put back, the change returned, the job
 * cancelled.
 *
 * B2 - a later paid change "superseded" an earlier one at the end although
 * the later one failed too, so of two failed upgrades the earlier was kept
 * (L: 73.000, not 100.000), and which was kept depended on the order they
 * were asked in; a failed upgrade followed by a downgrade whose shrink failed
 * kept 12.000 (K). Now only a later change that was delivered supersedes.
 */
final class AHeldPaidChangeIsReturnedOutOfPlayAndOnceTest extends BillingApiTestCase
{
    use SettlesAPaidUpgradeOnAMachine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpThePaidUpgradeWorld();
    }

    // ---------------------------------------------------------------- B1

    #[Test]
    public function h_a_resize_in_review_on_a_live_service_is_returned_out_of_play_and_the_renewal_bills_the_old_plan(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $this->resize->refresh()->status);
        $small = Plan::query()->where('slug', 'small')->sole();

        $this->theRawRefundIsRefused();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'Node full; the change will not be made.'])
            ->assertOk()
            ->assertJsonPath('data.status', ProvisioningJobStatus::Cancelled->value)
            ->assertJsonPath('data.returned_to_wallet_minor', self::PAID)
            ->assertJsonPath('data.plan_restored', true);

        $this->assertSame(100_000, $this->wallet());
        $subscription = $this->subscription->refresh();
        $this->assertSame((string) $small->id, (string) $subscription->plan_id, 'back on the plan the machine runs');
        $this->assertSame(9_000, $subscription->recurring_amount_minor);
        $this->assertSame(ProvisioningJobStatus::Cancelled, $this->resize->refresh()->status);
        $this->assertArrayHasKey(ReturnAHeldPaidChange::RETURNED, $this->resize->result);
        $this->assertNotNull($this->change->refresh()->returned_at);
        $this->assertTheHeldReturnWasRecordedOnce('billing.held_plan_change_returned');

        // Out of play: nothing holds the next change, and no retry runs it.
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'r10m-h-down')
            ->postJson("/api/v1/subscriptions/{$this->subscription->id}/plan", ['plan_id' => $small->id, 'price_id' => $this->priceOf($small)->id])
            ->assertJsonMissing(['refusals' => 'service_busy']);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/retry', ['evidence' => 'Try again.'])
            ->assertStatus(409);
        $this->assertSame(100_000, $this->wallet());

        // The renewal bills the plan the machine runs.
        $this->travelTo(CarbonImmutable::instance($subscription->current_period_end)->addMinute());
        app(RenewDueSubscriptions::class)->execute();
        $renewal = Invoice::query()->where('subscription_id', $this->subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $this->assertSame(9_000, (int) $renewal->total_minor);
    }

    #[Test]
    public function i_a_failed_resize_on_a_live_service_is_returned_and_no_downgrade_credits_it_again(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $this->assertSame(ProvisioningJobStatus::Failed, $this->resize->refresh()->status);
        $small = Plan::query()->where('slug', 'small')->sole();

        $this->theRawRefundIsRefused();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'The machine lost its provider id; not delivering.'])
            ->assertOk()
            ->assertJsonPath('data.status', ProvisioningJobStatus::Cancelled->value)
            ->assertJsonPath('data.plan_restored', true);

        $this->assertSame(100_000, $this->wallet());
        $this->assertSame((string) $small->id, (string) $this->subscription->refresh()->plan_id);
        $this->assertTheHeldReturnWasRecordedOnce('billing.held_plan_change_returned');

        // The downgrade that used to credit the refunded upgrade again has
        // nothing to credit: the subscription is already on the small plan.
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'r10m-i-down')
            ->postJson("/api/v1/subscriptions/{$this->subscription->id}/plan", ['plan_id' => $small->id, 'price_id' => $this->priceOf($small)->id]);
        $this->assertSame(100_000, $this->wallet(), 'nothing credited twice');

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/retry', ['evidence' => 'Try again.'])
            ->assertStatus(409);
    }

    #[Test]
    public function a_held_change_the_customer_already_moved_off_is_returned_without_moving_the_plan_or_paying_twice(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $small = Plan::query()->where('slug', 'small')->sole();

        // The customer moves back by themselves (the verifier's control J):
        // 30.000 credited, 3.000 charged - the whole upgrade back, net.
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'r10m-j-down')
            ->postJson("/api/v1/subscriptions/{$this->subscription->id}/plan", ['plan_id' => $small->id, 'price_id' => $this->priceOf($small)->id])
            ->assertOk();
        $this->assertSame(100_000, $this->wallet());

        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'Taking the failed upgrade out of play.'])
            ->assertOk()
            ->assertJsonPath('data.plan_restored', false);

        $this->assertSame(100_000, $this->wallet(), 'the invoice holds nothing the downgrade did not already return');
        $this->assertSame((string) $small->id, (string) $this->subscription->refresh()->plan_id);
        $this->assertSame(1, $this->notices('billing.held_plan_change_returned_plan_kept'));
        $this->assertSame(ProvisioningJobStatus::Cancelled, $this->resize->refresh()->status);
    }

    #[Test]
    public function the_return_takes_its_locks_job_then_subscription_then_invoice_then_wallet(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();

        $locked = [];
        DB::listen(static function ($query) use (&$locked): void {
            if (str_contains(strtolower($query->sql), 'for update') && preg_match('/from\s+"([a-z_]+)"/i', $query->sql, $m) === 1) {
                $locked[] = $m[1];
            }
        });

        app(ReturnAHeldPaidChange::class)->execute($this->resize, 'test');

        $first = static fn (string $table): int => (int) array_search($table, $locked, true);
        $this->assertSame('provisioning_jobs', $locked[0] ?? null, implode(', ', $locked));
        $this->assertLessThan($first('invoices'), $first('subscriptions'), 'a paid invoice is taken after its subscription: '.implode(', ', $locked));
        $this->assertLessThan($first('wallets'), $first('invoices'), implode(', ', $locked));
    }

    #[Test]
    public function the_return_is_refused_where_it_does_not_apply_and_behind_payment_refund(): void
    {
        $this->aPaidUpgradeSettled();

        // Queued: it may still deliver.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'Too early.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'provisioning.return_not_stopped');

        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();

        // Support cannot move money back out.
        $support = $this->operator();
        $support->syncRoles([Role::Support->value]);
        $this->actingAs($support)
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'Not mine to return.'])
            ->assertForbidden();

        // A job that delivers no paid change.
        $other = ProvisioningJob::factory()->kind(ProvisioningJobKind::Stop)->create([
            'status' => ProvisioningJobStatus::NeedsReview, 'service_id' => $this->machine->service_id, 'customer_id' => $this->customer->id,
        ]);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$other->id.'/return-payment', ['evidence' => 'Not a plan change.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'provisioning.return_not_a_paid_change');

        // An ended service's paid change is the end's to return; the job is closed.
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', ['evidence' => 'Service ended.'])
            ->assertStatus(409)->assertJsonPath('error.code', 'provisioning.return_service_ended');

        $this->assertSame(100_000 - self::PAID, $this->wallet());
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::ProvisioningPaidChangeReturned->value)->count());
    }

    #[Test]
    public function the_return_writes_nothing_the_request_supplies_onto_the_job(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        $payload = DB::table('provisioning_jobs')->where('id', $this->resize->id)->value('payload');

        // The body a mass-assignment writer would obey.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/return-payment', [
                'evidence' => 'Returning it.',
                'payload' => ['vcpu' => 64],
                'payload->vcpu' => 64,
                'status' => 'succeeded',
                'idempotency_key' => 'plan-change:x:y:invoice:01JZZZZZZZZZZZZZZZZZZZZZZZ',
            ])
            ->assertOk();

        $row = DB::table('provisioning_jobs')->where('id', $this->resize->id)->first();
        $this->assertSame($payload, $row->payload, 'the payload is the same bytes');
        $this->assertSame(ProvisioningJobStatus::Cancelled->value, $row->status);
        $this->assertSame((string) $this->resize->idempotency_key, $row->idempotency_key);
    }

    // ---------------------------------------------------------------- B2

    #[Test]
    public function k_a_failed_upgrade_then_a_downgrade_whose_shrink_failed_is_returned_in_full_at_the_end(): void
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $medium = $this->plan('medium', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 40], 45_000);

        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'r10m-k-down')
            ->postJson("/api/v1/subscriptions/{$this->subscription->id}/plan", ['plan_id' => $medium->id, 'price_id' => $this->priceOf($medium)->id])
            ->assertOk();
        $this->assertSame(88_000, $this->wallet(), '30.000 credited, 15.000 charged');
        $this->runEveryResizeUntilItStops();
        $this->assertSame(4096, $this->machine->refresh()->memory_mib, 'neither change was made');

        $this->terminateTheService();

        // 27.000 paid, 15.000 back through the downgrade, 12.000 at the end.
        $this->assertSame(100_000, $this->wallet());
    }

    #[Test]
    public function l_two_paid_upgrades_that_both_failed_are_both_returned_at_the_end(): void
    {
        $b = $this->twoPaidUpgradesThatBothFailed();

        $this->terminateTheService();

        $this->assertSame(100_000, $this->wallet());
        $this->assertSame(self::PAID, $this->returnedAgainst($this->invoice));
        $this->assertSame(30_000, $this->returnedAgainst($b));
    }

    #[Test]
    public function l_asked_earlier_first_the_two_failed_upgrades_are_both_returned(): void
    {
        $b = $this->twoPaidUpgradesThatBothFailed();
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);

        $return = app(ReturnAnUpgradeTheEndPrevented::class);
        $this->assertSame(self::PAID, DB::transaction(fn (): int => $return->execute((string) $this->invoice->id)));
        $this->assertSame(30_000, DB::transaction(fn (): int => $return->execute((string) $b->id)));
        $this->assertSame(100_000, $this->wallet());
    }

    #[Test]
    public function l_asked_later_first_the_two_failed_upgrades_are_both_returned(): void
    {
        $b = $this->twoPaidUpgradesThatBothFailed();
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);

        $return = app(ReturnAnUpgradeTheEndPrevented::class);
        $this->assertSame(30_000, DB::transaction(fn (): int => $return->execute((string) $b->id)));
        $this->assertSame(self::PAID, DB::transaction(fn (): int => $return->execute((string) $this->invoice->id)));
        $this->assertSame(100_000, $this->wallet());
    }

    #[Test]
    public function a_later_upgrade_that_was_delivered_still_supersedes_an_earlier_one(): void
    {
        // The guard on the other side of B2: a later change that was made
        // decided the machine, and the earlier one is not returned for it.
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $b = $this->aSecondPaidUpgrade();
        DB::table('provisioning_jobs')->where('idempotency_key', 'like', '%:invoice:'.$b->id)->update(['status' => ProvisioningJobStatus::Succeeded->value]);

        $this->terminateTheService();

        $this->assertSame(0, $this->returnedAgainst($this->invoice), 'superseded by a change that was delivered');
        $this->assertSame(0, $this->returnedAgainst($b), 'delivered, so kept');
    }

    // ------------------------------------------------ the owed oracles

    #[Test]
    public function m3_a_resize_that_goes_to_review_after_its_service_ended_is_returned_as_it_goes(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->terminateTheService();
        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'still queued: it may yet run');

        $this->runTheResizeUntilItStops();

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $this->resize->refresh()->status);
        $this->assertSame(100_000, $this->wallet(), 'returned as the job went to review, before any close');
        $this->assertSame(self::PAID, $this->returnedAgainst($this->invoice));
    }

    #[Test]
    public function m16_a_close_whose_return_fails_is_refused_and_asked_again(): void
    {
        $this->aPaidUpgradeSettled();
        $this->theRoomGoes();
        $this->runTheResizeUntilItStops();
        DB::table('services')->where('id', $this->machine->service_id)->update(['status' => ServiceStatus::Terminated->value]);

        $this->theWalletRefusesCreditsFor($this->invoice);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated.'])
            ->assertServerError();

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $this->resize->refresh()->status, 'the close was refused with its return');
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::ProvisioningClosed->value)->count());
        $this->assertSame(100_000 - self::PAID, $this->wallet());

        $this->theWalletAcceptsCreditsAgain();
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated.'])
            ->assertOk();
        $this->assertSame(100_000, $this->wallet());
    }

    #[Test]
    public function reservation_3_a_failed_job_whose_return_failed_goes_to_review_where_the_close_asks_again(): void
    {
        $this->aPaidUpgradeSettled();
        $this->terminateTheService();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);

        $this->theWalletRefusesCreditsFor($this->invoice);
        $this->runTheResizeUntilItStops();

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $this->resize->refresh()->status, 'left where a close asks again');
        $this->assertStringContainsString('close the job to ask again', (string) $this->resize->last_error);
        $this->assertSame(100_000 - self::PAID, $this->wallet());

        $this->theWalletAcceptsCreditsAgain();
        $listed = collect($this->actingAs($this->operator())->getJson('/api/admin/provisioning/needs-review')->json('data'))->firstWhere('id', $this->resize->id);
        $this->assertTrue($listed['closable'] ?? null);
        $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$this->resize->id.'/close', ['evidence' => 'Service terminated.'])
            ->assertOk();
        $this->assertSame(100_000, $this->wallet());
    }

    #[Test]
    public function m18_a_paid_package_change_that_fails_after_its_service_ended_is_returned(): void
    {
        [$service, $invoice, $job] = $this->aPaidPackageChangeOnAnEndedHostingService();

        event(new ProvisioningJobFailed((string) $job->id, ProvisioningJobKind::ChangeHostingPackage, (string) $service->id, FailureClass::Permanent, 'hosting.panel_refused'));

        $this->assertSame(self::PAID, $this->returnedAgainst($invoice));
        $this->assertSame(1, $this->notices('billing.plan_change_returned_at_the_end'));
    }

    // ----------------------------------------------------------- helpers

    private function theRawRefundIsRefused(): void
    {
        /** @var Transaction $charge */
        $charge = Transaction::query()->where('invoice_id', $this->invoice->id)->where('provider', 'wallet')->sole();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/transactions/'.$charge->id.'/refunds', ['amount_minor' => self::PAID, 'reason' => 'Held change returned by hand.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.refund_of_a_paid_change_in_play');

        $this->assertSame(100_000 - self::PAID, $this->wallet(), 'the refused refund moved nothing');
    }

    private function assertTheHeldReturnWasRecordedOnce(string $notice): void
    {
        $this->assertSame(self::PAID, $this->returnedAgainst($this->invoice));
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::ProvisioningPaidChangeReturned->value)->count());
        $this->assertSame(1, AuditEntry::query()
            ->where('action', AuditAction::PlanChanged->value)
            ->where('context->reason', ReturnAPlanChangeNoLongerDeliverable::HELD_AUDIT_REASON)
            ->count());
        $this->assertSame(1, $this->notices($notice));
    }

    private function notices(string $type): int
    {
        return DB::table('notifications')->where('customer_id', $this->customer->id)->where('type', $type)->count();
    }

    private function returnedAgainst(Invoice $invoice): int
    {
        return (int) DB::table('wallet_transactions')
            ->where('invoice_id', $invoice->id)
            ->where('kind', WalletTransactionKind::Topup->value)
            ->sum('amount_minor');
    }

    private function runEveryResizeUntilItStops(): void
    {
        foreach (ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->get() as $job) {
            for ($i = 0; $i < 10 && in_array($job->refresh()->status, [ProvisioningJobStatus::Queued, ProvisioningJobStatus::Running], true); $i++) {
                DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
                $this->runWorker($job);
            }
        }
    }

    private function aSecondPaidUpgrade(): Invoice
    {
        $xl = $this->plan('xl', ['vcpu' => 16, 'memory_mib' => 32768, 'disk_gib' => 40], 180_000);

        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'r10m-up-2')
            ->postJson("/api/v1/subscriptions/{$this->subscription->id}/plan", ['plan_id' => $xl->id, 'price_id' => $this->priceOf($xl)->id])
            ->assertOk();
        /** @var Invoice $b */
        $b = Invoice::query()->where('subscription_id', $this->subscription->id)->where('status', InvoiceStatus::Open->value)->sole();
        $this->actingAs($this->owner)->withHeaders(['Idempotency-Key' => 'r10m-wallet-2'])
            ->postJson('/api/v1/invoices/'.$b->id.'/wallet-credit')
            ->assertOk();
        app(ResizeOnPlanChangeSettlement::class)->handle(new InvoicePaid((string) $b->id, (string) $this->customer->id, null, (string) $this->subscription->id, CarbonImmutable::now()));

        return $b->refresh();
    }

    private function twoPaidUpgradesThatBothFailed(): Invoice
    {
        $this->aPaidUpgradeSettled();
        DB::table('virtual_machines')->where('id', $this->machine->id)->update(['provider_id' => null]);
        $this->runTheResizeUntilItStops();
        $b = $this->aSecondPaidUpgrade();
        $this->assertSame(30_000, (int) $b->total_minor);
        $this->runEveryResizeUntilItStops();
        $this->assertSame([ProvisioningJobStatus::Failed->value], ProvisioningJob::query()->where('kind', ProvisioningJobKind::Resize)->pluck('status')->map(static fn ($s) => $s->value)->unique()->values()->all());
        $this->assertSame(43_000, $this->wallet());

        return $b;
    }

    private function theWalletRefusesCreditsFor(Invoice $invoice): void
    {
        DB::statement("CREATE OR REPLACE FUNCTION r10m_the_wallet_refuses() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'r10m: the wallet refused the credit'; END \$\$ LANGUAGE plpgsql");
        DB::statement(sprintf(
            "CREATE TRIGGER r10m_the_wallet_refuses BEFORE INSERT ON wallet_transactions FOR EACH ROW WHEN (NEW.invoice_id = '%s' AND NEW.amount_minor > 0) EXECUTE FUNCTION r10m_the_wallet_refuses()",
            $invoice->id,
        ));
    }

    private function theWalletAcceptsCreditsAgain(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS r10m_the_wallet_refuses ON wallet_transactions');
    }

    /**
     * @return array{Service, Invoice, ProvisioningJob}
     */
    private function aPaidPackageChangeOnAnEndedHostingService(): array
    {
        [$customer] = $this->accountWithOwner();
        $this->customer = $customer;
        $product = $this->product;
        $basic = Plan::factory()->create(['product_id' => $product->getKey(), 'slug' => 'basic']);
        $pro = Plan::factory()->create(['product_id' => $product->getKey(), 'slug' => 'pro']);
        PlanPrice::factory()->create(['plan_id' => $pro->getKey(), 'currency' => 'KWD', 'billing_period' => BillingPeriod::Monthly, 'recurring_amount_minor' => 90_000, 'setup_amount_minor' => 0, 'is_active' => true]);
        $subscription = $this->paidSubscriptionOn($customer, $pro);

        $ledger = app(WalletLedger::class);
        $ledger->walletFor($customer, 'KWD');

        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(), 'kind' => 'shared_hosting',
            'subscription_id' => $subscription->getKey(), 'status' => ServiceStatus::Terminated,
        ]);
        $invoice = Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(), 'subscription_id' => $subscription->getKey(), 'currency' => 'KWD',
            'subtotal_minor' => self::PAID, 'total_minor' => self::PAID, 'amount_paid_minor' => self::PAID,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(), 'kind' => InvoiceItemKind::Proration, 'description' => 'Package upgrade',
            'quantity' => 1, 'unit_amount_minor' => self::PAID, 'total_minor' => self::PAID, 'subscription_id' => $subscription->getKey(),
        ]);
        Transaction::factory()->create(['customer_id' => $customer->getKey(), 'invoice_id' => $invoice->getKey(), 'amount_minor' => self::PAID, 'currency' => 'KWD']);
        PlanChange::query()->create([
            'subscription_id' => $subscription->getKey(), 'from_plan_id' => $basic->getKey(), 'to_plan_id' => $pro->getKey(),
            'currency' => 'KWD', 'units' => 1, 'credit_minor' => 0, 'charge_minor' => self::PAID, 'wallet_credit_minor' => 0,
            'from_recurring_amount_minor' => 9_000, 'proration_invoice_id' => $invoice->getKey(), 'resources' => [],
            'changed_at' => now()->subDay(), 'delivered_at' => now()->subDay(),
        ]);
        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::ChangeHostingPackage)->create([
            'status' => ProvisioningJobStatus::Failed, 'service_id' => $service->getKey(), 'customer_id' => $customer->getKey(),
            'idempotency_key' => sprintf('plan-change:%s:%s:invoice:%s', $subscription->getKey(), $pro->getKey(), $invoice->getKey()),
        ]);

        return [$service, $invoice, $job];
    }
}
