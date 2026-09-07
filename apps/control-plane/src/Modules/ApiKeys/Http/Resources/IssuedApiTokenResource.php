<?php

declare(strict_types=1);

namespace Lynomia\Modules\ApiKeys\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\NewAccessToken;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;

/**
 * The one and only response that contains a token's plaintext.
 *
 * There is no second endpoint that returns it and no column it could be read
 * back from — the table holds a SHA-256 digest — so a customer who loses this
 * body issues a new token. That is the point: a credential the platform can
 * show twice is a credential the platform can leak twice.
 *
 * `meta.plaintext_shown_once` is not decoration. It is the flag a client uses
 * to decide to make the value copyable and to warn before navigating away, and
 * an integration reading the field will store the token rather than expecting
 * to fetch it again from the list endpoint.
 *
 * @property NewAccessToken $resource
 */
final class IssuedApiTokenResource extends JsonResource
{
    public function __construct(NewAccessToken $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PersonalAccessToken $token */
        $token = $this->resource->accessToken;

        return array_merge(
            (new ApiTokenResource($token))->toArray($request),
            [
                // `id|plaintext`, the whole value that goes in the
                // Authorization header. Split it and it stops authenticating.
                'token' => $this->resource->plainTextToken,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            'meta' => [
                'plaintext_shown_once' => true,
            ],
        ];
    }
}
