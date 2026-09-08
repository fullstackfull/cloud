<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Turns an offer down.
 *
 * Held to the same address check as acceptance. Declining is not harmless —
 * it closes the offer and frees the partial index, so a stranger who could
 * decline could stop a colleague from ever joining by declining every
 * invitation as it arrived.
 */
final readonly class DeclineInvitation
{
    public function execute(string $token, User $user): CustomerInvitation
    {
        return DB::transaction(function () use ($token, $user): CustomerInvitation {
            /** @var CustomerInvitation|null $invitation */
            $invitation = CustomerInvitation::forToken($token)->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isOpen()) {
                throw MembershipRefusedException::becauseTheOfferIsNotOpen();
            }

            if (! hash_equals($invitation->email, mb_strtolower((string) $user->email))) {
                throw MembershipRefusedException::becauseTheOfferWasMadeToSomebodyElse();
            }

            $invitation->forceFill(['declined_at' => CarbonImmutable::now()])->save();

            return $invitation;
        });
    }
}
