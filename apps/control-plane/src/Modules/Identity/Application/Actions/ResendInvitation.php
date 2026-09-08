<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Application\DTOs\IssuedInvitation;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;

/**
 * Sends an open offer again.
 *
 * **The token is replaced, and it has to be.** The platform stores a hash, not
 * the token, so it cannot put the original link into a second mail — there is
 * nothing to put. Keeping a copy so that a resend could repeat it would mean
 * storing a working credential for every open invitation, which is the thing
 * the hashing exists to avoid. So a resend mints a new token and the previous
 * link stops working: one live link per offer, always the most recent one.
 *
 * The expiry is pushed out with it, because the reason to resend is that the
 * clock has been running with nobody reading. The row records that it
 * happened; six resends to an address that never answers is worth being able
 * to see.
 */
final readonly class ResendInvitation
{
    public function execute(CustomerInvitation $invitation): IssuedInvitation
    {
        $token = bin2hex(random_bytes(32));

        return DB::transaction(function () use ($invitation, $token): IssuedInvitation {
            /** @var CustomerInvitation $locked */
            $locked = CustomerInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw MembershipRefusedException::becauseTheOfferIsNotOpen();
            }

            $locked->forceFill([
                'token_hash' => CustomerInvitation::hashOf($token),
                'expires_at' => CarbonImmutable::now()->addDays(max(1, (int) config('teams.invitation_ttl_days', 14))),
                'sent_count' => $locked->sent_count + 1,
                'last_sent_at' => CarbonImmutable::now(),
            ])->save();

            return new IssuedInvitation($locked, $token);
        });
    }
}
