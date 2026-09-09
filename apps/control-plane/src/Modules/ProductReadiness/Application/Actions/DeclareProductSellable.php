<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Exceptions\ReadinessRefused;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;

/**
 * The rung a person climbs.
 *
 * The platform can establish that every requirement is met by a real
 * provider enabled in production. It cannot establish that the product
 * works: that is something a real provider did when a person asked it to do
 * a real thing, and this records that a person did. The reference is where
 * the evidence lives — a validation report, a ticket, a runbook — and it is
 * required, because "I checked" without a where is not a record.
 *
 * Refused unless the product is ready for production RIGHT NOW: the row is
 * reassessed under a lock first, so a declaration cannot be made on the
 * strength of a screen loaded before a credential was revoked.
 */
final readonly class DeclareProductSellable
{
    public function __construct(
        private AssessProduct $assess,
        private RecordActAtomically $record,
    ) {}

    public function execute(Product $product, User $operator, string $reason, string $validationReference): ProductReadiness
    {
        return $this->record->execute(
            act: function () use ($product, $operator, $reason, $validationReference): ProductReadiness {
                $verdict = $this->assess->execute($product);

                if (! $verdict->state->atLeast(ProductReadinessState::ReadyForProduction)) {
                    throw ReadinessRefused::notReadyForProduction($product, $verdict->state, $verdict->detail);
                }

                $row = ProductReadiness::query()
                    ->where('product', $product->value)
                    ->lockForUpdate()
                    ->firstOrFail();

                $row->forceFill([
                    'state' => ProductReadinessState::ReadyToSell,
                    'state_changed_at' => CarbonImmutable::now(),
                    'declared_sellable_at' => CarbonImmutable::now(),
                    'declared_sellable_by' => $operator->getKey(),
                    'declared_reason' => $reason,
                    'validation_reference' => $validationReference,
                    'sellability_withdrawn_at' => null,
                    'sellability_withdrawn_reason' => null,
                ])->save();

                return $row;
            },
            describe: fn (ProductReadiness $row): AuditedAct => new AuditedAct(
                action: AuditAction::ProductDeclaredSellable,
                subject: $row,
                context: [
                    'product' => $product->value,
                    'reason' => $reason,
                    'validation_reference' => $validationReference,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
