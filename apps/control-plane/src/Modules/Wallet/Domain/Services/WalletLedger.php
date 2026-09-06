<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Services;

use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Exceptions\InsufficientWalletBalanceException;
use Lynomia\Modules\Wallet\Domain\Exceptions\UnattributedAdjustmentException;
use Lynomia\Modules\Wallet\Domain\ValueObjects\WalletReconciliation;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * The only place a wallet balance changes.
 *
 * Three properties hold here and nowhere else, which is why nothing outside
 * this class may write wallets.balance_minor or wallet_transactions:
 *
 *  1. **The ledger is the truth and the balance is a cache.** Every mutation
 *     appends a signed entry and stamps the resulting balance onto it, so the
 *     balance can always be re-derived — see recomputeBalance() — and any
 *     disagreement can be reported rather than papered over — see reconcile().
 *
 *  2. **Reads that decide a write happen under a row lock.** Two debits that
 *     each read a balance of 10 KWD and each spend 7 would leave the customer
 *     4 KWD in the red with no product behind it. The wallet row is taken with
 *     lockForUpdate() inside the transaction, so the second debit waits and
 *     then sees the first one's balance.
 *
 *  3. **A refused debit writes nothing.** The check happens after the lock and
 *     before the insert, inside the transaction, so a rejection cannot leave a
 *     ledger entry or a moved balance behind.
 */
final class WalletLedger
{
    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * The wallet a customer holds in a currency, opening one if they have none.
     *
     * Currencies are never converted: a customer transacting in two currencies
     * holds two independent balances.
     */
    public function walletFor(Customer $customer, string $currency): Wallet
    {
        return Wallet::query()->firstOrCreate(
            ['customer_id' => $customer->getKey(), 'currency' => strtoupper($currency)],
            ['balance_minor' => 0],
        );
    }

    /**
     * The cached balance, re-read from the database so a stale in-memory model
     * cannot answer this question.
     */
    public function balance(Wallet $wallet): Money
    {
        return Money::ofMinor($this->cachedBalanceMinor($wallet), $wallet->currency);
    }

    /**
     * @param  Money  $amount  a positive amount; the sign is this method's job, not the caller's
     * @param  array<string, mixed>  $metadata
     *
     * @throws CurrencyMismatchException
     * @throws UnattributedAdjustmentException
     */
    public function credit(
        Wallet $wallet,
        Money $amount,
        WalletTransactionKind $kind,
        string $description,
        ?User $actor = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $invoiceId = null,
        ?string $transactionId = null,
    ): WalletTransaction {
        return $this->post(
            $wallet,
            $this->assertPositive($amount, 'credit'),
            $kind,
            $description,
            $actor,
            $metadata,
            $idempotencyKey,
            $invoiceId,
            $transactionId,
        );
    }

    /**
     * @param  Money  $amount  a positive amount to take out of the wallet
     * @param  array<string, mixed>  $metadata
     *
     * @throws InsufficientWalletBalanceException
     * @throws CurrencyMismatchException
     * @throws UnattributedAdjustmentException
     */
    public function debit(
        Wallet $wallet,
        Money $amount,
        WalletTransactionKind $kind,
        string $description,
        ?User $actor = null,
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?string $invoiceId = null,
        ?string $transactionId = null,
    ): WalletTransaction {
        return $this->post(
            $wallet,
            $this->assertPositive($amount, 'debit')->negated(),
            $kind,
            $description,
            $actor,
            $metadata,
            $idempotencyKey,
            $invoiceId,
            $transactionId,
        );
    }

    /**
     * Re-derives the balance from the ledger, ignoring the cached column.
     *
     * This is the figure a customer would arrive at by adding up their own
     * statement, and it is what any dispute is settled against.
     */
    public function recomputeBalance(Wallet $wallet): Money
    {
        return Money::ofMinor(
            (int) $wallet->transactions()->sum('amount_minor'),
            $wallet->currency,
        );
    }

    /**
     * Compares the cache against the ledger and reports what it finds.
     *
     * Two independent things can be wrong and both are checked: the cached
     * balance can disagree with the sum of the entries, and an individual
     * entry's balance_after_minor can disagree with the running total up to
     * it. The second catches a ledger that was tampered with in the middle,
     * where the endpoints still happen to agree.
     *
     * Nothing is corrected here — see WalletReconciliation.
     */
    public function reconcile(Wallet $wallet): WalletReconciliation
    {
        $running = 0;
        $divergent = [];

        /*
         * created_at has one-second resolution, so it cannot order entries
         * written in the same second on its own. ULIDs are lexicographically
         * ordered by generation time, which breaks those ties in insertion
         * order.
         */
        $entries = $wallet->transactions()
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        foreach ($entries as $entry) {
            $running += $entry->amount_minor;

            if ($entry->balance_after_minor !== $running) {
                $divergent[] = $entry->id;
            }
        }

        return new WalletReconciliation(
            walletId: (string) $wallet->getKey(),
            cached: Money::ofMinor($this->cachedBalanceMinor($wallet), $wallet->currency),
            derived: Money::ofMinor($running, $wallet->currency),
            divergentEntryIds: $divergent,
        );
    }

