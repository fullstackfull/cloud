<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\DTOs;

/**
 * What changing an account's country or currency would touch, counted,
 * and what stops it. Money is in minor units of the currency named beside
 * it, never converted: a balance in the old currency is reported as
 * exactly that, and is a blocker until it is spent or refunded.
 *
 * @phpstan-type Facts array{
 *   country_changes: bool,
 *   currency_changes: bool,
 *   active_subscriptions: int,
 *   recurring_minor: int,
 *   open_invoices: int,
 *   open_invoices_due_minor: int,
 *   orders_in_flight: int,
 *   domain_operations_in_flight: int,
 *   active_services: int,
 *   wallet_balance_minor: int,
 *   wallet_currency: string,
 *   target_currency_in_catalogue: bool,
 *   tax_before: string,
 *   tax_after: string,
 * }
 */
final readonly class CountryCurrencyImpact
{
    /**
     * @param  Facts  $facts
     * @param  list<string>  $blockers  What must change before this can be applied, in words.
     * @param  list<string>  $warnings  What will be true afterwards and is not a reason to refuse.
     */
    public function __construct(
        public array $facts,
        public array $blockers,
        public array $warnings,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockers !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'facts' => $this->facts,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
        ];
    }
}
