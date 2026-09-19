<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Stop selling something, without pretending it was never sold.
 *
 * ===========================================================================
 * WHY THIS IS NOT A DELETE
 * ===========================================================================
 *
 * A catalogue row that has been sold is referenced by history. `order_items`
 * points at a plan, `subscriptions` point at a plan, a hosting account points
 * at the package it was built under, and every one of those is somebody's
 * answer to "what did I buy and what was I charged". The order line keeps its
 * own snapshot so the money survives regardless — but the link is how an
 * operator gets from an invoice to the thing that was sold, and destroying it
 * turns a support question into an archaeology exercise.
 *
 * So withdrawal is `is_active = false`. The row stays, the history stays
 * readable, and the customer surface stops offering it because
 * `scopePurchasable()` already filters on exactly that column. Recording the
 * same slug again re-lists it, which is the other half of the same property:
 * an operator who withdrew something by mistake has a route back that is not
 * a support ticket.
 *
 * ===========================================================================
 * AND WHY IT DOES NOT TOUCH WHAT IS ALREADY RUNNING
 * ===========================================================================
 *
 * Withdrawing a plan stops future sales. It does not suspend, terminate or
 * reprice a service somebody is already using: those are lifecycle decisions
 * with their own actions, their own audit entries and their own customer
 * notifications, and coupling them to a catalogue edit would mean an operator
 * tidying a price list could end a customer's server.
 */
final readonly class WithdrawFromSale
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function product(Product $product, User $operator): Product
    {
        return $this->deactivate(
            $product,
            AuditAction::CatalogueProductWithdrawn,
            ['slug' => $product->slug, 'kind' => $product->kind->value],
            $operator,
        );
    }

    public function plan(Plan $plan, User $operator): Plan
    {
        return $this->deactivate(
            $plan,
            AuditAction::CataloguePlanWithdrawn,
            ['slug' => $plan->slug],
            $operator,
        );
    }

    public function price(PlanPrice $price, User $operator): PlanPrice
    {
        return $this->deactivate(
            $price,
            AuditAction::CataloguePriceWithdrawn,
            [
                'plan_id' => (string) $price->plan_id,
                'currency' => $price->currency,
                'billing_period' => $price->billing_period,
            ],
            $operator,
        );
    }

    /**
     * @template T of Model
     *
     * @param  T  $subject
     * @param  array<string, mixed>  $context
     * @return T
     */
    private function deactivate(Model $subject, AuditAction $action, array $context, User $operator): Model
    {
        return $this->record->execute(
            act: function () use ($subject): Model {
                DB::transaction(function () use ($subject): void {
                    $subject->forceFill(['is_active' => false])->save();
                });

                return $subject;
            },
            describe: fn (Model $saved): AuditedAct => new AuditedAct(
                action: $action,
                subject: $saved,
                context: $context + ['operator' => (string) $operator->getKey()],
            ),
        );
    }
}
