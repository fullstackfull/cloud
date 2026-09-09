<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Http\Controllers\CountryCurrencyChangeController;

/*
|--------------------------------------------------------------------------
| The account itself
|--------------------------------------------------------------------------
|
| What the whole account is billed in. A request, not a setting: the
| platform analyses what the change would touch, an operator decides, and
| the account changes only once nothing already priced in the old currency
| is still live. Nothing already written is ever converted.
|
*/

Route::prefix('account/country-currency-changes')->as('account.country_currency_changes.')->group(function (): void {
    Route::get('/', [CountryCurrencyChangeController::class, 'index'])->name('index');

    Route::post('/', [CountryCurrencyChangeController::class, 'store'])
        ->middleware('throttle:5,1,country-currency-change:')
        ->name('store');

    Route::post('{change}/reanalyse', [CountryCurrencyChangeController::class, 'reanalyse'])
        ->middleware('throttle:10,1,country-currency-reanalyse:')
        ->name('reanalyse');

    Route::post('{change}/withdraw', [CountryCurrencyChangeController::class, 'withdraw'])->name('withdraw');
});
