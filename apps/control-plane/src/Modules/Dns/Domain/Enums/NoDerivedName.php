<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * Why one of the platform's own addresses gave the reservation no name.
 *
 * Kept as a reason rather than a null because what is left unheld depends on
 * it, and no one sentence covers every case: "nothing there an account could
 * claim" is true of an address and false of a host with no scheme in front of
 * it, whose name any account can claim. Each case says only what is true of
 * every value that lands in it:
 *
 *   - **Unset** and **NotAUrl** — no host was read, so nothing is held.
 *     What the operator meant it to name may be anybody's to claim.
 *   - **IpAddress** — a dotted-quad IPv4 address, or a bracketed IPv6 one. No
 *     zone at, above or beneath either is a name: every such zone ends in a
 *     numeric label or carries a bracket or a colon, and DomainName refuses
 *     all three. Nothing is lost.
 *   - **SingleLabel** — refused as a zone for having one label, and there is
 *     nothing above it. Nothing beneath it is held for it; where the label is
 *     well formed, as `localhost` is, the names beneath it are names, and an
 *     account can claim them.
 *   - **NotADomainName** — more than one label, and refused by the name rules
 *     — after conversion to the ASCII form DNS carries, where it is written in
 *     Unicode and converts. Nothing at, above or beneath it is held for it.
 *     Whether the host itself could be claimed is not said: a Unicode host
 *     that did not convert here may convert elsewhere, into a name nobody
 *     here has checked.
 *
 * The sentences are the preflight's. They never carry the value.
 */
enum NoDerivedName: string
{
    case Unset = 'unset';
    case NotAUrl = 'not_a_url';
    case IpAddress = 'ip_address';
    case SingleLabel = 'single_label';
    case NotADomainName = 'not_a_domain_name';

    /**
     * Why, and what that leaves unheld, as the rest of a sentence that begins
     * "APP_URL contributed no name: ".
     */
    public function reason(): string
    {
        return match ($this) {
            self::Unset => 'it is not set, so it holds nothing',
            self::NotAUrl => 'no host could be read from it — a URL needs a scheme, such as https://, in front of the '
                .'host — so it holds nothing',
            self::IpAddress => 'its host is an IP address, and no zone at, above or beneath an address can be claimed, '
                .'so there is nothing for it to hold',
            self::SingleLabel => 'its host is a single label, which cannot be claimed as a zone and has nothing above '
                .'it; it holds none of the names beneath it',
            self::NotADomainName => 'its host is not a name the zone rules accept, so it holds nothing: not the host, '
                .'and nothing above or beneath it',
        };
    }
}
