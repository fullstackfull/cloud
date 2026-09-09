<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Identity\Domain\Enums\CountryCurrencyChangeState;
use Lynomia\Modules\Identity\Infrastructure\Models\CountryCurrencyChange;
use Throwable;

/**
 * Apply the scheduled changes whose moment has come. Each one is checked
 * again before anything is written; one that cannot be applied is left in
 * needs_review for a person and does not stop the others.
 */
final readonly class ApplyDueCountryCurrencyChanges
{
    public function __construct(
        private ApplyCountryCurrencyChange $apply,
    ) {}

    /**
     * @return array{considered: int, applied: int, held: int, failed: int}
     */
    public function execute(int $limit = 100): array
    {
        $due = CountryCurrencyChange::query()
            ->where('state', CountryCurrencyChangeState::Scheduled->value)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->get();

        $applied = 0;
        $held = 0;
        $failed = 0;

        foreach ($due as $change) {
            try {
                $after = $this->apply->execute($change);

                if ($after->state === CountryCurrencyChangeState::Applied) {
                    $applied++;
                } else {
                    $held++;
                }
            } catch (Throwable $e) {
                $failed++;

                Log::error('A scheduled country/currency change could not be applied.', [
                    'change_id' => $change->getKey(),
                    'exception' => $e::class,
                ]);
            }
        }

        return ['considered' => $due->count(), 'applied' => $applied, 'held' => $held, 'failed' => $failed];
    }
}
