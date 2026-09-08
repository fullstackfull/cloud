<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\DTOs;

use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;

/**
 * An invitation and, once only, the token that redeems it.
 *
 * The token exists in memory on exactly this object and in the mail that is
 * built from it. It is not returned to the inviter: an invitation is proof that
 * the invitee read their own mail, and handing the inviter a working link would
 * let an account be joined by somebody who never did.
 *
 * @immutable
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public CustomerInvitation $invitation,
        public string $token,
    ) {}
}
