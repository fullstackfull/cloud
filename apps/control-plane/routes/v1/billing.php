<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Billing\Http\Controllers\InvoiceController;
use Lynomia\Modules\Billing\Http\Controllers\SubscriptionController;

/*
 * billing — customer surface.
 *
 * Included by routes/api_v1.php inside the group that has already applied
 * auth:sanctum, verified, throttle:api and customer. Do not re-declare those
 * here; do declare anything narrower that this module needs.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately not here
 * ---------------------------------------------------------------------------
 *
 * No route accepts a customer id, in the path, the query string or the body.
 * The account is resolved once by the `customer` middleware from the
 * authenticated principal, and `{invoice}` and `{subscription}` are resolved
 * *through* that account — hence plain string parameters rather than implicit
 * model binding, which would fetch the row globally before anyone could scope
 * it, and would 403-or-404 on an id the caller was never allowed to name.
 *
 * **No invoice PDF.** The platform does not render an invoice document, so
 * there is no GET /invoices/{invoice}/pdf. A route that returned an empty file
 * would be worse than a missing one: a client would ship a download button
 * that hands customers a broken file, and nobody would find out until an
 * accountant asked for one.
 *
 * **No customer route edits an invoice.** No PATCH, no refund, and no void of
 * an invoice as such. An invoice is frozen once issued, and every figure on it
 * is moved by the settlement, void and refund actions on the platform's own
 * side. Two POSTs on an invoice here go through those actions:
 * POST {invoice}/wallet-credit pays the invoice from the customer's wallet
 * (PayInvoiceFromWallet) - a settlement, through the same action as any
 * other, never a write to what the invoice says it bought; and
 * POST {invoice}/withdraw-plan-change withdraws the unpaid plan change the
 * invoice bills (WithdrawAnUnpaidPlanChange): what it holds goes back to the
 * wallet, the platform's void withdraws it and puts the subscription back on
 * the plan it came from - what a renewal's lapse does, now without waiting
 * for the renewal. Refused (409 `invoice.plan_change_not_withdrawable`) for
 * any other invoice. It exists because a change that can no longer be
 * delivered cannot be paid, and its open invoice held every other change of
 * plan until the renewal (N2 / X7-2). (The other two POSTs in this file are
 * on a subscription: cancel and plan, below.) Paying by card is the Payments
 * module's surface.
 *
 * **No renewal route.** RenewSubscription is the worker's entry point and
 * refuses anything the due-for-renewal scope excludes.
 *
 * ---------------------------------------------------------------------------
 * Changing plan
 * ---------------------------------------------------------------------------
 *
 * GET /subscriptions/{subscription}/plan-options prices every plan the
 * subscription could move to, refused ones with their reasons, and POST
 * /subscriptions/{subscription}/plan makes the move. The POST goes through
 * ApplyPlanChange, which re-quotes under the subscription's lock and settles
 * the proration as a purchase: an upgrade leaves an invoice and the machine
 * is resized only when it is paid; a downgrade credits the wallet, never with
 * more than the period collected. A change is refused while an invoice for
 * the subscription is open, onto a plan that is sold out or at the account's
 * limit, and onto a plan the change could not be delivered onto (a hosting
 * plan with no single package on sale, say: `not_deliverable`, asked again
 * when its invoice is paid). It takes the plan and the price and nothing else:
 * the unit count is the one the subscription holds, as the quote priced it.
 * The Idempotency-Key it requires names no provisioning job; the change's own
 * record does.
 *
 * ---------------------------------------------------------------------------
 * Cancellation
 * ---------------------------------------------------------------------------
 *
 * POST /subscriptions/{subscription}/cancel takes an optional `immediately`
 * flag. Absent or false schedules the end for the period the customer has
 * already paid for; true ends it now.
 *
 * The immediate form additionally requires `confirm_subscription_id`, which
 * must repeat the id already in the path. It is the one irreversible thing on
 * this surface — the state machine has no edge back out of cancelled, the
 * service stops the same second and the paid remainder is not returned — and a
 * boolean is not a confirmation for something irreversible: it is a field a
 * generated client sets in its constructor, a convenience wrapper defaults and
 * a retry loop resends without a person ever seeing it. This is the same rule
 * the VPS and dedicated reinstall routes apply with confirm_hostname and
 * confirm_serial. The scheduled form is reversible and asks for nothing extra,
 * so clients are not trained to send the confirmation always.
 *
 * No idempotency key: a cancellation neither spends money nor provisions
 * hardware. The scheduled form converges on repeat, keeping the first date it
 * recorded; a repeat of the immediate form is answered 409
 * `subscription.already_ended` rather than a second 200 for an operation that
 * did nothing.
 */

