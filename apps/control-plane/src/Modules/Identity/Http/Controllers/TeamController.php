<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Application\Actions\ChangeMemberRole;
use Lynomia\Modules\Identity\Application\Actions\InviteMember;
use Lynomia\Modules\Identity\Application\Actions\RemoveMember;
use Lynomia\Modules\Identity\Application\Actions\ResendInvitation;
use Lynomia\Modules\Identity\Application\Actions\RevokeInvitation;
use Lynomia\Modules\Identity\Application\Actions\TransferOwnership;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Domain\Exceptions\MembershipRefusedException;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Http\Requests\ChangeMemberRoleRequest;
use Lynomia\Modules\Identity\Http\Requests\InviteMemberRequest;
use Lynomia\Modules\Identity\Http\Requests\TransferOwnershipRequest;
use Lynomia\Modules\Identity\Http\Resources\TeamInvitationResource;
use Lynomia\Modules\Identity\Http\Resources\TeamMemberResource;
use Lynomia\Modules\Identity\Http\Resources\TeamRoleResource;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMailer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerMember;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Who belongs to the acting customer's account.
 *
 * **Every lookup is scoped, not checked.** Members and invitations are found
 * through a `where customer_id` on the acting account, so a member id from
 * another account is a 404 rather than a 403 — there is no row in the result
 * set to authorise against, and no id here that can be enumerated into a
 * confirmation that some other account exists.
 *
 * **Reading is a membership; writing is a permission.** Any member may see who
 * their colleagues are — a read-only member who cannot tell who has access to
 * their servers is worse off than one who can. Changing the list needs
 * `customer.members.manage`, which Owner and Administrator have and the
 * billing, technical and read-only tiers do not.
 *
 * **The mail is sent after the transaction, never inside it.** See
 * InvitationMailer.
 */
