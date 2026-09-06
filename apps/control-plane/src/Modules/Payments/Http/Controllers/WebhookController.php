<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Payments\Application\Actions\IngestWebhookEvent;
use Lynomia\Modules\Payments\Domain\Exceptions\MalformedWebhookPayloadException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnknownPaymentProviderException;
use Lynomia\Modules\Payments\Domain\Exceptions\WebhookSignatureException;

/**
 * The public endpoint a payment provider posts to.
 *
 * Three response decisions here are about how providers behave rather than
 * about this application:
 *
 *  - **A rejected signature returns 400, not 401 or 403.** Providers retry on
 *    5xx and on some 4xx codes; a forged payload must be told plainly that it
 *    is malformed so nothing retries it, and a genuine misconfiguration shows
 *    up immediately in the provider's delivery log rather than as a slow
 *    trickle of retries.
 *
 *  - **A successfully ingested event returns 200 even when the platform chose
 *    to ignore it.** An event type this platform does not act on is still
 *    delivered successfully; answering anything else makes the provider retry
 *    an event that will never be actionable.
 *
 *  - **An unexpected failure is allowed to become a 500.** That is the one
 *    case where a retry is exactly what should happen: the event was genuine,
 *    the platform could not process it, and the provider's redelivery is the
 *    recovery mechanism. Ingestion is idempotent, so the retry is safe.
 */
final class WebhookController
{
    public function __invoke(Request $request, IngestWebhookEvent $ingest, string $provider): JsonResponse
    {
        try {
            $result = $ingest->execute(
                $provider,
                // The raw body, not the parsed one. Re-encoding a decoded
                // payload changes key order and whitespace, and the signature
                // is over the bytes the provider sent.
                $request->getContent(),
                $this->headers($request),
            );
        } catch (UnknownPaymentProviderException $e) {
            return response()->json(['error' => ['code' => $e->errorCode(), 'message' => 'Unknown provider.']], 404);
        } catch (WebhookSignatureException $e) {
            /*
             * Logged as a warning with the provider and the reason, and
             * nothing from the body. A failing signature is either an attack
             * or a misconfigured endpoint secret, and both need to be visible
             * without the payload that would make the log itself a liability.
             */
            Log::warning('Rejected a webhook with an invalid signature.', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
                'source_ip' => $request->ip(),
            ]);

            return response()->json(['error' => ['code' => $e->errorCode(), 'message' => 'Invalid signature.']], 400);
        } catch (MalformedWebhookPayloadException $e) {
            return response()->json(['error' => ['code' => $e->errorCode(), 'message' => 'Malformed payload.']], 400);
        }

        return response()->json([
            'data' => [
                'status' => $result->event->status->value,
                'duplicate' => $result->duplicate,
                'acted_on' => $result->wasActedOn(),
            ],
        ]);
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function headers(Request $request): array
    {
        /** @var array<string, list<string|null>> $all */
        $all = $request->headers->all();

        $normalised = [];
        foreach ($all as $name => $values) {
            $filtered = array_values(array_filter($values, static fn (?string $v): bool => $v !== null));
            $normalised[$name] = count($filtered) === 1 ? $filtered[0] : $filtered;
        }

        return $normalised;
    }
}
