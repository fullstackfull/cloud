<?php

declare(strict_types=1);

namespace Lynomia\Modules\Audit\Application\DTOs;

use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;

/**
 * What to write down about an act, decided once the act has happened.
 *
 * The description has to come after the work rather than before it: the
 * subject of a suspension is the row as it now reads, and the context of an
 * adoption includes what the platform ended up believing. Building the
 * description from the result is what lets the record and the act share a
 * transaction without the record having to guess.
 */
final readonly class AuditedAct
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public AuditAction $action,
        public ?Model $subject = null,
        public ?string $customerId = null,
        public array $context = [],
    ) {}
}
