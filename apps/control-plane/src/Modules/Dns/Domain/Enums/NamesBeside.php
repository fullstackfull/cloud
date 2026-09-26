<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * Whether the names beside one of the platform's own hosts are held.
 *
 * A reserved name covers itself, every parent of it and everything beneath
 * it. It does not cover a sibling: holding `api.example.net` refuses
 * `example.net` and `x.api.example.net`, and leaves `www.example.net` and
 * `mail.example.net` to any account. Those are the names a customer would
 * most want to be the platform's, so each platform host is asked separately
 * whether they are held — which is the question the estate preflight's
 * verdict has to follow (F-26).
 *
 * The honest answer needs the host's registrable domain, and that cannot be
 * computed without a public-suffix list. What can be established without one:
 *
 *   - **Held** — a reserved name lies strictly above the host, so every name
 *     beside it is beneath that name and refused. Whether that name is itself
 *     the registrable domain is not established; the names beside *it* are
 *     not held unless something above it is reserved too.
 *   - **OwnDomain** — the host has two labels. The names beside it are other
 *     domains under the same top-level domain, which are not the platform's.
 *   - **OnlyItselfListed** — the host is listed in `DNS_RESERVED_ZONES`
 *     exactly, and nothing above it is reserved. If it is the registrable
 *     domain (as `example.co.uk` is) that is complete; if it is not, the
 *     names beside it are claimable. Which of the two cannot be told here.
 *   - **Claimable** — none of the above: the names beside it can be claimed
 *     by any account.
 */
enum NamesBeside: string
{
    case Held = 'held';
    case OwnDomain = 'own_domain';
    case OnlyItselfListed = 'only_itself_listed';
    case Claimable = 'claimable';

    /** Is every name beside the host established to be refused? */
    public function held(): bool
    {
        return $this === self::Held || $this === self::OwnDomain;
    }
}
