<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Application\Jobs\DeliverNotification;
use Lynomia\Modules\Notifications\Application\Listeners\NotifyOnProvisioningOutcome;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A plan change whose provider half failed tells the customer what happened
 * to the money, truthfully for every change that sends it (a residue the
 * round-six fixers recorded).
 *
 * The one message said "Nothing has been charged for the change". A change
 * that owed nothing (a downgrade, a move between equal prices) is queued when
 * it is made, and that is true of it. An upgrade is queued only when its
 * proration invoice is paid, under a key naming that invoice
 * (`plan-change:<subscription>:<plan>:invoice:<id>`), so a failed resize or
 * package change queued that way has been charged: the payment is held for
 * an operator to complete the change or return it (docs/billing.md). The
 * paid case now has its own message. The review message is one type for
 * both, and speaks of a payment only conditionally, which is true of each.
 */
final class APlanChangeThatFailsSaysWhatHappenedToTheMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([DeliverNotification::class]);

        $this->customer = Customer::factory()->create();
        $this->service = Service::factory()->create(['customer_id' => $this->customer->id, 'kind' => 'vps', 'label' => 'web-01']);
    }

    /**
     * @return iterable<string, array{ProvisioningJobKind}>
     */
    public static function planChangeKinds(): iterable
    {
        yield 'a resize' => [ProvisioningJobKind::Resize];
        yield 'a hosting package change' => [ProvisioningJobKind::ChangeHostingPackage];
    }

    #[Test]
    #[DataProvider('planChangeKinds')]
    public function a_paid_change_that_failed_does_not_say_nothing_was_charged(ProvisioningJobKind $kind): void
    {
        $job = $this->job($kind, 'plan-change:'.Str::ulid().':'.Str::ulid().':invoice:'.Str::ulid());

        app(NotifyOnProvisioningOutcome::class)->failed(new ProvisioningJobFailed((string) $job->id, $kind, (string) $this->service->id, FailureClass::Permanent, 'compute.refused'));

        foreach (['en', 'ar'] as $locale) {
            $body = $this->renderedBody($locale);
            $this->assertStringNotContainsString('Nothing has been charged', $body);
            $this->assertStringNotContainsString('لم تُحمَّل أي رسوم', $body);
        }
        $this->assertStringContainsString('held', $this->renderedBody('en'), 'The customer who paid is not told where the payment is.');
        $this->assertStringContainsString('محفوظ', $this->renderedBody('ar'));
    }

    #[Test]
    #[DataProvider('planChangeKinds')]
    public function a_change_that_owed_nothing_and_failed_says_nothing_was_charged(ProvisioningJobKind $kind): void
    {
        $job = $this->job($kind, 'plan-change:'.Str::ulid().':'.Str::ulid().':change:'.Str::ulid());

        app(NotifyOnProvisioningOutcome::class)->failed(new ProvisioningJobFailed((string) $job->id, $kind, (string) $this->service->id, FailureClass::Permanent, 'compute.refused'));

        $this->assertStringContainsString('Nothing has been charged', $this->renderedBody('en'));
        $this->assertStringContainsString('لم تُحمَّل أي رسوم', $this->renderedBody('ar'));

        /*
         * But it was not "left as it was" (the re-audit after round six): a
         * change that owed nothing moved the subscription, and its price and
         * any credit, when it was made. What was left as it was is the
         * service.
         */
        $this->assertStringNotContainsString('left as it was', $this->renderedBody('en'));
        $this->assertStringNotContainsString('وبقي كما كان', $this->renderedBody('ar'));
        $this->assertStringContainsString('now on the new plan and billed at its price', $this->renderedBody('en'));
        $this->assertStringContainsString('the change to web-01 itself did not complete', $this->renderedBody('en'));
        $this->assertStringContainsString('ويُفوتَر بسعرها', $this->renderedBody('ar'));
    }

    #[Test]
    #[DataProvider('planChangeKinds')]
    public function a_change_whose_money_is_held_says_the_renewal_bills_the_new_plan(ProvisioningJobKind $kind): void
    {
        /*
         * "Held" said nothing of the renewal (the re-audit after round six):
         * the subscription is on the new plan, and a renewal before the
         * change is completed bills its price. Said, in both messages that
         * speak of a payment held.
         */
        $paid = $this->job($kind, 'plan-change:'.Str::ulid().':'.Str::ulid().':invoice:'.Str::ulid());
        app(NotifyOnProvisioningOutcome::class)->failed(new ProvisioningJobFailed((string) $paid->id, $kind, (string) $this->service->id, FailureClass::Permanent, 'compute.refused'));
        $this->assertStringContainsString('billed at its price', $this->renderedBody('en'));
        $this->assertStringContainsString('يُفوتَر بسعرها', $this->renderedBody('ar'));

        Notification::query()->delete();

        app(NotifyOnProvisioningOutcome::class)->needsReview(new ProvisioningJobNeedsReview((string) $paid->id, $kind, (string) $this->service->id, null, FailureClass::Capacity, 'no room'));
        $this->assertStringContainsString('billed at its price', $this->renderedBody('en'));
        $this->assertStringContainsString('يُفوتَر بسعرها', $this->renderedBody('ar'));
    }

    #[Test]
    public function a_paid_change_that_failed_or_waits_does_not_say_the_service_runs_as_it_was(): void
    {
        /*
         * A8-1 (the re-audit after round seven): a resize the hypervisor
         * made and could not read back failed the job, and the customer was
         * told the service was "still running as it was" - of a machine that
         * had been resized. Such a resize now stops in review, and neither
         * message a paid change can end in says what state the service is
         * in beyond what the platform knows.
         */
        $paid = $this->job(ProvisioningJobKind::Resize, 'plan-change:'.Str::ulid().':'.Str::ulid().':invoice:'.Str::ulid());

        app(NotifyOnProvisioningOutcome::class)->failed(new ProvisioningJobFailed((string) $paid->id, ProvisioningJobKind::Resize, (string) $this->service->id, FailureClass::Permanent, 'vps.unknown_machine'));
        $this->assertStringNotContainsString('as it was', $this->renderedBody('en'));
        $this->assertStringNotContainsString('كما كان', $this->renderedBody('ar'));

        Notification::query()->delete();

        app(NotifyOnProvisioningOutcome::class)->needsReview(new ProvisioningJobNeedsReview((string) $paid->id, ProvisioningJobKind::Resize, (string) $this->service->id, null, FailureClass::Timeout, 'vps.resize_unverified'));
        $this->assertStringNotContainsString('as it was', $this->renderedBody('en'));
        $this->assertStringNotContainsString('كما كان', $this->renderedBody('ar'));
        $this->assertStringContainsString('may not match the new plan', $this->renderedBody('en'));
        $this->assertStringContainsString('قد لا يطابق', $this->renderedBody('ar'));
    }

    #[Test]
    public function an_unpaid_change_whose_machine_a_destroy_removed_does_not_say_it_runs_as_it_was(): void
    {
        /*
         * B-2 (the verification of round eight A): a plan-change resize a
         * destroy overlapped ends vps.unknown_machine, permanent, and the
         * customer read that the service "is still running as it was" - of a
         * machine that is gone.
         */
        $job = $this->job(ProvisioningJobKind::Resize, 'plan-change:'.Str::ulid().':'.Str::ulid().':change:'.Str::ulid());

        app(NotifyOnProvisioningOutcome::class)->failed(new ProvisioningJobFailed((string) $job->id, ProvisioningJobKind::Resize, (string) $this->service->id, FailureClass::Permanent, 'vps.unknown_machine'));

        $this->assertStringNotContainsString('as it was', $this->renderedBody('en'));
        $this->assertStringNotContainsString('running', $this->renderedBody('en'));
        $this->assertStringNotContainsString('كما كان', $this->renderedBody('ar'));
        $this->assertStringNotContainsString('يعمل', $this->renderedBody('ar'));
        $this->assertStringContainsString('Nothing has been charged', $this->renderedBody('en'));
    }

    #[Test]
    public function the_review_message_speaks_of_a_payment_only_if_there_was_one(): void
    {
        $job = $this->job(ProvisioningJobKind::Resize, 'plan-change:'.Str::ulid().':'.Str::ulid().':change:'.Str::ulid());

        app(NotifyOnProvisioningOutcome::class)->needsReview(new ProvisioningJobNeedsReview((string) $job->id, ProvisioningJobKind::Resize, (string) $this->service->id, null, FailureClass::Capacity, 'no room'));

        $this->assertStringNotContainsString('What you paid', $this->renderedBody('en'));
        $this->assertStringContainsString('If you paid', $this->renderedBody('en'));
    }

    private function job(ProvisioningJobKind $kind, string $key): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'service_id' => $this->service->id,
            'customer_id' => $this->customer->id,
            'kind' => $kind,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Failed,
            'idempotency_key' => $key,
        ]);
    }

    private function renderedBody(string $locale): string
    {
        $notification = Notification::query()->where('customer_id', $this->customer->id)->sole();

        return app(RenderNotification::class)->execute($notification, $locale)->body;
    }
}
