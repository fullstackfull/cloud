<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Domain\Exceptions\CountryCurrencyChangeRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;

/**
 * The customer takes the request back. Possible while it is open and not
 * yet applied — including after an operator scheduled it, right up to the
 * moment the sweep applies it.
 */
final readonly class WithdrawCountryCurrencyChange
{
    /**
     * @throws CountryCurrencyChangeRefusedException
     */
    public function execute(CountryCurrencyChange $change): CountryCurrencyChange
    {
        if (! $change->state->isOpen()) {
            throw CountryCurrencyChangeRefusedException::notInState((string) $change->getKey(), $change->state->value, 'withdrawn');
        }

        $change->forceFill([
            'state' => CountryCurrencyChangeState::Withdrawn,
            'decided_at' => now(),
        ])->save();

        return $change->refresh();
    }
}
