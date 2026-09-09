<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Exceptions\ReadinessRefused;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;

/**
 * A person taking the declaration back.
 *
 * The state returns to what the providers support — reassessed, not
 * remembered — so a withdrawal on a product whose providers are still live
 * leaves it ready_for_production, one rung down and one declaration away.
 */
final readonly class WithdrawProductSellability
{
    public function __construct(
        private AssessProduct $assess,
        private RecordActAtomically $record,
    ) {}

    public function execute(Product $product, User $operator, string $reason): ProductReadiness
    {
        return $this->record->execute(
            act: function () use ($product, $reason): ProductReadiness {
                $row = ProductReadiness::query()
                    ->where('product', $product->value)
                    ->lockForUpdate()
                    ->first();

                if ($row === null || ! $row->isDeclaredSellable()) {
                    throw ReadinessRefused::notDeclared($product);
                }

                $row->forceFill([
                    'sellability_withdrawn_at' => CarbonImmutable::now(),
                    'sellability_withdrawn_reason' => $reason,
                ])->save();

                $this->assess->execute($product);

                return $row->refresh();
            },
            describe: fn (ProductReadiness $row): AuditedAct => new AuditedAct(
                action: AuditAction::ProductSellabilityWithdrawn,
                subject: $row,
                context: [
                    'product' => $product->value,
                    'reason' => $reason,
                    'to' => $row->state->value,
                    'automatic' => false,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
