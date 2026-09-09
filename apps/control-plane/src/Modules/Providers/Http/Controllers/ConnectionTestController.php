<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SafetyRefusal;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Application\Actions\AssessProvider;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\Exceptions\NoSuchTester;
use Lynomia\Modules\Providers\Http\Resources\ConnectionTestResource;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Symfony\Component\HttpFoundation\Response;

/**
 * Can we reach it, and what will it let us do?
 *
 * ---------------------------------------------------------------------------
 * Why this lives in Providers and takes a server
 * ---------------------------------------------------------------------------
 *
 * Because connecting to things is what this module is for. A connection test
 * against a hypervisor and one against a registrar account differ only in
 * which tester runs; putting the server variant in the Infrastructure module
 * would mean two controllers, two response shapes for one idea, and — as
 * LayeringTest pointed out — a module reaching into another module's HTTP
 * layer to render the result.
 *
 * Infrastructure owns the machine. Providers owns the act of reaching it.
 */
final class ConnectionTestController
{
    public function forServer(Request $request, string $server, TestConnection $test): JsonResponse
    {
        $found = ManagedServer::query()->findOrFail($server);

        $driver = $request->string('driver', 'fake')->value();

        try {
            $result = $test->forServer($found, $driver, $request->user());
        } catch (SafetyRefusal $refused) {
            /*
             * 409, not 403. The operator's permissions were fine; the machine
             * is not one anybody has agreed we may open a socket to. Those are
             * different problems with different fixes, and collapsing them
             * sends somebody to the RBAC screen when they should be talking to
             * whoever owns the hardware.
             */
            return $this->safetyRefused($refused);
        } catch (NoSuchTester $unknown) {
            return response()->json([
                'error' => ['code' => 'unknown_driver', 'message' => $unknown->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => (new ConnectionTestResource($result))->toArray($request)]);
    }

    /**
     * Ask a provider account whether it answers, and what it can do.
     *
     * The reassessment afterwards is the part that makes this more than a
     * diagnostic. A test that succeeds moves a provider from "configured" to
     * "proven", and if the readiness column did not move with it an operator
     * would see a successful test beside a provider still reported as not
     * ready — and would reasonably conclude the test meant nothing.
     *
     * It happens after the test's own transaction rather than inside it: the
     * test result is a fact worth keeping even if computing a display column
     * fails.
     */
    public function forProvider(
        Request $request,
        string $provider,
        TestConnection $test,
        AssessProvider $assess,
    ): JsonResponse {
        $found = ProviderInstance::query()
            ->with(['credential', 'licence', 'server', 'capabilities'])
            ->findOrFail($provider);

        try {
            $result = $test->forProvider($found, $request->user());
        } catch (SafetyRefusal $refused) {
            // Reachable for the categories that run on a machine of ours: the
            // test goes to the machine, and the machine's classification
            // decides. Same shape as the server variant, because it is the
            // same refusal.
            return $this->safetyRefused($refused);
        } catch (NoSuchTester $unknown) {
            /*
             * The common case in this build rather than an exotic one: most
             * catalogued drivers have an adapter and no connection tester yet.
             * The catalogue says so up front through `testable`, and this is
             * what happens if something asks anyway.
             */
            return response()->json([
                'error' => ['code' => 'unknown_driver', 'message' => $unknown->getMessage()],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $assess->execute($found->refresh()->load(['credential', 'licence', 'server', 'capabilities']));

        return response()->json(['data' => (new ConnectionTestResource($result))->toArray($request)]);
    }

    private function safetyRefused(SafetyRefusal $refused): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'safety_refused',
                'message' => $refused->getMessage(),
                'details' => [
                    'classification' => $refused->classification->value,
                    'attempted' => $refused->attempted->value,
                    'would_permit' => $refused->wouldPermit?->value,
                ],
            ],
        ], Response::HTTP_CONFLICT);
    }
}
