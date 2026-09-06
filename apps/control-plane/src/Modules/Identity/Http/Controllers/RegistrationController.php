<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Modules\Identity\Application\Actions\RegisterCustomer;
use Lynomia\Modules\Identity\Http\Requests\RegisterRequest;
use Lynomia\Modules\Identity\Http\Resources\CustomerResource;
use Lynomia\Modules\Identity\Http\Resources\UserResource;

final class RegistrationController
{
    public function __invoke(RegisterRequest $request, RegisterCustomer $register): JsonResponse
    {
        /** @var array{name: string, email: string, password: string} $attributes */
        $attributes = $request->validated();

        ['user' => $user, 'customer' => $customer] = $register->execute($attributes);

        /*
         * Registration does not sign the user in. Email verification comes
         * first, so that an address typo cannot leave an unreachable account
         * holding an active session — and so that ordering, which requires a
         * verified address, has one consistent gate.
         */
        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'customer' => new CustomerResource($customer),
            ],
            'meta' => [
                'email_verification_required' => true,
            ],
        ], 201);
    }
}
