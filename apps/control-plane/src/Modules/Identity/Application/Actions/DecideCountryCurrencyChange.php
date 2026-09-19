<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use DateTimeInterface;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Exceptions\CountryCurrencyChangeRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;

/**
 * An operator's decision on a request.
 *
 * Approval re-runs the analysis first: a request that was clear when the
 * customer asked may have an invoice on it by now, and approving it would
 * schedule a change the sweep would refuse anyway. A blocked request is
 * refused here with the blockers, not scheduled. Approval for "now" is
 * applied in the same act; approval for a later moment is `scheduled`, and
 * the sweep applies it then after checking again.
 *
 * Rejection needs a note. The customer is told either way.
 */
final readonly class DecideCountryCurrencyChange
{
    public function __construct(
        private AnalyseCountryCurrencyChange $analyse,
        private ApplyCountryCurrencyChange $apply,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @throws CountryCurrencyChangeRefusedException
     */
    public function approve(CountryCurrencyChange $change, string $decidedBy, string $note, ?DateTimeInterface $applyAt): CountryCurrencyChange
    {
        if (! $change->state->isDecidable()) {
            throw CountryCurrencyChangeRefusedException::notInState((string) $change->getKey(), $change->state->value, 'approved');
        }

        /** @var Customer $customer */
        $customer = $change->customer()->firstOrFail();

        $impact = $this->analyse->execute($customer, $change->to_country, $change->to_currency);

        if ($impact->isBlocked()) {
            $change->forceFill([
                'state' => CountryCurrencyChangeState::Blocked,
                'impact' => $impact->toArray(),
                'analysed_at' => now(),
            ])->save();

            throw CountryCurrencyChangeRefusedException::blocked((string) $change->getKey());
        }

        $when = $applyAt === null ? now() : $applyAt;

        $change->forceFill([
            'state' => CountryCurrencyChangeState::Scheduled,
            'impact' => $impact->toArray(),
            'analysed_at' => now(),
            'decided_by_user_id' => $decidedBy,
            'decision_note' => $note,
            'decided_at' => now(),
            'scheduled_for' => $when,
        ])->save();

        if ($applyAt === null || ! $applyAt->getTimestamp() > now()->getTimestamp()) {
            return $this->apply->execute($change->refresh());
        }

        return $change->refresh();
    }

    /**
     * @throws CountryCurrencyChangeRefusedException
     */
    public function reject(CountryCurrencyChange $change, string $decidedBy, string $note): CountryCurrencyChange
    {
        if (! $change->state->isDecidable() && $change->state !== CountryCurrencyChangeState::Scheduled) {
            throw CountryCurrencyChangeRefusedException::notInState((string) $change->getKey(), $change->state->value, 'rejected');
        }

        $change->forceFill([
            'state' => CountryCurrencyChangeState::Rejected,
            'decided_by_user_id' => $decidedBy,
            'decision_note' => $note,
            'decided_at' => now(),
            'scheduled_for' => null,
        ])->save();

        $this->notify->execute(
            customerId: $change->customer_id,
            type: NotificationType::CountryCurrencyChangeRejected,
            idempotencyKey: 'country-currency-change:'.$change->getKey().':rejected',
            subject: $change,
            data: ['currency' => $change->to_currency, 'country' => $change->to_country ?? '', 'note' => $note],
            link: '/',
        );

        return $change->refresh();
    }
}
