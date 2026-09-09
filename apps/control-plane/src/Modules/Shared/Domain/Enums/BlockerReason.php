<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Enums;

/**
 * Why something cannot be enabled.
 *
 * These are the same words the Phase 30B verification matrix uses, on purpose:
 * an operator reading "BLOCKED_LICENCE" on a screen and "BLOCKED_LICENCE" in
 * docs/real-infrastructure-verification-matrix.md is reading about one thing.
 *
 * The rule attached to this enum is a product rule rather than a technical
 * one: never show a generic error when the actual reason is known.
 */
enum BlockerReason: string
{
    /** No machine exists to act on. */
    case Hardware = 'blocked_hardware';

    /** No credential is held, or the one held no longer works. */
    case Credentials = 'blocked_credentials';

    /** A commercial licence or a registry authorisation is missing or expired. */
    case Licence = 'blocked_licence';

    /** The endpoint cannot be reached from where we are. */
    case Network = 'blocked_network';

    /** Everything is present and something is set wrongly. */
    case Configuration = 'blocked_configuration';

    /** Something this depends on is itself blocked. Follow the chain. */
    case Dependency = 'blocked_dependency';

    /**
     * What an operator should do next.
     *
     * Guidance, never automation: this tells somebody where to go, and stops
     * short of buying anything on their behalf.
     */
    public function nextAction(): string
    {
        return match ($this) {
            self::Hardware => 'estate.guidance.hardware',
            self::Credentials => 'estate.guidance.credentials',
            self::Licence => 'estate.guidance.licence',
            self::Network => 'estate.guidance.network',
            self::Configuration => 'estate.guidance.configuration',
            self::Dependency => 'estate.guidance.dependency',
        };
    }

    /**
     * Which blocker to show when several apply.
     *
     * Lowest first. A missing machine outranks a missing credential, because
     * issuing a credential for a server nobody has bought is work in the wrong
     * order.
     *
     * @param  list<self>  $reasons
     */
    public static function first(array $reasons): ?self
    {
        $order = [
            self::Hardware->value => 0,
            self::Licence->value => 1,
            self::Credentials->value => 2,
            self::Network->value => 3,
            self::Configuration->value => 4,
            self::Dependency->value => 5,
        ];

        usort($reasons, fn (self $a, self $b): int => $order[$a->value] <=> $order[$b->value]);

        return $reasons[0] ?? null;
    }
}
