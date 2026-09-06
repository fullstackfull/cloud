<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * No coupon exists for the code the customer typed.
 *
 * The code is echoed back in its normalised form rather than verbatim, so an
 * operator reading the log sees the string the lookup actually used and not
 * the invisible whitespace that may have caused the miss.
 */
final class UnknownCouponException extends DomainException
{
    public static function forCode(string $code): self
    {
        $exception = new self(sprintf('No coupon exists with the code "%s".', $code));

        return $exception->withContext(['code' => $code]);
    }

    public function errorCode(): string
    {
        return 'coupon.unknown_code';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
