<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Wallet\Application\Actions\PayInvoiceFromWallet;

/*
 * One wallet payment, in its own operating system process.
 *
 * Run by WalletRaceTest, which starts several of these at the same instant
 * against one balance. It exists as a script rather than as a closure because
 * the property being tested — that a row lock serialises two transactions —
 * cannot be tested inside one PHP process, which can only ever do one thing at
 * a time. A "concurrency test" that ran both halves in one process would be
 * proving that a program cannot race itself.
 *
 * Arguments: customer id, invoice id, idempotency key, and a unix timestamp
 * with microseconds to start at. The last is the barrier: every process sleeps
 * until the same instant, so they contend rather than queue politely behind
 * each other.
 *
 * Prints one line of JSON: what happened, for the test to read.
 */

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$customerId, $invoiceId, $key, $startAt] = array_slice($argv, 1);

$startAt = (float) $startAt;
$now = microtime(true);

if ($startAt > $now) {
    usleep((int) (($startAt - $now) * 1_000_000));
}

$customer = Customer::query()->findOrFail($customerId);
$invoice = Invoice::query()->findOrFail($invoiceId);

try {
    $settlement = $app->make(PayInvoiceFromWallet::class)
        ->execute($customer, $invoice, $key);

    echo json_encode([
        'outcome' => 'settled',
        'applied_minor' => $settlement->applied->minorUnits(),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'outcome' => 'refused',
        'error' => $e::class,
        'message' => $e->getMessage(),
    ], JSON_THROW_ON_ERROR), PHP_EOL;
}