Route::prefix('invoices')->as('invoices.')->group(function (): void {
    Route::get('/', [InvoiceController::class, 'index'])->name('index');
    Route::get('{invoice}', [InvoiceController::class, 'show'])->name('show');

    /*
     * Paying from stored credit.
     *
     * Here rather than on the wallet surface for two reasons that agree: what
     * the POST answers with is an invoice, and a module's HTTP layer is not
     * another module's to reach into — a rule the architecture test enforces.
     *
     * The GET is a quote and takes nothing. The POST requires an
     * Idempotency-Key, because a repeated submission that debited twice would
     * spend a balance the customer only has once, and carries a tighter
     * limiter than the shared ceiling: it spends the wallet directly, with no
     * payment provider in the way. (It is not the only route here that moves
     * money without one - a plan change credits the wallet on a downgrade and
     * issues an invoice on an upgrade, under its own limiter below.)
     */
    Route::get('{invoice}/wallet-credit', [InvoiceController::class, 'walletCreditQuote'])
        ->whereUlid('invoice')
        ->name('wallet_credit.quote');

    Route::post('{invoice}/wallet-credit', [InvoiceController::class, 'payFromWalletCredit'])
        ->whereUlid('invoice')
        ->middleware('throttle:30,1,wallet-credit:')
        ->name('wallet_credit.pay');

    /*
     * Withdrawing the unpaid plan change an invoice bills. Limited as a plan
     * change is: it moves the subscription back and can return money to the
     * wallet. Safe to repeat - a second call finds nothing open to withdraw.
     */
    Route::post('{invoice}/withdraw-plan-change', [InvoiceController::class, 'withdrawPlanChange'])
        ->whereUlid('invoice')
        ->middleware('throttle:10,1,plan-change-withdraw:')
        ->name('plan_change.withdraw');
});

Route::prefix('subscriptions')->as('subscriptions.')->group(function (): void {
    Route::get('/', [SubscriptionController::class, 'index'])->name('index');
    Route::get('{subscription}', [SubscriptionController::class, 'show'])->name('show');

    /*
     * A tighter limiter than the shared `throttle:api` ceiling the group
     * already applies. Cancelling is safe to repeat, but it is also the one
     * destructive thing on this surface, and a flood of distinct subscription
     * ids — the shape of a stolen session being used to tear an account down —
     * should be expensive.
     */
    Route::post('{subscription}/cancel', [SubscriptionController::class, 'cancel'])
        ->middleware('throttle:30,1,subscription-cancel:')
        ->name('cancel');

    /*
     * Changing plan mid-cycle. Limited harder than cancelling: each change
     * settles money both ways. The limit is not what stops a client flapping
     * between two plans for credit - ten a minute would still mint - the
     * open-invoice refusal and the period's credit ceiling are (F-01).
     */
    Route::post('{subscription}/plan', [SubscriptionController::class, 'changePlan'])
        ->middleware('throttle:10,1,plan-change:')
        ->name('plan');

    /*
     * What the plans on offer would cost this subscription.
     *
     * A read, and priced by the backend. The portal renders these numbers and
     * computes none of them: proration, currency and rounding are the
     * platform's rules, and a second implementation of them in TypeScript
     * would agree until it did not.
     */
    Route::get('{subscription}/plan-options', [SubscriptionController::class, 'planOptions'])
        ->name('plan_options');
});
