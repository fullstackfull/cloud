<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Exceptions\CountryCurrencyChangeRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * Run the analysis again on an open request and move it between `blocked`
 * and `awaiting_approval` as the facts say. The customer does this after
 * paying an invoice or ending a subscription; the sweep does it before
 * applying anything. A request an operator has already scheduled is not
 * touched here — the sweep re-checks it at its moment.
 */
final readonly class ReanalyseCountryCurrencyChange
{
    public function __construct(
        private AnalyseCountryCurrencyChange $analyse,
    ) {}

    /**
     * @throws CountryCurrencyChangeRefusedException
     */
    public function execute(CountryCurrencyChange $change): CountryCurrencyChange
    {
        if (! in_array($change->state, [CountryCurrencyChangeState::Blocked, CountryCurrencyChangeState::AwaitingApproval, CountryCurrencyChangeState::NeedsReview], true)) {
            throw CountryCurrencyChangeRefusedException::notInState((string) $change->getKey(), $change->state->value, 'checked again');
        }

        /** @var Customer $customer */
        $customer = $change->customer()->firstOrFail();

        $impact = $this->analyse->execute($customer, $change->to_country, $change->to_currency);

        $next = match (true) {
            $impact->isBlocked() => CountryCurrencyChangeState::Blocked,
            $change->state === CountryCurrencyChangeState::NeedsReview => CountryCurrencyChangeState::NeedsReview,
            default => CountryCurrencyChangeState::AwaitingApproval,
        };

        $change->forceFill([
            'state' => $next,
            'impact' => $impact->toArray(),
            'analysed_at' => now(),
        ])->save();

        return $change->refresh();
    }
}
