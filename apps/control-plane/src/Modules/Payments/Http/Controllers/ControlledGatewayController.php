<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\AuthorisesWithinAccount;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Payments\Application\Actions\IngestWebhookEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\ControlledGatewayUnavailableException;
use Lynomia\Modules\Payments\Domain\Services\FakeProviderGuard;
use Lynomia\Modules\Payments\Http\Requests\ConfirmControlledPaymentRequest;
use Lynomia\Modules\Payments\Http\Resources\PaymentResource;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderManager;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Queries\CustomerTransactions;

/**
 * The fake provider's own payment page, and the two buttons on it.
 *
 * This is a test and development facility, and it is the honest way to prove
 * a payment journey without a merchant account. A real gateway hosts a page,
 * the customer authorises or abandons the payment there, and the gateway then
 * tells the platform what happened over a signed server-to-server callback.
 * Every one of those steps happens here for real:
 *
 *  - the browser is redirected to a page it can see and click;
 *  - the decision is recorded on the provider side, not in our database;
 *  - the outcome reaches the platform as a genuinely signed webhook through
 *    the same IngestWebhookEvent the real providers use, signature check,
 *    idempotency key and all;
 *  - the invoice is settled by that webhook and by nothing else.
 *
 * What it therefore does NOT do is mark anything paid. There is no path from
 * these endpoints to an invoice status that does not go through the webhook,
 * which is the property the whole payment module is built around: a browser's
 * word about money is a claim, never evidence.
 *
 * Three guards keep it out of production: the fake provider refuses to be
 * constructed there at all, `assertNotProduction` is called on every request
 * here, and every method refuses unless the fake is the *selected* provider —
 * so an environment running Stripe cannot be talked into approving its own
 * payments even if these routes were somehow reachable.
 *
 * Authentication is required and scoping is by the acting customer, so this
 * cannot be used to authorise somebody else's payment.
 */
final class ControlledGatewayController
{
    use AuthorisesWithinAccount;

    public function __construct(
        private readonly ActingCustomer $acting,
        private readonly PaymentProviderManager $providers,
        private readonly IngestWebhookEvent $ingest,
    ) {}

    /**
     * What the gateway page shows: the amount, and which invoice it is for.
     *
     * The figures come from the platform's own record of the pending payment
     * rather than from the reference in the URL, so a customer who edits the
     * amount in the address bar sees the amount they actually owe.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $this->assertAvailable($request);

        $payment = $this->scopedPayment($request, $reference);

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($payment),
                'provider' => $payment->provider,
                'reference' => $payment->provider_reference,
            ],
            'meta' => [
                'is_controlled_gateway' => true,
                'settlement' => 'Approving here makes the provider send the platform a signed webhook. The invoice is settled by that webhook and by nothing this browser says.',
            ],
        ]);
    }

    /**
     * Authorises the payment, the way a customer would on a hosted page.
     */
    public function approve(Request $request, string $reference): JsonResponse
    {
        return $this->decide($request, $reference, RemotePaymentStatus::Succeeded);
    }

    /**
     * Refuses it, the way a bank would.
     */
    public function decline(Request $request, string $reference): JsonResponse
    {
        return $this->decide($request, $reference, RemotePaymentStatus::Failed, 'card_declined');
    }

    /**
     * The other shape: confirmation from the client, with the credential the
     * intent issued.
     *
     * The credential is checked, and that check is the point. A browser that
     * does not hold the client secret for this intent cannot confirm it, which
     * is exactly the property a real client-side SDK relies on.
     */
    public function confirm(ConfirmControlledPaymentRequest $request, string $reference): JsonResponse
    {
        $this->assertAvailable($request);

        $payment = $this->scopedPayment($request, $reference);
        $attempt = PaymentAttempt::query()
            ->where('transaction_id', $payment->getKey())
            ->orderByDesc('attempt_number')
            ->firstOrFail();

        $expected = $this->fake()->clientSecretFor($reference, (string) $attempt->getKey());

        if (! hash_equals($expected, $request->clientSecret())) {
            throw ControlledGatewayUnavailableException::becauseTheClientCredentialDoesNotMatch();
        }

        return $this->decide($request, $reference, RemotePaymentStatus::Succeeded);
    }

    protected function acting(): ActingCustomer
    {
        return $this->acting;
    }

    private function decide(
        Request $request,
        string $reference,
        RemotePaymentStatus $status,
        ?string $failureCode = null,
    ): JsonResponse {
        $this->assertAvailable($request);

        $payment = $this->scopedPayment($request, $reference);
        $fake = $this->fake();

        // Recorded on the provider's side of the boundary first, so that a
        // later server-side retrieve — the reconciliation run, say — agrees
        // with the decision the customer just took.
        $fake->record($reference, $status, $failureCode);

        $signed = $fake->emitWebhook(
            kind: $status === RemotePaymentStatus::Succeeded
                ? ProviderEventKind::PaymentSucceeded
                : ProviderEventKind::PaymentFailed,
            reference: $reference,
            // The platform's own record of what this payment is for. Nothing
            // about the amount comes from the request.
            amount: $payment->amount(),
            metadata: [
                'customer_id' => (string) $payment->customer_id,
                'invoice_id' => (string) $payment->invoice_id,
            ],
            failureCode: $failureCode,
        );

        /*
         * Delivered through the ordinary ingestion path: the signature is
         * verified, the event id is claimed for idempotency, and the capture
         * or the failure is recorded by the same actions a real provider's
         * callback reaches. Called in-process rather than over HTTP only
         * because a test runner has no route back to itself; the code that
         * runs is the same code.
         */
        $result = $this->ingest->execute($fake->name(), $signed->rawPayload, $signed->headers);

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($payment->refresh()),
                // Flat rather than nested, so the derivation that finds
                // customer-facing field names in this module does not read
                // a response key as a validated field.
                'webhook_status' => $result->event->status->value,
                'webhook_was_duplicate' => $result->duplicate,
            ],
        ]);
    }

    /**
     * The pending payment this reference belongs to, scoped to the caller.
     *
     * Fetched through the acting customer's own relation, so another tenant's
     * reference matches no row and the request 404s rather than 403s.
     */
    private function scopedPayment(Request $request, string $reference): Transaction
    {
        $this->authoriseWithinAccount($request, 'billing.pay');

        return CustomerTransactions::of($this->acting->get())
            ->where('provider_reference', $reference)
            ->firstOrFail();
    }

    private function fake(): FakePaymentProvider
    {
        $provider = $this->providers->default();

        if (! $provider instanceof FakePaymentProvider) {
            throw ControlledGatewayUnavailableException::becauseARealProviderIsConfigured();
        }

        return $provider;
    }

    private function assertAvailable(Request $request): void
    {
        FakeProviderGuard::assertNotProduction(FakePaymentProvider::NAME);

        if ($this->providers->defaultName() !== FakePaymentProvider::NAME) {
            throw ControlledGatewayUnavailableException::becauseARealProviderIsConfigured();
        }
    }
}
