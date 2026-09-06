<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Modules\Identity\Application\Actions\RegisterCustomer;
use Lynomia\Modules\Identity\Http\Requests\RegisterRequest;

final class RegistrationController
{
    public function __invoke(RegisterRequest $request, RegisterCustomer $register): JsonResponse
    {
        /** @var array{name: string, email: string, password: string} $attributes */
        $attributes = $request->validated();

        $register->execute($attributes);

        /*
         * One response, whatever happened.
         *
         * The endpoint used to answer 201 with the new user and customer, and
         * 422 when the address was taken. That difference is a membership
         * oracle: one request per address tells an attacker which of a list of
         * people hold accounts here, which is the input to credential stuffing
         * and to phishing that names the right provider. Returning the created
         * account would be the same leak by another route, since the only way
         * to answer differently is to have behaved differently.
         *
         * So nothing identifying comes back, and the account id arrives the
         * ordinary way: verify the address, sign in, read /me. The customer
         * loses nothing they can act on in the meantime — the next step is to
         * open their inbox either way — and the person whose address was
         * already registered gets told, which is the one disclosure that is
         * theirs to receive.
         *
         * 202 rather than 201: the request has been accepted, and whether it
         * created anything is deliberately not stated.
         *
         * Registration also does not sign anyone in. Verification comes first,
         * so an address typo cannot leave an unreachable account holding a live
         * session, and ordering has one consistent gate.
         */
        return response()->json([
            'data' => [
                'message' => 'If that address can be registered, a verification link is on its way to it.',
            ],
            'meta' => [
                'email_verification_required' => true,
            ],
        ], 202);
    }
}
