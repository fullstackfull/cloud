<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Subscriptions\Application\DTOs\LifecycleSweep;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Throwable;

/**
 * The two clocks that are not the renewal clock.
 *
 * **Scheduled cancellations.** Cancelling records a date; something has to act
 * on it when it arrives. Nothing did, so a customer who cancelled kept an
 * active subscription for ever — still counted as active, still swept for
 * renewal, and still holding whatever it provisioned.
 *
 * **Dunning.** A failed payment moves a subscription to past_due with a grace
 * deadline, and expiry of that deadline suspends it; expiry of the suspension
 * window terminates it. Both steps are moves somebody has to make. Without
 * them a past_due subscription ran unpaid indefinitely, which is the mirror
 * image of the same defect: the platform recorded a policy it never applied.
 *
 * Cancellations are swept before dunning, and one dunning step is taken per
 * subscription per run — AdvanceDunning's own rule, so that suspension and
 * termination can never land in the same instant with no window for anybody to
 * notice.
 *
 * Per-row failures are counted and logged rather than allowed to abandon the
 * rest of the sweep, exactly as in RenewDueSubscriptions.
 */
final readonly class SweepSubscriptionLifecycle
{
    public function __construct(
        private CancelSubscription $cancel,
        private AdvanceDunning $dunning,
    ) {}

    public function execute(?DateTimeImmutable $at = null, int $limit = 500): LifecycleSweep
    {
        $cancelled = 0;
        $advanced = 0;
        $failed = 0;

        $due = Subscription::query()
            ->dueForCancellation($at)
            ->orderBy('cancel_at')
            ->limit($limit)
            ->get();

        foreach ($due as $subscription) {
            try {
                $this->cancel->applyScheduled($subscription, $at);
                $cancelled++;
            } catch (Throwable $e) {
                $failed++;
                $this->report('A scheduled cancellation could not be applied.', $subscription, $e);
            }
        }

        /*
         * Read after the cancellations, so a subscription that has just been
         * cancelled is not also dunned in the same run: it is no longer in one
         * of the statuses below.
         */
        $overdue = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($overdue as $subscription) {
            try {
                $before = $subscription->status;
                $after = $this->dunning->execute($subscription, $at);

                // Counted only when the clock actually moved the subscription
                // on. A sweep that reports work it did not do is a sweep whose
                // numbers cannot be used to spot one that has stopped working.
                if ($after->status !== $before) {
                    $advanced++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->report('A dunning step could not be taken.', $subscription, $e);
            }
        }

        return new LifecycleSweep(
            cancellationsConsidered: $due->count(),
            cancelled: $cancelled,
            dunningConsidered: $overdue->count(),
            advanced: $advanced,
            failed: $failed,
        );
    }

    private function report(string $message, Subscription $subscription, Throwable $e): void
    {
        Log::error($message, [
            'subscription_id' => $subscription->getKey(),
            'customer_id' => $subscription->customer_id,
            'status' => $subscription->status->value,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
