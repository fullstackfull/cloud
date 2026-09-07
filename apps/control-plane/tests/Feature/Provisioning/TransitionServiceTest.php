<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The one place a service's status may change.
 *
 * The regression at the bottom of this file is why it exists. The action used
 * to answer two questions — "is this already the target status" and "is this
 * transition legal" — from the model the caller handed it, before taking the
 * lock. That copy is stale by definition in the flow that matters most: a
 * listener loads a service once and then moves it twice, and the second move
 * was silently dropped because the caller's copy still held the status it was
 * loaded with. A service whose reactivation failed stayed in `reactivating`
 * forever, invisible to the retry path that looks for suspended services.
 */
final class TransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_legal_transition_is_written_and_stamped(): void
    {
        $service = Service::factory()->active()->create();

        $moved = $this->action()->execute($service, ServiceStatus::Suspended);

        $this->assertSame(ServiceStatus::Suspended, $moved->status);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);
        $this->assertNotNull($moved->suspended_at);
    }

    #[Test]
    public function an_illegal_transition_is_refused(): void
    {
        $service = Service::factory()->create(['status' => ServiceStatus::Terminated]);

        $this->expectException(IllegalStateTransitionException::class);

        $this->action()->execute($service, ServiceStatus::Active);
    }

    #[Test]
    public function transitioning_to_the_status_it_already_holds_is_not_an_error(): void
    {
        $service = Service::factory()->active()->create();

        $moved = $this->action()->execute($service, ServiceStatus::Active);

        $this->assertSame(ServiceStatus::Active, $moved->status);
    }

    #[Test]
    public function the_activation_date_is_the_first_one(): void
    {
        // A service that was suspended and restored has not "gone live" twice.
        $service = Service::factory()->active()->create();
        $firstActivation = $service->activated_at;

        $this->action()->execute($service, ServiceStatus::Suspended);
        $restored = $this->action()->execute($service->fresh(), ServiceStatus::Active);

        $this->assertEquals($firstActivation, $restored->activated_at);
    }

    #[Test]
    public function a_stale_caller_copy_does_not_swallow_the_transition(): void
    {
        /*
         * The regression. The caller holds a model loaded when the service was
         * suspended; the row has since moved on to reactivating. Asking for
         * `suspended` must move the row back, not conclude from the stale copy
         * that there is nothing to do.
         */
        $service = Service::factory()->create(['status' => ServiceStatus::Suspended]);

        $stale = $service->replicate();
        $stale->exists = true;
        $stale->id = $service->id;
        $stale->status = ServiceStatus::Suspended;

        Service::query()->whereKey($service->getKey())
            ->update(['status' => ServiceStatus::Reactivating->value]);

        $moved = $this->action()->execute($stale, ServiceStatus::Suspended);

        $this->assertSame(ServiceStatus::Suspended, $moved->status);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()?->status);
    }

    #[Test]
    public function legality_is_judged_on_the_row_rather_than_the_callers_copy(): void
    {
        /*
         * The same stale copy, the other way round. The caller thinks the
         * service is active and asks for active; the row is terminated. A
         * check taken before the lock would have seen "active to active",
         * called it a no-op, and returned a resurrected service.
         */
        $service = Service::factory()->active()->create();

        $stale = $service->replicate();
        $stale->exists = true;
        $stale->id = $service->id;
        $stale->status = ServiceStatus::Active;

        Service::query()->whereKey($service->getKey())
            ->update(['status' => ServiceStatus::Terminated->value]);

        $this->expectException(IllegalStateTransitionException::class);

        try {
            $this->action()->execute($stale, ServiceStatus::Active);
        } finally {
            $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status);
        }
    }

    private function action(): TransitionService
    {
        return app(TransitionService::class);
    }
}
