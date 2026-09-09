<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Enums;

/**
 * The three things an answer to a readiness question can be.
 *
 * `not_applicable` exists so that "infrastructure available?" for a product
 * whose providers all live on somebody else's servers is not a misleading
 * yes, and so a screen can grey the cell rather than tick it.
 */
enum ReadinessAnswer: string
{
    case Yes = 'yes';
    case No = 'no';
    case NotApplicable = 'not_applicable';

    public static function of(bool $answer): self
    {
        return $answer ? self::Yes : self::No;
    }
}
