<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TransitionOrderTest extends TestCase
{
    use RefreshDatabase;

    private TransitionOrder $transition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transition = app(TransitionOrder::class);
    }

    #[Test]
    public function a_valid_transition_is_applied_and_recorded(): void
    {
        $order = Order::factory()->create();

        $this->transition->execute($order, OrderStatus::PendingPayment, reason: 'checkout submitted');

        $order->refresh();
        $this->assertSame(OrderStatus::PendingPayment, $order->status);

        $record = $order->transitions()->sole();
        $this->assertSame(OrderStatus::Draft, $record->from_status);
        $this->assertSame(OrderStatus::PendingPayment, $record->to_status);
        $this->assertSame('checkout submitted', $record->reason);
    }

    #[Test]
    public function the_actor_is_recorded_so_an_operator_can_be_asked_why(): void
    {
        $admin = User::factory()->create();
        $order = Order::factory()->status(OrderStatus::Paid)->create();

        $this->transition->execute(
            $order,
            OrderStatus::ManualReview,
            actorType: 'admin',
            actor: $admin,
            reason: 'risk score above threshold',
            context: ['risk_score' => 82],
        );

        $record = $order->transitions()->sole();
        $this->assertSame('admin', $record->actor_type);
        $this->assertSame($admin->id, $record->actor_user_id);
        $this->assertSame(['risk_score' => 82], $record->context);
    }

    #[Test]
    public function the_correlation_id_links_the_change_back_to_its_request(): void
    {
        Context::add('request_id', '01TESTCORRELATIONID0000000');
        $order = Order::factory()->create();

        $this->transition->execute($order, OrderStatus::PendingPayment);

        $this->assertSame('01TESTCORRELATIONID0000000', $order->transitions()->sole()->correlation_id);
    }

    #[Test]
    public function an_illegal_transition_throws_and_changes_nothing(): void
    {
        $order = Order::factory()->create();

        try {
            $this->transition->execute($order, OrderStatus::Active);
            $this->fail('Expected the transition to be rejected.');
        } catch (IllegalStateTransitionException) {
            // Expected.
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame(0, $order->transitions()->count());
    }

    #[Test]
    public function a_repeated_transition_converges_without_a_duplicate_audit_entry(): void
    {
        $order = Order::factory()->create();

        $this->transition->execute($order, OrderStatus::PendingPayment);
        $this->transition->execute($order->fresh(), OrderStatus::PendingPayment);
        $this->transition->execute($order->fresh(), OrderStatus::PendingPayment);

        // A redelivered webhook must converge silently, but it must not
        // manufacture audit entries suggesting something happened three times.
        $this->assertSame(1, $order->transitions()->count());
    }

    #[Test]
    public function a_stale_in_memory_copy_cannot_overwrite_a_newer_status(): void
    {
        /*
         * The concurrency this guards against: a webhook confirming payment and
         * a timeout job cancelling the same order both read status=pending,
         * both pass the state-machine check, and without the re-read under
         * lock, both write — producing an audit trail describing a sequence
         * that never happened.
         */
        $order = Order::factory()->status(OrderStatus::PendingPayment)->create();
        $staleCopy = Order::query()->find($order->id);

        // Another worker gets there first.
        $this->transition->execute($order, OrderStatus::Cancelled, reason: 'payment window expired');

        $this->assertNotNull($staleCopy);
        $this->expectException(IllegalStateTransitionException::class);

        // The stale copy still believes the order is pending payment.
        $this->transition->execute($staleCopy, OrderStatus::Paid, reason: 'webhook confirmed');
    }

    #[Test]
    public function lifecycle_timestamps_are_stamped_as_the_order_advances(): void
    {
        $order = Order::factory()->create();

        $this->transition->execute($order, OrderStatus::PendingPayment);
        $this->assertNotNull($order->fresh()->placed_at);

        $this->transition->execute($order->fresh(), OrderStatus::Paid);
        $this->assertNotNull($order->fresh()->paid_at);

        $this->transition->execute($order->fresh(), OrderStatus::QueuedForProvisioning);
        $this->transition->execute($order->fresh(), OrderStatus::Provisioning);
        $this->transition->execute($order->fresh(), OrderStatus::Active);
        $this->assertNotNull($order->fresh()->completed_at);
    }

    #[Test]
    public function a_timestamp_is_never_overwritten_once_set(): void
    {
        $order = Order::factory()->create();

        $this->transition->execute($order, OrderStatus::PendingPayment);
        $placedAt = $order->fresh()->placed_at;

        $this->travel(1)->hour();

        // Payment fails and the customer retries, returning to pending payment.
        $this->transition->execute($order->fresh(), OrderStatus::PaymentFailed);
        $this->transition->execute($order->fresh(), OrderStatus::PendingPayment);

        // "Placed at" means when the order was placed, not when it was last
        // retried; overwriting it would corrupt every report built on it.
        $this->assertTrue($placedAt->equalTo($order->fresh()->placed_at));
    }

    #[Test]
    public function the_full_history_is_reconstructable_from_the_transitions(): void
    {
        $order = Order::factory()->create();

        foreach ([
            OrderStatus::PendingPayment,
            OrderStatus::PaymentFailed,
            OrderStatus::PendingPayment,
            OrderStatus::Paid,
            OrderStatus::QueuedForProvisioning,
            OrderStatus::Provisioning,
            OrderStatus::ProvisioningFailed,
            OrderStatus::ManualReview,
            OrderStatus::Active,
        ] as $status) {
            $this->transition->execute($order->fresh(), $status);
        }

        $history = $order->transitions()->orderBy('created_at')->orderBy('id')->get();

        $this->assertCount(9, $history);
        $this->assertSame(OrderStatus::Draft, $history->first()->from_status);
        $this->assertSame(OrderStatus::Active, $history->last()->to_status);

        // Each entry's "from" must be the previous entry's "to", or the trail
        // is not a trail.
        foreach ($history->sliding(2) as $pair) {
            $this->assertSame($pair->first()->to_status, $pair->last()->from_status);
        }
    }
}
