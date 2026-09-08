<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Application\Actions\AcceptInvitation;
use Lynomia\Modules\Identity\Application\Actions\DeclineInvitation;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * The invitee's side of an invitation.
 *
 * Deliberately outside the acting-customer group. Everything else a customer
 * does happens inside one account they already belong to; this is the one
 * request made by somebody who belongs to nothing yet, and requiring a
 * resolved account would make it impossible for exactly the people it is for.
 *
 * **The token is the only selector, and it is never listed.** There is no
 * endpoint that enumerates invitations by address, because that would be a way
 * to ask "is this person being invited anywhere" about somebody else. The
 * preview endpoint takes a token the caller must already hold and tells them
 * only what the mail already told them: which account, which role, and whether
 * the offer is still open.
 *
 * **Preview does not require the addresses to match; acting on it does.** A
 * person who has signed in with the wrong one of their two addresses should be
 * told that, rather than shown a 403 they cannot interpret — but they still
 * cannot accept.
 */
final class InvitationController
{
    public function __construct(
        private readonly AcceptInvitation $accept,
        private readonly DeclineInvitation $decline,
        private readonly RecordActAtomically $audited,
    ) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $invitation = $this->openInvitation($token);
        $user = $request->user();

        return response()->json([
            'data' => [
                'account' => $invitation->customer?->display_name,
                'role' => $invitation->role->value,
                'invited_by' => $invitation->invitedBy?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                /*
                 * Whether the signed-in person is the one invited. The address
                 * itself is not returned: the invitee already knows it, and
                 * somebody holding a forwarded token would otherwise learn who
                 * it was meant for.
                 */
                'is_for_you' => $user instanceof User
                    && hash_equals($invitation->email, mb_strtolower((string) $user->email)),
            ],
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw MembershipRefusedException::becauseTheOfferIsNotOpen();
        }

        /*
         * Somebody gaining access to an account is recorded against the
         * account they joined, not against themselves: the people who need to
         * find this later are the account's owner and whoever is reading the
         * trail after an incident.
         */
        $member = $this->audited->execute(
            fn () => $this->accept->execute($token, $user),
            fn ($member) => new AuditedAct(
                action: AuditAction::MemberJoined,
                subject: $member,
                customerId: (string) $member->customer_id,
                context: ['user_id' => (string) $member->user_id, 'role' => $member->role->value],
            ),
        );

        return response()->json([
            'data' => [
                'customer_id' => (string) $member->customer_id,
                'role' => $member->role->value,
            ],
        ], 201);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw MembershipRefusedException::becauseTheOfferIsNotOpen();
        }

        $this->decline->execute($token, $user);

        return response()->json(null, 204);
    }

    /**
     * A token that names nothing and a token that names a spent offer get the
     * same answer, so that a wrong guess cannot be told from a stale link.
     */
    private function openInvitation(string $token): CustomerInvitation
    {
        /** @var CustomerInvitation|null $invitation */
        $invitation = CustomerInvitation::forToken($token)->with(['customer', 'invitedBy'])->first();

        if ($invitation === null || ! $invitation->isOpen()) {
            throw MembershipRefusedException::becauseTheOfferIsNotOpen();
        }

        return $invitation;
    }
}
