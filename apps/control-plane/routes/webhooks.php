<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider webhooks
|--------------------------------------------------------------------------
|
| Unauthenticated in the session sense and authenticated by provider signature
| instead. No CSRF, no session, no cookies. A service is never provisioned
| because a browser landed on a success URL — only because a signed webhook was
| verified server-side.
|
*/

// Populated in Phase 2 (payments).
