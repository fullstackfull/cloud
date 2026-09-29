<?php

declare(strict_types=1);

/*
 * One money-path action, in its own process.
 *
 * Run by ARefundAndASettlementDoNotDeadlockTest, for the reason
 * Billing/plan_change_racer.php exists: two statements on one connection are
 * serialised by definition, so a lock-order cycle between two actions cannot
 * be shown from one process.
 *
 * Arguments: the action ("refund" or "settle"), the capture's id, the
 * invoice's id, and for a refund the amount in minor units. Writes one line
 * of JSON: whether the action completed, and the class and SQLSTATE of what
 * it threw when it did not.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$action, $captureId, $invoiceId] = [$argv[1], $argv[2], $argv[3]];

/*
 * The refund's booking onto the invoice is a queued listener in production
 * (RecordRefundAgainstTheInvoice); run inline here it would race the
 * settlement for reasons that have nothing to do with lock order. Held back,
 * so what is measured is the two actions' own locks.
 */
Event::fake([RefundIssued::class]);

$capture = Transaction::query()->findOrFail($captureId);
$invoice = Invoice::query()->findOrFail($invoiceId);

$outcome = ['completed' => false, 'error' => null, 'sqlstate' => null];

try {
    if ($action === 'refund') {
        $app->make(IssueRefund::class)->execute($capture, Money::ofMinor((int) $argv[4], $capture->currency), 'raced refund');
    } else {
        $app->make(SettleInvoice::class)->execute($invoice, $capture);
    }

    $outcome['completed'] = true;
} catch (QueryException $e) {
    $outcome = ['completed' => false, 'error' => $e::class, 'sqlstate' => $e->getCode()];
} catch (Throwable $e) {
    $outcome = ['completed' => false, 'error' => $e::class.': '.$e->getMessage(), 'sqlstate' => null];
}

echo json_encode($outcome, JSON_THROW_ON_ERROR), PHP_EOL;
