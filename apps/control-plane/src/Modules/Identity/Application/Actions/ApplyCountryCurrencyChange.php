<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Exceptions\CountryCurrencyChangeRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;

/**
 * The one place the account's country and currency are written after
 * registration.
 *
 * Checks the facts one last time. A blocker that appeared between the
 * approval and this moment — an invoice issued, an order placed — moves
 * the row to `needs_review` with the blockers, applies nothing, tells the
 * customer, and writes the audit row that says why. Otherwise the two
 * columns change, the row is `applied`, and every document already written
 * is left exactly as it was: nothing is converted, ever.
 */
final readonly class ApplyCountryCurrencyChange
{
    public function __construct(
        private AnalyseCountryCurrencyChange $analyse,
        private RecordAuditEntry $audit,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @throws CountryCurrencyChangeRefusedException
     */
    public function execute(CountryCurrencyChange $change): CountryCurrencyChange
    {
        if ($change->state !== CountryCurrencyChangeState::Scheduled) {
            throw CountryCurrencyChangeRefusedException::notInState((string) $change->getKey(), $change->state->value, 'applied');
        }

        return DB::transaction(function () use ($change): CountryCurrencyChange {
            /** @var Customer $customer */
            $customer = Customer::query()->whereKey($change->customer_id)->lockForUpdate()->firstOrFail();

            $impact = $this->analyse->execute($customer, $change->to_country, $change->to_currency);

            if ($impact->isBlocked()) {
                $change->forceFill([
                    'state' => CountryCurrencyChangeState::NeedsReview,
                    'impact' => $impact->toArray(),
                    'analysed_at' => now(),
                ])->save();

                $this->audit->execute(
                    AuditAction::CountryCurrencyChangeBlocked,
                    $change,
                    (string) $change->customer_id,
                    ['blockers' => $impact->blockers, 'to_currency' => $change->to_currency, 'to_country' => $change->to_country],
                );

                $this->notify->execute(
                    customerId: $change->customer_id,
                    type: NotificationType::CountryCurrencyChangeNeedsReview,
                    idempotencyKey: 'country-currency-change:'.$change->getKey().':needs_review:'.now()->timestamp,
                    subject: $change,
                    data: ['currency' => $change->to_currency, 'country' => $change->to_country ?? ''],
                    link: '/',
                );

                return $change->refresh();
            }

            $before = ['country' => $customer->country, 'currency' => $customer->currency];

            $customer->forceFill([
                'country' => $change->to_country,
                'currency' => $change->to_currency,
            ])->save();

            $change->forceFill([
                'state' => CountryCurrencyChangeState::Applied,
                'impact' => $impact->toArray(),
                'analysed_at' => now(),
                'applied_at' => now(),
            ])->save();

            $this->audit->execute(
                AuditAction::CountryCurrencyChangeApplied,
                $change,
                (string) $change->customer_id,
                ['from' => $before, 'to' => ['country' => $change->to_country, 'currency' => $change->to_currency]],
            );

            $this->notify->execute(
                customerId: $change->customer_id,
                type: NotificationType::CountryCurrencyChangeApplied,
                idempotencyKey: 'country-currency-change:'.$change->getKey().':applied',
                subject: $change,
                data: ['currency' => $change->to_currency, 'country' => $change->to_country ?? ''],
                link: '/',
            );

            return $change->refresh();
        });
    }
}
