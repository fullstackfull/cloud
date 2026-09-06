<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin / NOC API — /api/admin
|--------------------------------------------------------------------------
|
| Deliberately a separate prefix and route file from the customer API so that
| administrative capability can never be reached by a customer token that
| happens to be over-scoped: every route here requires the platform guard and
| an explicit permission.
|
*/

Route::middleware(['auth:sanctum', 'verified'])->group(function (): void {
    // Populated from Phase 1c onwards.
});
