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
