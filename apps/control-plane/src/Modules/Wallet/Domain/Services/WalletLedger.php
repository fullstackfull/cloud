<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Domain\Services;

use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Exceptions\IdempotencyKeyConflictException;
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
        /*
         * The scan of the ledger and the read of the cached balance have to be
         * one consistent observation of the wallet. Taken separately, a top-up
         * that commits between them is counted by the cache and missed by the
         * scan, and reconcile() reports drift on a wallet that is perfectly
         * consistent — a false ledger-corruption alarm, which is worse than no
         * alarm because it teaches an operator to ignore the real one.
         *
         * The wallet row is therefore taken with the same lock the writers
         * take, so no entry can appear underneath the scan. It holds a write
         * lock for the length of one wallet's ledger read, which is an indexed
         * scan of that wallet's entries; a sweep does one wallet at a time.
         */
        return $wallet->getConnection()->transaction(function () use ($wallet): WalletReconciliation {
            /** @var Wallet $locked */
            $locked = $wallet->newQuery()->lockForUpdate()->findOrFail($wallet->getKey());

            $running = 0;
            $divergent = [];

            /*
             * created_at has one-second resolution, so it cannot order entries
             * written in the same second on its own. ULIDs are lexicographically
             * ordered by generation time, which breaks those ties in insertion
             * order.
             */
            $entries = $locked->transactions()
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
                walletId: (string) $locked->getKey(),
                // Read from the locked row rather than re-queried, so the cache
                // and the ledger below it are the same observation.
                cached: $locked->balance(),
                derived: Money::ofMinor($running, $locked->currency),
                divergentEntryIds: $divergent,
            );
        });
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
                    // lost on the way back — but only once the original is
                    // confirmed to be this call and not a different one that
                    // happens to share the key.
                    $this->assertReplayMatches($locked, $replay, $idempotencyKey, $kind, $signedAmount);

                    return $this->withWallet($replay, $locked);
                }
            }

            $balanceAfter = $locked->balance()->plus($signedAmount);

            /*
             * Only a debit can breach the floor. A credit onto a balance that
             * is already negative — the compensating adjustment that repairs
             * exactly that situation — moves it towards zero, so refusing it
             * would leave a broken wallet with no way back.
             */
            if ($signedAmount->isNegative() && $balanceAfter->isNegative()) {
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

    /**
     * The entry a key already posted, if any.
     *
     * Public because a caller that writes something *else* alongside a ledger
     * entry — a wallet-funded invoice payment writes a charge row too — has to
     * be able to tell a first attempt from a repeat before it writes that
     * other thing. Without it, a repeated request would find the debit already
     * posted (correctly, once) and still leave a second charge row behind
     * attached to nothing, which reads to the rest of billing as money that
     * arrived and was never applied.
     *
     * The caller must already hold whatever lock makes the answer stable; this
     * takes none of its own.
     */
    public function entryPostedUnder(Wallet $wallet, string $key): ?WalletTransaction
    {
        return $this->findByIdempotencyKey($wallet, $key);
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
     * A replay may only be answered with an entry that is the same posting.
     *
     * Matching on the key alone is not enough: the same key attached to a
     * different kind or amount means two different calls collided on one key,
     * and handing back the first one reports work as done that was never done.
     */
    private function assertReplayMatches(
        Wallet $wallet,
        WalletTransaction $replay,
        string $idempotencyKey,
        WalletTransactionKind $kind,
        Money $signedAmount,
    ): void {
        if ($replay->kind === $kind && $replay->amount_minor === $signedAmount->minorUnits()) {
            return;
        }

        throw IdempotencyKeyConflictException::forEntry(
            (string) $wallet->getKey(),
            $idempotencyKey,
            (string) $replay->getKey(),
            $kind,
            $signedAmount->minorUnits(),
        );
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

        /*
         * The metadata document is caller-controlled and the replay check reads
         * one reserved name out of it, so that name is stripped unconditionally
         * before the real key is written. Without this a caller could attach
         * `idempotency_key` to any entry and pre-empt a later genuine top-up
         * carrying that key: the top-up would be answered with the planted
         * entry and the customer's money would never reach their balance.
         */
        unset($redacted[WalletTransaction::IDEMPOTENCY_METADATA_KEY]);

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
        // Money normalises its currency code to upper case, so the wallet's
        // side is normalised too: a row written in another case is still that
        // wallet's own currency, and reporting a mismatch against itself would
        // make the wallet permanently unusable rather than merely untidy.
        if (strtoupper($wallet->currency) !== $amount->currency()) {
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
