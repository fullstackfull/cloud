<?php

declare(strict_types=1);

namespace Lynomia\Modules\Rbac\Application\DTOs;

use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * What InviteOperator did: the operator, and whether the address already had
 * a login that was promoted — and so had every credential it held taken away
 * — rather than a new one created.
 */
final readonly class InvitedOperator
{
    public function __construct(
        public User $operator,
        public bool $promotedAnExistingLogin,
    ) {}
}