final class TeamController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $actingCustomer,
        private readonly InviteMember $invite,
        private readonly ResendInvitation $resend,
        private readonly RevokeInvitation $revoke,
        private readonly ChangeMemberRole $changeRole,
        private readonly RemoveMember $remove,
        private readonly TransferOwnership $transfer,
        private readonly InvitationMailer $mailer,
        private readonly RecordActAtomically $audited,
    ) {}

    protected function acting(): ActingCustomer
    {
        return $this->actingCustomer;
    }

    /**
     * What each role can do, from the authorization the server enforces.
     *
     * Readable by any member, and deliberately so: a person deciding whether
     * to accept an invitation, or wondering why a button is missing, is asking
     * this question. Nothing here is account data — it is the same five roles
     * for every customer on the platform — so there is nothing to scope and
     * no permission to check beyond belonging to an account.
     *
     * No query runs. The matrix is the enum, and a dashboard that asked the
     * database what a role means would be inventing a second answer.
     */
    public function roles(Request $request): JsonResponse
    {
        return response()->json([
            'data' => TeamRoleResource::collection(CustomerRole::cases()),
            'meta' => [
                'assignable_roles' => CustomerRole::assignableValues(),
            ],
        ]);
    }

    /**
     * The people in this account, owner first and then by when they joined.
     */
    public function members(Request $request): JsonResponse
    {
        $members = CustomerMember::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->with(['user', 'inviter'])
            ->orderByRaw("case when role = 'owner' then 0 else 1 end")
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => TeamMemberResource::collection($members),
            'meta' => [
                'total' => $members->count(),
                'limit' => max(1, (int) config('teams.max_members', 25)),
                'assignable_roles' => CustomerRole::assignableValues(),
            ],
        ]);
    }

    /**
     * Offers still outstanding, and the ones that were closed recently enough
     * to explain themselves.
     *
     * Spent invitations are included rather than filtered out: "I invited them
     * last week and nothing happened" is answered by seeing that the offer was
     * declined, and an empty list answers it with silence.
     */
    public function invitations(Request $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $invitations = CustomerInvitation::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->with('invitedBy')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => TeamInvitationResource::collection($invitations),
            'meta' => ['total' => $invitations->count()],
        ]);
    }

    public function invite(InviteMemberRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $user = $request->user();
        $customer = $this->actingCustomer->get();

        /*
         * Audited inside the same transaction as the invitation itself. An
         * offer of access to somebody's servers that nothing recorded is
         * exactly the kind of act an incident review comes looking for, and a
         * trail written afterwards can fail after the offer is already out.
         */
        $issued = $this->audited->execute(
            fn () => $this->invite->execute($customer, $request->email(), $request->role(), $user instanceof User ? $user : null),
            fn ($issued) => new AuditedAct(
                action: AuditAction::MemberInvited,
                subject: $issued->invitation,
                customerId: (string) $customer->getKey(),
                context: ['email' => $issued->invitation->email, 'role' => $issued->invitation->role->value],
            ),
        );

        $this->mailer->send($issued, $user instanceof User ? $user : null);

        return response()->json(
            ['data' => new TeamInvitationResource($issued->invitation->fresh(['invitedBy']))],
            201,
        );
    }

    public function resendInvitation(Request $request, string $invitation): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $issued = $this->resend->execute($this->invitationOfThisAccount($invitation));
        $user = $request->user();

        $this->mailer->send($issued, $user instanceof User ? $user : null);

        return response()->json(['data' => new TeamInvitationResource($issued->invitation->fresh(['invitedBy']))]);
    }

    public function revokeInvitation(Request $request, string $invitation): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $target = $this->invitationOfThisAccount($invitation);

        $revoked = $this->audited->execute(
            fn () => $this->revoke->execute($target),
            fn ($revoked) => new AuditedAct(
                action: AuditAction::MemberInvitationRevoked,
                subject: $revoked,
                customerId: (string) $this->actingCustomer->id(),
                context: ['email' => $revoked->email, 'role' => $revoked->role->value],
            ),
        );

        return response()->json(['data' => new TeamInvitationResource($revoked->fresh(['invitedBy']))]);
    }

    public function changeRole(ChangeMemberRoleRequest $request, string $member): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $target = $this->memberOfThisAccount($member);
        $was = $target->role;

        $changed = $this->audited->execute(
            fn () => $this->changeRole->execute($target, $request->role()),
            fn ($changed) => new AuditedAct(
                action: AuditAction::MemberRoleChanged,
                subject: $changed,
                customerId: (string) $this->actingCustomer->id(),
                context: ['from' => $was->value, 'to' => $changed->role->value],
            ),
        );

        return response()->json(['data' => new TeamMemberResource($changed->fresh(['user', 'inviter']))]);
    }

    public function removeMember(Request $request, string $member): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $target = $this->memberOfThisAccount($member);

        $this->audited->execute(
            function () use ($target): array {
                $removed = ['member_id' => (string) $target->getKey(), 'role' => $target->role->value];
                $this->remove->execute($target);

                return $removed;
            },
            fn (array $removed) => new AuditedAct(
                action: AuditAction::MemberRemoved,
                customerId: (string) $this->actingCustomer->id(),
                context: $removed,
            ),
        );

        return response()->json(null, 204);
    }

    /**
     * The one action on this surface that the owner alone may take, checked
     * inside the action against the membership rather than here against a
     * permission: `customer.members.manage` is held by administrators too, and
     * an administrator who could hand the account away could take it.
     */
    public function transferOwnership(TransferOwnershipRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'customer.members.manage');

        $customer = $this->actingCustomer->get();

        if (! hash_equals(trim($customer->display_name), $request->confirmation())) {
            throw MembershipRefusedException::becauseTheTransferWasNotConfirmed();
        }

        $successor = $this->memberOfThisAccount($request->memberId());
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw MembershipRefusedException::becauseOnlyTheOwnerMayTransfer();
        }

        $successorUser = $successor->user;

        if (! $successorUser instanceof User) {
            throw MembershipRefusedException::becauseTheyAreNotAMember();
        }

        $now = $this->audited->execute(
            fn () => $this->transfer->execute($customer, $actor, $successorUser),
            fn ($now) => new AuditedAct(
                action: AuditAction::OwnershipTransferred,
                subject: $now,
                customerId: (string) $customer->getKey(),
                context: ['from_user_id' => (string) $actor->getKey(), 'to_user_id' => (string) $now->user_id],
            ),
        );

        return response()->json(['data' => new TeamMemberResource($now->fresh(['user', 'inviter']))]);
    }

    private function memberOfThisAccount(string $id): CustomerMember
    {
        /** @var CustomerMember $member */
        $member = CustomerMember::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($id)
            ->with('user')
            ->firstOrFail();

        return $member;
    }

    private function invitationOfThisAccount(string $id): CustomerInvitation
    {
        /** @var CustomerInvitation $invitation */
        $invitation = CustomerInvitation::query()
            ->where('customer_id', $this->actingCustomer->id())
            ->whereKey($id)
            ->firstOrFail();

        return $invitation;
    }
}
