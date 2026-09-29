<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuysSharedHosting;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * R4 (the re-audit after round five): a service ended from its own side ends
 * its subscription through EndTheSubscriptionWithItsService, which runs once
 * the service's move has committed and never throws. A deadlock inside the
 * wind-up was caught and logged, and nothing else happened: the service was
 * terminated, the subscription stayed active and its open invoice stayed
 * payable. The wind-up is the outermost transaction there, so it is now rolled
 * back and tried again.
 *
 * Committed for real (no RefreshDatabase transaction), because a retry happens
 * only at the outermost level; LeavesNothingCommitted empties every table
 * after. The deadlock is a real SQLSTATE 40P01 from a trigger on the invoice's
 * void, raised once (a sequence, which a rollback does not rewind).
 */
final class AServiceEndedThroughADeadlockStillEndsItsSubscriptionTest extends TestCase
{
    use BuysSharedHosting;
    use LeavesNothingCommitted;
    use RefreshDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::unprepared('drop trigger if exists r6_deadlock_once on invoices; drop function if exists r6_deadlock_once(); drop sequence if exists r6_deadlock_once_seq;');
        $this->emptyEveryTable();

        parent::tearDown();
    }

    #[Test]
    public function a_deadlock_in_the_wind_up_is_retried_and_the_subscription_ends(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $small = $this->sharedHostingPlan('small');
        $big = $this->sharedHostingPlan('big');
        $big->prices()->update(['recurring_amount_minor' => 9_000]);
        $order = $this->buySharedHosting($customer, $small);

        $subscription = Subscription::query()->where('customer_id', $customer->getKey())->sole();
        app(ApplyPlanChange::class)->execute($subscription, $big, $big->prices()->firstOrFail(), null, $user);
        $open = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();

        DB::unprepared(<<<'SQL'
            create sequence r6_deadlock_once_seq;
            create function r6_deadlock_once() returns trigger as $$
            begin
              if new.status = 'void' and old.status <> 'void' and nextval('r6_deadlock_once_seq') = 1 then
                raise exception 'deadlock detected' using errcode = '40P01';
              end if;
              return new;
            end $$ language plpgsql;
            create trigger r6_deadlock_once before update on invoices for each row execute function r6_deadlock_once();
        SQL);

        $service = Service::query()->where('order_id', $order->getKey())->sole();
        app(TransitionService::class)->execute($service, ServiceStatus::Suspended);
        app(TransitionService::class)->execute($service->fresh() ?? $service, ServiceStatus::Terminated);

        $this->assertSame(2, (int) DB::selectOne('select last_value from r6_deadlock_once_seq')->last_value, 'The injected deadlock did not fire, or the wind-up was not tried again.');
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()?->status);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()?->status, 'A deadlock left the subscription of a terminated service active.');
        $this->assertSame(InvoiceStatus::Void, $open->fresh()?->status, 'A deadlock left the invoice of a terminated service payable.');
    }
}
