<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\DTOs\RemoteRefundResult;
use Lynomia\Modules\Payments\Domain\DTOs\WebhookVerification;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Domain\Exceptions\InvalidRefundAmountException;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Domain\Exceptions\TransactionNotRefundableException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invariant under test is a financial one: the platform can never pay out
 * more than it took in on a given transaction, no matter how the refunds are
 * split, retried, or raced.
 */
final class IssueRefundTest extends TestCase
{
    use RefreshDatabase;

    private IssueRefund $issue;

    private PaymentProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new PaymentProviderRegistry($this->app);
        $this->issue = new IssueRefund($this->registry);
    }

    #[Test]
    public function a_refund_within_the_captured_amount_is_issued(): void
    {
        Event::fake([RefundIssued::class]);
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        $refund = $this->issue->execute($transaction, Money::ofMinor(3000, 'KWD'), 'requested_by_customer');

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertTrue($refund->amount()->equals(Money::ofMinor(3000, 'KWD')));
        $this->assertNotNull($refund->provider_reference);
        $this->assertNotNull($refund->processed_at);

        Event::assertDispatched(RefundIssued::class, function (RefundIssued $event): bool {
            return $event->remainingRefundable->equals(Money::ofMinor(6000, 'KWD'))
                && ! $event->isFullRefund();
        });
    }

    #[Test]
    public function a_refund_exceeding_the_captured_amount_is_refused(): void
    {
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        try {
            $this->issue->execute($transaction, Money::ofMinor(9500, 'KWD'), 'operator error');
            $this->fail('A refund larger than the capture was issued.');
        } catch (RefundExceedsCaptureException $e) {
            $this->assertSame('payment.refund_exceeds_capture', $e->errorCode());
            $this->assertSame(9500, $e->context()['requested_minor']);
            $this->assertSame(9000, $e->context()['refundable_minor']);
        }

        // Nothing was reserved, so a correct refund is still possible.
        $this->assertSame(0, Refund::query()->count());
    }

    #[Test]
    public function partial_refunds_accumulate_and_cannot_overshoot_in_total(): void
    {
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        $this->issue->execute($transaction, Money::ofMinor(3000, 'KWD'), 'partial one');
        $this->issue->execute($transaction, Money::ofMinor(4000, 'KWD'), 'partial two');

        $transaction->refresh();
        $this->assertTrue($transaction->refundedAmount()->equals(Money::ofMinor(7000, 'KWD')));
        $this->assertTrue($transaction->refundableAmount()->equals(Money::ofMinor(2000, 'KWD')));

        // 3.000 more would take the total past the capture, even though each
        // individual refund is well under it.
        $this->expectException(RefundExceedsCaptureException::class);

        try {
            $this->issue->execute($transaction, Money::ofMinor(3000, 'KWD'), 'partial three');
        } finally {
            $this->assertSame(2, Refund::query()->count());
        }
    }

    #[Test]
    public function the_final_partial_refund_may_use_the_whole_remaining_balance(): void
    {
        Event::fake([RefundIssued::class]);
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        $this->issue->execute($transaction, Money::ofMinor(7000, 'KWD'), 'partial one');
        $this->issue->execute($transaction, Money::ofMinor(2000, 'KWD'), 'the rest');

        $this->assertTrue($transaction->refresh()->refundableAmount()->isZero());

        Event::assertDispatched(RefundIssued::class, fn (RefundIssued $event): bool => $event->isFullRefund());
    }

    #[Test]
    public function a_refund_still_in_flight_reserves_its_share_of_the_capture(): void
    {
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        // A refund the provider has accepted but not settled. The money is
        // already promised, so it must not be promised again.
        Refund::factory()->status(RefundStatus::Pending)->amount(Money::ofMinor(8000, 'KWD'))->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->expectException(RefundExceedsCaptureException::class);

        $this->issue->execute($transaction, Money::ofMinor(2000, 'KWD'), 'while the first is pending');
    }

    #[Test]
    public function a_refund_that_failed_at_the_provider_releases_its_reservation(): void
    {
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));
        $this->registry->swap('fake', $this->providerThatRefusesRefunds());

        try {
            $this->issue->execute($transaction, Money::ofMinor(9000, 'KWD'), 'first attempt');
            $this->fail('A provider failure was swallowed.');
        } catch (PaymentProviderException) {
            // Expected: the money never moved.
        }

        $this->assertSame(RefundStatus::Failed, Refund::query()->sole()->status);
        // The full capture is refundable again; a failed payout must not
        // permanently withhold funds from the customer.
        $this->assertTrue($transaction->refresh()->refundableAmount()->equals(Money::ofMinor(9000, 'KWD')));
    }

    #[Test]
    public function a_payment_that_never_settled_cannot_be_refunded(): void
    {
        $transaction = Transaction::factory()->pending()->create();

        try {
            $this->issue->execute($transaction, Money::ofMinor(1000, 'KWD'), 'too early');
            $this->fail('A pending payment was refunded.');
        } catch (TransactionNotRefundableException $e) {
            $this->assertSame('payment.transaction_not_refundable', $e->errorCode());
            $this->assertSame(TransactionStatus::Pending->value, $e->context()['status']);
        }
    }

    #[Test]
    public function a_refund_in_a_different_currency_is_refused_rather_than_converted(): void
    {
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        $this->expectException(CurrencyMismatchException::class);

        $this->issue->execute($transaction, Money::ofMinor(9000, 'USD'), 'wrong currency');
    }

    #[Test]
    public function a_non_positive_refund_is_refused(): void
    {
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        $this->expectException(InvalidRefundAmountException::class);

        $this->issue->execute($transaction, Money::zero('KWD'), 'nothing at all');
    }

    #[Test]
    public function the_operator_who_issued_a_refund_is_recorded(): void
    {
        $admin = User::factory()->create();
        $transaction = $this->capture(Money::ofMinor(9000, 'KWD'));

        $refund = $this->issue->execute(
            $transaction,
            Money::ofMinor(1000, 'KWD'),
            'goodwill',
            issuedBy: $admin,
        );

        $this->assertSame($admin->id, $refund->issued_by_user_id);
        $this->assertSame('goodwill', $refund->reason);
    }

    private function capture(Money $amount): Transaction
    {
        return Transaction::factory()->amount($amount)->create();
    }

    /**
     * A provider whose refund endpoint is down, to prove the reservation is
     * released rather than stranded.
     */
    private function providerThatRefusesRefunds(): PaymentProvider
    {
        return new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'fake';
            }

            public function supportsCurrency(string $currency): bool
            {
                return true;
            }

            public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
            {
                throw new \LogicException('not used');
            }

            public function retrievePayment(string $reference): RemotePaymentState
            {
                throw new \LogicException('not used');
            }

            public function refund(string $chargeReference, Money $amount, string $reason): RemoteRefundResult
            {
                throw PaymentProviderException::requestFailed('fake', 'refund', ['error_code' => 'api_unavailable']);
            }

            public function verifyWebhookSignature(string $rawPayload, array $headers): WebhookVerification
            {
                return WebhookVerification::failed('not used');
            }

            public function parseWebhookEvent(array $payload): ?ProviderEvent
            {
                return null;
            }
        };
    }
}