    /**
     * @param  Money  $signedAmount  positive credits the customer, negative debits
     * @param  array<string, mixed>  $metadata
     */
    private function post(
        Wallet $wallet,
        Money $signedAmount,
        WalletTransactionKind $kind,
        string $description,
        ?User $actor,
        array $metadata,
        ?string $idempotencyKey,
        ?string $invoiceId,
        ?string $transactionId,
    ): WalletTransaction {
        $this->assertSameCurrency($wallet, $signedAmount);
        $description = $this->assertDescribed($description);

        if ($kind->requiresActor() && $actor === null) {
            throw UnattributedAdjustmentException::forWallet((string) $wallet->getKey());
        }

        $attributes = $this->buildMetadata($metadata, $idempotencyKey);

        /*
         * The transaction is opened on the wallet's own connection rather than
         * through the DB facade, so a wallet read from a secondary connection
         * is locked and written on the connection that actually holds it —
         * a lock taken on a different connection protects nothing.
         */
        return $wallet->getConnection()->transaction(function () use (
            $wallet,
            $signedAmount,
            $kind,
            $description,
            $actor,
            $attributes,
            $idempotencyKey,
            $invoiceId,
            $transactionId,
        ): WalletTransaction {
            /** @var Wallet $locked */
            $locked = $wallet->newQuery()->lockForUpdate()->findOrFail($wallet->getKey());

            if ($idempotencyKey !== null) {
                $replay = $this->findByIdempotencyKey($locked, $idempotencyKey);

                if ($replay !== null) {
                    // A retried top-up must not credit twice. The original
                    // entry is returned unchanged, so the caller sees the same
                    // answer it would have seen had the first call not been
                    // lost on the way back.
                    return $this->withWallet($replay, $locked);
                }
            }

            $balanceAfter = $locked->balance()->plus($signedAmount);

            if ($balanceAfter->isNegative()) {
                // Thrown inside the transaction and before any insert, so the
                // refusal leaves neither an entry nor a moved balance.
                throw InsufficientWalletBalanceException::forDebit(
                    (string) $locked->getKey(),
                    $locked->balance(),
                    $signedAmount->absolute(),
                );
            }

            /** @var WalletTransaction $entry */
            $entry = $locked->transactions()->create([
                'amount_minor' => $signedAmount->minorUnits(),
                'balance_after_minor' => $balanceAfter->minorUnits(),
                'kind' => $kind,
                'description' => $description,
                'invoice_id' => $invoiceId,
                'transaction_id' => $transactionId,
                'created_by_user_id' => $actor?->getKey(),
                'metadata' => $attributes === [] ? null : $attributes,
                'created_at' => now(),
            ]);

            $locked->balance_minor = $balanceAfter->minorUnits();
            $locked->save();

            // The caller's instance would otherwise keep answering balance()
            // with the pre-mutation figure.
            $wallet->balance_minor = $balanceAfter->minorUnits();
            $wallet->syncOriginalAttribute('balance_minor');

            return $this->withWallet($entry, $locked);
        });
    }

    private function findByIdempotencyKey(Wallet $wallet, string $key): ?WalletTransaction
    {
        /** @var WalletTransaction|null $entry */
        $entry = $wallet->transactions()
            ->where('metadata->'.WalletTransaction::IDEMPOTENCY_METADATA_KEY, $key)
            ->first();

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildMetadata(array $metadata, ?string $idempotencyKey): array
    {
        // Metadata is free-form and frequently a slice of a provider response,
        // so it reaches storage through the redactor: a stored payment token
        // is a leak whether it was logged or persisted.
        $redacted = $this->redactor->redact($metadata);

        if ($idempotencyKey !== null) {
            // Written after redaction, and last, so caller-supplied metadata
            // can neither forge nor overwrite the key the replay check reads.
            $redacted[WalletTransaction::IDEMPOTENCY_METADATA_KEY] = $idempotencyKey;
        }

        return $redacted;
    }

    private function withWallet(WalletTransaction $entry, Wallet $wallet): WalletTransaction
    {
        // Entries carry no currency of their own, so an entry handed back to a
        // caller arrives with the wallet it belongs to already attached.
        return $entry->setRelation('wallet', $wallet);
    }

    private function cachedBalanceMinor(Wallet $wallet): int
    {
        return (int) $wallet->newQuery()->whereKey($wallet->getKey())->value('balance_minor');
    }

    private function assertSameCurrency(Wallet $wallet, Money $amount): void
    {
        if ($wallet->currency !== $amount->currency()) {
            throw CurrencyMismatchException::between($wallet->currency, $amount->currency());
        }
    }

    private function assertPositive(Money $amount, string $operation): Money
    {
        if (! $amount->isPositive()) {
            throw new \InvalidArgumentException(
                sprintf('A wallet %s must be a positive amount, got %s.', $operation, $amount)
            );
        }

        return $amount;
    }

    private function assertDescribed(string $description): string
    {
        $description = trim($description);

        // Every line has to be explainable to the customer whose balance it
        // moved, so an empty description is refused rather than defaulted.
        if ($description === '') {
            throw new \InvalidArgumentException('A wallet entry must carry a human description.');
        }

        if (mb_strlen($description) > 255) {
            throw new \InvalidArgumentException('A wallet entry description may not exceed 255 characters.');
        }

        return $description;
    }
}
