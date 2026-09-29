<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\DTOs\RemoteRefundResult;
use Lynomia\Modules\Payments\Domain\DTOs\WebhookVerification;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use RuntimeException;

/**
 * The controlled provider, with something happening on the platform while a
 * refund call is out at the provider - the window between IssueRefund's
 * reservation and the provider's answer - and then an answer of the test's
 * choosing: refused at once (`failed`) or no answer at all (the call throws).
 *
 * Everything but refund() is the controlled provider's own behaviour.
 */
final class ProviderThatWithdrawsTheInvoiceMidRefund implements PaymentProvider
{
    private FakePaymentProvider $inner;

    /**
     * @param  Closure(): void  $duringTheCall
     */
    public function __construct(
        private readonly Closure $duringTheCall,
        private readonly bool $throws,
    ) {
        $this->inner = new FakePaymentProvider;
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function supportsCurrency(string $currency): bool
    {
        return $this->inner->supportsCurrency($currency);
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        return $this->inner->createPaymentIntent($request);
    }

    public function retrievePayment(string $reference): RemotePaymentState
    {
        return $this->inner->retrievePayment($reference);
    }

    public function refund(string $chargeReference, Money $amount, string $reason, string $idempotencyKey): RemoteRefundResult
    {
        ($this->duringTheCall)();

        if ($this->throws) {
            throw new RuntimeException('The provider did not answer.');
        }

        return new RemoteRefundResult(
            reference: 'fake_re_refused_'.substr(hash('sha256', $idempotencyKey), 0, 12),
            status: RefundStatus::Failed,
            amount: $amount,
            failureReason: 'refused by design',
        );
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): WebhookVerification
    {
        return $this->inner->verifyWebhookSignature($rawPayload, $headers);
    }

    public function parseWebhookEvent(array $payload): ?ProviderEvent
    {
        return $this->inner->parseWebhookEvent($payload);
    }
}
