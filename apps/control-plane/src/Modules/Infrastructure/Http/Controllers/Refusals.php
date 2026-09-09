<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SafetyRefusal;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two refusals the deployment chain answers with, shaped once.
 */
final class Refusals
{
    public static function deployment(DeploymentRefused $refusal): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'deployment_refused',
                'message' => $refusal->getMessage(),
                'details' => ['reason' => $refusal->code_],
            ],
        ], Response::HTTP_CONFLICT);
    }

    public static function safety(SafetyRefusal $refusal): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'safety_refused',
                'message' => $refusal->getMessage(),
                'details' => [
                    'classification' => $refusal->classification->value,
                    'attempted' => $refusal->attempted->value,
                    'would_permit' => $refusal->wouldPermit?->value,
                ],
            ],
        ], Response::HTTP_CONFLICT);
    }
}
