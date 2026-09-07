<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentMethod;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SecretFixtures;
use Tests\TestCase;

final class PaymentModelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_transaction_amount_comes_back_as_money_in_its_own_currency(): void
    {
        $transaction = Transaction::factory()->amount(Money::ofMinor(12345, 'KWD'))->create();

        $amount = $transaction->refresh()->amount();

        $this->assertTrue($amount->equals(Money::ofMinor(12345, 'KWD')));
        // KWD has three decimal places; a float round trip would not survive.
        $this->assertSame('12.345', $amount->toDecimalString());
    }

    #[Test]
    public function provider_metadata_is_stored_with_its_secrets_redacted(): void
    {
        $transaction = Transaction::factory()->create([
            'provider_metadata' => [
                'object' => [
                    'id' => 'pi_test_1',
                    'client_secret' => 'pi_test_1_secret_abcdefghijklmno',
                    'note' => 'retried with '.SecretFixtures::STRIPE_SECRET_KEY,
                ],
                'attempt' => 2,
            ],
        ]);

        // Read straight from the column: the redaction has to have happened on
        // the way in, not on the way out, or the secret is still on disk.
        $raw = (string) DB::table('transactions')->where('id', $transaction->id)->value('provider_metadata');

        $this->assertStringNotContainsString('pi_test_1_secret_abcdefghijklmno', $raw);
        $this->assertStringNotContainsString(SecretFixtures::STRIPE_SECRET_KEY, $raw);

        $stored = $transaction->refresh()->provider_metadata;
        $this->assertSame(SecretRedactor::PLACEHOLDER, $stored['object']['client_secret']);
        // Non-secret detail survives, or the record would be useless.
        $this->assertSame('pi_test_1', $stored['object']['id']);
        $this->assertSame(2, $stored['attempt']);
    }

    #[Test]
    public function a_refunds_provider_metadata_is_redacted_too(): void
    {
        $refund = Refund::factory()->create([
            'provider_metadata' => ['api_key' => SecretFixtures::STRIPE_RESTRICTED_KEY, 'reason' => 'duplicate'],
        ]);

        $this->assertSame(SecretRedactor::PLACEHOLDER, $refund->refresh()->provider_metadata['api_key']);
        $this->assertSame('duplicate', $refund->provider_metadata['reason']);
    }

    #[Test]
    public function a_stored_webhook_payload_is_redacted(): void
    {
        $event = WebhookEvent::factory()->create([
            'payload' => ['id' => 'evt_1', 'data' => ['client_secret' => 'pi_1_secret_zzz']],
        ]);

        $this->assertSame(SecretRedactor::PLACEHOLDER, $event->refresh()->payload['data']['client_secret']);
        $this->assertSame('evt_1', $event->payload['id']);
    }

    #[Test]
    public function refunded_and_refundable_amounts_ignore_refunds_that_released_their_claim(): void
    {
        $transaction = Transaction::factory()->amount(Money::ofMinor(9000, 'KWD'))->create();

        Refund::factory()->amount(Money::ofMinor(2000, 'KWD'))->status(RefundStatus::Succeeded)->create([
            'transaction_id' => $transaction->id,
        ]);
        Refund::factory()->amount(Money::ofMinor(1000, 'KWD'))->status(RefundStatus::Pending)->create([
            'transaction_id' => $transaction->id,
        ]);
        // A refund the provider refused returns nothing, so it holds nothing.
        Refund::factory()->amount(Money::ofMinor(5000, 'KWD'))->status(RefundStatus::Failed)->create([
            'transaction_id' => $transaction->id,
        ]);

        $this->assertTrue($transaction->refundedAmount()->equals(Money::ofMinor(3000, 'KWD')));
        $this->assertTrue($transaction->refundableAmount()->equals(Money::ofMinor(6000, 'KWD')));
    }

    #[Test]
    public function a_card_is_expired_only_after_the_last_day_of_its_expiry_month(): void
    {
        $current = PaymentMethod::factory()->create([
            'expiry_month' => (int) now()->month,
            'expiry_year' => (int) now()->year,
        ]);

        $this->assertFalse($current->isExpired());
        $this->assertTrue(PaymentMethod::factory()->expired()->create()->isExpired());
    }

    #[Test]
    public function a_card_is_labelled_by_brand_and_last_four_only(): void
    {
        $method = PaymentMethod::factory()->create(['brand' => 'visa', 'last_four' => '4242']);

        $this->assertSame('visa ····4242', $method->label());
        $this->assertSame('Knet', PaymentMethod::factory()->knet()->create()->label());
    }

    #[Test]
    public function a_failed_attempt_becomes_due_once_its_retry_time_passes(): void
    {
        $attempt = PaymentAttempt::factory()->failed()->create();

        $this->assertSame(PaymentAttemptStatus::Failed, $attempt->status);
        $this->assertFalse($attempt->isDue());

        $this->travel(2)->days();
        $this->assertTrue($attempt->isDue());
    }

    #[Test]
    public function a_webhook_event_only_counts_as_settled_once_it_has_an_outcome(): void
    {
        $this->assertFalse(WebhookEvent::factory()->create()->isSettled());
        $this->assertFalse(WebhookEvent::factory()->failed()->create()->isSettled());
        $this->assertTrue(WebhookEvent::factory()->processed()->create()->isSettled());
        $this->assertTrue(WebhookEventStatus::Ignored->isSettled());
    }
}
