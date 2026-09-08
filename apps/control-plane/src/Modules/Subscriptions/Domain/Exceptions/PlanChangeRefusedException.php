<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Subscriptions\Domain\Enums\PlanChangeRefusal;

/**
 * The platform will not move this subscription onto that plan.
 *
 * Carries the reasons rather than a sentence, because the portal says them in
 * two languages and because a customer told only "incompatible" cannot tell
 * "not sold in your currency" from "that would destroy your disk" — and only
 * one of those is worth trying something else about.
 */
final class PlanChangeRefusedException extends DomainException
{
    /**
     * @param  list<PlanChangeRefusal>  $refusals
     */
    private function __construct(
        public readonly array $refusals,
    ) {
        parent::__construct('This subscription cannot be moved onto that plan.');
    }

    /**
     * @param  list<PlanChangeRefusal>  $refusals
     */
    public static function because(array $refusals): self
    {
        return (new self($refusals))->withContext([
            // The reasons travel in the error body as a comma-separated list
            // rather than as a nested structure, because the error envelope
            // this platform publishes carries scalars — and the portal only
            // needs to know which sentences to show.
            'refusals' => implode(',', array_map(
                static fn (PlanChangeRefusal $refusal): string => $refusal->value,
                $refusals,
            )),
        ]);
    }

    public function errorCode(): string
    {
        return 'subscription.plan_change_refused';
    }

    public function httpStatus(): int
    {
        // Refused rather than malformed: the request was understood and the
        // platform declined it.
        return 409;
    }
}
