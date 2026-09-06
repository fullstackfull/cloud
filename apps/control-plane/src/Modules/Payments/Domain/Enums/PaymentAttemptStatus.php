<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Enums;

enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
