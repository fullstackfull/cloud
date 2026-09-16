<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

/**
 * How much of the estate a preflight is about.
 *
 * Five, taken from the domain names this repository already uses rather than
 * invented: the whole estate, one datacenter, one provider row, one product,
 * one managed machine. Anything narrower would be a filter on a check rather
 * than a scope, and anything broader is the global run.
 *
 * The point of having scopes at all is that an operator fixing one provider
 * should not have to dial every endpoint in the estate to find out whether
 * they fixed it — and on a real estate, a global run in READ_ONLY_REAL mode
 * is a lot of outbound requests.
 */
enum PreflightScope: string
{
    case Estate = 'estate';
    case Site = 'site';
    case Provider = 'provider';
    case Product = 'product';
    case Machine = 'machine';

    /** Does this scope need a target identifier to mean anything? */
    public function needsTarget(): bool
    {
        return $this !== self::Estate;
    }

    public function label(): string
    {
        return match ($this) {
            self::Estate => 'the whole estate',
            self::Site => 'one datacenter',
            self::Provider => 'one provider',
            self::Product => 'one product',
            self::Machine => 'one machine',
        };
    }
}
