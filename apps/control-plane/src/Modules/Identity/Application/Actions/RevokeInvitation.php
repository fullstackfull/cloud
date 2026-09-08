<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;

/**
 * Withdraws an offer before it is taken up.
 *
 * The lock is what makes it a revocation rather than a race: without it, an
 * acceptance already inside its own transaction would commit a membership
 * after the offer was marked withdrawn, and the account would gain exactly the
 * member somebody had just decided to keep out.
 */
final readonly class RevokeInvitation
{
    public function execute(CustomerInvitation $invitation): CustomerInvitation
    {
        return DB::transaction(function () use ($invitation): CustomerInvitation {
            /** @var CustomerInvitation $locked */
            $locked = CustomerInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw MembershipRefusedException::becauseTheOfferIsNotOpen();
            }

            $locked->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

            return $locked;
        });
    }
}
