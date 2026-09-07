<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Lynomia\Modules\Billing\Domain\Exceptions\ImmediateCancellationNotConfirmedException;
use Lynomia\Modules\Billing\Domain\Exceptions\SubscriptionAlreadyEndedException;
use Lynomia\Modules\Subscriptions\Application\Actions\CancelSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * A cancellation asked for by the customer who owns the subscription.
 *
 * The mechanics belong to CancelSubscription and are not repeated here: this
 * action adds the two preconditions the customer surface needs and delegates.
 *
 * **The irreversible form has to be asked for by name.** Cancelling at the end
 * of the paid period is reversible — the row stays active, the service keeps
 * running, and revoke() puts it back. Cancelling immediately is not: the state
 * machine has no edge out of cancelled, the service stops now, and nothing of
 * the paid remainder comes back. So it takes the subscription's own id as a
 * confirmation, the same proof a reinstall takes in the hostname, rather than
 * a boolean that a client library defaults and a retry loop resends. The check
 * lives here and not in the form request so that a support script or a console
 * command reaching this action is held to it too.
 *
 * **The subscription must not already have ended.** The underlying action is
 * built for the platform's own callers — a dunning sweep, an operator, a
 * chargeback handler — and its scheduled form deliberately does no status
 * checking, because it is the form a *sweep* uses. Asked of a cancelled
 * subscription it would happily stamp a fresh `cancel_at` onto a dead row and
 * report success.
 *
 * There is no idempotency key. A cancellation neither spends money nor
 * provisions hardware, and the scheduled form converges by construction: it
 * leaves the first date it recorded alone (`cancel_at ??=`). A repeat of the
 * immediate form is refused rather than converged — the row already ended, and
 * saying so is more honest than a second 200 for an operation that did
 * nothing.
 */
final readonly class CancelCustomerSubscription
{
    public function __construct(
        private CancelSubscription $cancelSubscription,
    ) {}

    /**
     * @param  bool  $immediately  true ends the subscription now; false — the
     *                             default — lets it run out the period the
     *                             customer has already paid for.
     * @param  ?string  $confirmation  must equal the subscription's own id
     *                                 when $immediately is true; ignored
     *                                 otherwise.
     *
     * @throws ImmediateCancellationNotConfirmedException
     * @throws SubscriptionAlreadyEndedException
     */
    public function execute(
        Subscription $subscription,
        bool $immediately = false,
        ?string $confirmation = null,
    ): Subscription {
        /*
         * Compared before anything else, and compared exactly — no trimming of
         * internal whitespace, no case folding. The confirmation is not a
         * lookup, it is evidence that a person read the screen.
         */
        if ($immediately && ($confirmation === null || ! hash_equals((string) $subscription->getKey(), $confirmation))) {
            throw ImmediateCancellationNotConfirmedException::make();
        }

        if ($subscription->status->isTerminal()) {
            throw SubscriptionAlreadyEndedException::forStatus(
                (string) $subscription->getKey(),
                $subscription->status,
            );
        }

        return $this->cancelSubscription->execute($subscription, immediately: $immediately);
    }
}
