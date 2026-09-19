<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Domains\Http\Controllers\DomainController;
use Lynomia\Modules\Domains\Http\Controllers\DomainSearchController;

/*
 * domains — customer surface.
 *
 * Inside the business group, so authenticated, verified, throttled and
 * resolved to one account before anything here runs.
 *
 * Searching and quoting both take `service.view`. Neither spends money: a
 * quote is a price the platform commits to, not an order, and the permission
 * that matters is the one on the order that redeems it.
 *
 * The third argument on the limiters below is not decoration. A bare
 * `throttle:N,1` keys on the caller alone — every unprefixed numeric limiter
 * in the application then shares one counter per user — so a customer trying
 * names in the search box would spend the allowance for everything else they
 * can do, and get a 429 for a reason no client can explain.
 */
Route::prefix('domains')->as('domains.')->group(function (): void {
    /*
     * A name is addressed by the name itself as well as by its id.
     *
     * `/domains/example.com` is what a customer sends a colleague and reads
     * back to support; a ULID is neither. Both forms resolve through the same
     * `where` on the acting customer, so a name from another account 404s
     * exactly as an unknown id does.
     *
     * The pattern accepts what a hostname is made of and nothing else: no
     * slash, so a path cannot be smuggled through the parameter, and no
     * leading or trailing separator. The literal segments above — search,
     * quotes, transfers — are declared first and therefore win.
     */
    $identity = '[A-Za-z0-9](?:[A-Za-z0-9.\\-]{0,253}[A-Za-z0-9])?';

    /*
     * Tighter than the shared ceiling, and tighter than most write endpoints,
     * because a search is the one read on this platform that costs a third
     * party something: every uncached name is a registrar call against an
     * allowance the whole platform shares.
     */
    Route::get('search', [DomainSearchController::class, 'search'])
        ->middleware('throttle:30,1,domain-search:')
        ->name('search');

    Route::post('quotes', [DomainSearchController::class, 'quote'])
        ->middleware('throttle:20,1,domain-quote:')
        ->name('quotes.store');

    Route::get('/', [DomainController::class, 'index'])->name('index');

    /*
     * Buying a name. Tighter than the shared ceiling because each one issues
     * an invoice and, once paid, spends a registry fee that cannot be given
     * back — a loop here is a loop of real money.
     */
    Route::post('/', [DomainController::class, 'store'])
        ->middleware('throttle:10,1,domain-order:')
        ->name('store');

    /*
     * Transferring a name in. Not nested under a domain id, because the whole
     * point is that this platform does not hold it yet.
     */
    Route::post('transfers', [DomainController::class, 'transfer'])
        ->middleware('throttle:10,1,domain-order:')
        ->name('transfers.store');

    Route::get('{domain}', [DomainController::class, 'show'])
        ->where('domain', $identity)
        ->name('show');

    Route::post('{domain}/renewals', [DomainController::class, 'renew'])
        ->where('domain', $identity)
        ->middleware('throttle:10,1,domain-order:')
        ->name('renewals.store');

    Route::post('{domain}/redemptions', [DomainController::class, 'redeem'])
        ->where('domain', $identity)
        ->middleware('throttle:10,1,domain-order:')
        ->name('redemptions.store');

    Route::put('{domain}/nameservers', [DomainController::class, 'setNameservers'])
        ->where('domain', $identity)
        ->middleware('throttle:30,1,domain-manage:')
        ->name('nameservers.update');

    /*
     * Reading the registrant back. `service.manage` in the controller, because
     * it is a person's home address — see DomainContactResource.
     */
    Route::get('{domain}/contacts', [DomainController::class, 'contacts'])
        ->where('domain', $identity)
        ->middleware('throttle:30,1,domain-manage:')
        ->name('contacts.show');

    Route::put('{domain}/contacts', [DomainController::class, 'updateContacts'])
        ->where('domain', $identity)
        ->middleware('throttle:30,1,domain-manage:')
        ->name('contacts.update');

    /*
     * Auto-renew: whether the platform raises the next invoice before this
     * name lapses. A setting rather than an act, so it takes no idempotency
     * key; it shares the management limiter with the nameservers and the lock.
     */
    Route::put('{domain}/auto-renew', [DomainController::class, 'setAutoRenew'])
        ->where('domain', $identity)
        ->middleware('throttle:30,1,domain-manage:')
        ->name('auto_renew.update');

    Route::put('{domain}/transfer-lock', [DomainController::class, 'setLock'])
        ->where('domain', $identity)
        ->middleware('throttle:30,1,domain-manage:')
        ->name('transfer_lock.update');

    /*
     * A POST because it is an act, not a read: most registrars regenerate the
     * code when asked, and a GET would be repeated by a browser prefetch. Its
     * own tight limiter, because a stolen session pulling auth codes for every
     * name on an account is the shape of a domain theft.
     */
    Route::post('{domain}/authorisation-code', [DomainController::class, 'authorisationCode'])
        ->where('domain', $identity)
        ->middleware('throttle:5,1,domain-auth-code:')
        ->name('authorisation_code.store');
});
