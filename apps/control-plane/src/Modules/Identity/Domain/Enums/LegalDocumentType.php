<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

/**
 * The documents a customer must accept before an account exists.
 *
 * Both are required. There is no "recommended" tier here on purpose: a
 * document worth showing a customer at registration is a document worth
 * being bound by, and one that could be skipped would be advertising.
 *
 * The case name is what is stored against an acceptance, so it is short,
 * stable and not a title — titles get rewritten and a stored acceptance has
 * to keep meaning the same thing years later.
 */
enum LegalDocumentType: string
{
    case Terms = 'terms';

    case AcceptableUse = 'aup';

    /**
     * Where the published document lives, as a configuration key.
     */
    public function urlKey(): string
    {
        return match ($this) {
            self::Terms => 'legal.terms_url',
            self::AcceptableUse => 'legal.aup_url',
        };
    }

    /**
     * Which revision of it is currently published.
     */
    public function versionKey(): string
    {
        return match ($this) {
            self::Terms => 'legal.terms_version',
            self::AcceptableUse => 'legal.aup_version',
        };
    }
}
