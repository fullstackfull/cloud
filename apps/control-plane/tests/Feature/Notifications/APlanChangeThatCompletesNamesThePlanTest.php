<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Application\Jobs\DeliverNotification;
use Lynomia\Modules\Notifications\Application\Listeners\NotifyOnProvisioningOutcome;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobSucceeded;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A plan change that completes names the plan, in the reader's language.
 *
 * `plan_change_completed` is ":service is now on the :plan plan", and nothing
 * sent `plan`: the customer read ":plan" (a residue the round-six fixers
 * recorded). The plan's name is sent as the catalogue keeps it, in both
 * languages, and read in the reader's.
 */
final class APlanChangeThatCompletesNamesThePlanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_completed_message_names_the_plan_the_change_delivered_in_each_language(): void
    {
        Queue::fake([DeliverNotification::class]);

        $customer = Customer::factory()->create();
        $service = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps', 'label' => 'web-01']);
        $plan = Plan::factory()->create(['name' => ['en' => 'Large', 'ar' => 'كبير']]);
        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Succeeded,
            'payload' => ['plan_id' => (string) $plan->id, 'vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80],
        ]);

        app(NotifyOnProvisioningOutcome::class)->succeeded(new ProvisioningJobSucceeded((string) $job->id, ProvisioningJobKind::Resize, (string) $service->id, null));

        $notification = Notification::query()->where('customer_id', $customer->id)->sole();
        $render = app(RenderNotification::class);

        $this->assertSame('web-01 is now on the Large plan', $render->execute($notification, 'en')->title);
        $arabic = $render->execute($notification, 'ar')->title;
        $this->assertStringContainsString('كبير', $arabic);
        $this->assertStringNotContainsString(':plan', $arabic);
        $this->assertStringNotContainsString('Large', $arabic, 'The Arabic reader was given the English name.');
    }
}
