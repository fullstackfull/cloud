<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Http\Controllers;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Http\Concerns\ConfirmsCurrentPassword;
use Lynomia\Modules\ApiKeys\Application\Actions\IssueApiToken;
use Lynomia\Modules\ApiKeys\Application\Actions\RevokeApiToken;
use Lynomia\Modules\ApiKeys\Application\Queries\CustomerApiTokens;
use Lynomia\Modules\ApiKeys\Domain\Enums\ApiTokenStatus;
use Lynomia\Modules\ApiKeys\Http\Requests\IssueApiTokenRequest;
use Lynomia\Modules\ApiKeys\Http\Requests\ListApiTokensRequest;
use Lynomia\Modules\ApiKeys\Http\Requests\RevokeApiTokenRequest;
use Lynomia\Modules\ApiKeys\Http\Resources\ApiTokenResource;
use Lynomia\Modules\ApiKeys\Http\Resources\IssuedApiTokenResource;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * A customer's own API tokens.
 *
 * Four rules hold across every method here.
 *
 * **Two scopes, not one.** Every row this controller touches is fetched through
 * CustomerApiTokens, which hangs off the authenticated user *and* the acting
 * customer. A colleague's token is not a row these queries can return, and
 * neither is one bound to the caller's other account — so a user who
 * administers two accounts never sees one account's credentials while acting
 * for the other, and another tenant's id 404s rather than 403s.
 *
 * **The plaintext exists once.** `store` returns it; nothing else can, because
 * the table stores a digest. There is deliberately no "reveal" route.
 *
 * **Revoking is not deleting.** `destroy` records a reason and keeps the row,
 * so "which token did this?" survives the credential. The token stops
 * authenticating immediately: ApiTokenServiceProvider refuses any token that is
 * not `isUsable()`.
 *
 * **Minting takes the account password.** A token outlives the session that
 * created it and does not appear in the browser's session list, so a borrowed
 * session must not be enough to mint one. The confirmation goes through
 * the shared ConfirmsCurrentPassword, so a wrong password here counts towards
 * the same lockout as a failed sign-in and appears in the login history —
 * without that, guessing at this endpoint is the one attack that leaves no
 * trace anywhere.
 *
 * **Turning one off is never harder than making one.** `apikey.manage` gates
 * `store` and only `store`: minting a credential that automates against the
 * account is an administrative act. Listing and revoking are not, because both
 * are already scoped to the caller's *own* tokens — a member cannot see or kill
 * a colleague's key whatever their role, so the permission adds no protection
 * there and does real damage. Gating them stranded the demotion case: an owner
 * mints a token, is demoted to member, and their live credential becomes
 * unrevokable by anybody at all — not by them (403) and not by an
 * administrator, since the query hangs off the token owner's login. A
 * credential the platform cannot switch off is a worse outcome than a member
 * reading a list of their own keys.
 *
 * No transaction lives in this class: issuing is an action, so a future
 * provisioning job or admin path mints tokens the same way, under the same
 * ceiling.
 */
final class ApiTokenController
{
    use AuthorisesWithinAccount, ConfirmsCurrentPassword;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly IssueApiToken $issue,
        private readonly RevokeApiToken $revoke,
    ) {}

    /**
     * The caller's tokens for the acting account, newest first.
     */
    public function index(ListApiTokensRequest $request): JsonResponse
    {
        // No apikey.manage here. The list is the caller's own tokens on the
        // acting account and nothing else, and it is what a demoted member
        // needs in order to find the id of a credential they have to revoke.
        $status = $request->status();

        /** @var LengthAwarePaginator<int, PersonalAccessToken> $tokens */
        $tokens = CustomerApiTokens::for($this->currentUser($request), $this->acting->get())
            ->when($status !== null, fn ($query) => $this->constrainToStatus($query, $status))
            // The ULID tie-breaks tokens created in the same millisecond, so
            // paging is stable and a row cannot appear on two pages.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage());

        return response()->json([
            'data' => ApiTokenResource::collection($tokens->getCollection()),
            'meta' => [
                'page' => $tokens->currentPage(),
                'per_page' => $tokens->perPage(),
                'total' => $tokens->total(),
                'last_page' => $tokens->lastPage(),
                'max_per_page' => ListApiTokensRequest::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * Mints a token and returns its plaintext, once.
     *
     * The only method that requires `apikey.manage`, and the only response in
     * the API whose body is a live bearer credential. It must never be
     * cacheable; nothing is set here because the SecurityHeaders middleware
     * already sends `no-store, private` on every `api/*` response, and a second
     * copy of that header in one controller would read as the guarantee living
     * here rather than there.
     */
    public function store(IssueApiTokenRequest $request): JsonResponse
    {
        $this->authoriseWithinAccount($request, 'apikey.manage');

        $user = $this->currentUser($request);
        $this->confirmCurrentPassword($request, $user, $request->currentPassword());

        $issued = $this->issue->execute(
            user: $user,
            customer: $this->acting->get(),
            name: $request->tokenName(),
            expiresAt: $request->expiresAt(),
            allowedIpRanges: $request->allowedIpRanges(),
            rateLimitPerMinute: $request->rateLimitPerMinute(),
        );

        return (new IssuedApiTokenResource($issued))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Revokes one token.
     *
     * Answers 200 with the revoked row rather than 204, because the row is the
     * point: the response shows the customer exactly what was recorded, and
     * that the token is still listed rather than gone.
     *
     * Repeating the call is safe and keeps the first reason — see
     * RevokeApiToken. A caller may revoke the very token they are
     * authenticating with; that is a deliberate self-logout, and the next
     * request with it is refused at authentication.
     */
    public function destroy(RevokeApiTokenRequest $request, string $token): JsonResponse
    {
        /*
         * No apikey.manage here either, deliberately. Revocation is the safe
         * direction: the only rows reachable are the caller's own on the acting
         * account, so the worst a member can do is switch off a credential that
         * authenticates as them. Requiring the permission meant a token minted
         * by an owner who was later demoted could not be revoked by anyone,
         * which is the one state this endpoint exists to prevent.
         */
        $user = $this->currentUser($request);

        $found = CustomerApiTokens::for($user, $this->acting->get())
            ->whereKey($token)
            ->firstOrFail();

        $this->revoke->execute($found, $request->reason($user));

        return (new ApiTokenResource($found))->response();
    }

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 401);

        return $user;
    }

    /**
     * Status is derived from two columns, so filtering on it is a predicate
     * rather than a `where`. It mirrors ApiTokenStatus::of exactly — including
     * that revocation wins over expiry — because a list filtered by "revoked"
     * that disagrees with the `status` printed on each row is worse than no
     * filter at all.
     */
    private function constrainToStatus(Builder $query, ApiTokenStatus $status): Builder
    {
        return match ($status) {
            ApiTokenStatus::Revoked => $query->whereNotNull('revoked_at'),

            ApiTokenStatus::Expired => $query
                ->whereNull('revoked_at')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()),

            ApiTokenStatus::Active => $query
                ->whereNull('revoked_at')
                ->where(fn ($nested) => $nested
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())),
        };
    }
}
