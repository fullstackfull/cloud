<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every login address and every team invitation's address rewritten, again,
 * to the one spelling (LoginAddress) — which changed after 2026_05_04_000120
 * and 2026_05_04_000121 ran.
 *
 * The spelling now drops a domain's final root label (B9-1, re-audit after
 * round eight): the operator and team invitations validated an address as
 * typed, and stored `ops@EXAMPLE.com。` as `ops@example.com.`, which
 * LoginAddress now spells `ops@example.com`. A row stored that way would no
 * longer be found by any lookup, so the rows are rewritten.
 *
 * This runs those two migrations' up() again, unchanged and in one
 * transaction, so there is one
 * definition of how the rows are rewritten and of what stops it: two logins
 * that are one address once rewritten — the second login the invitation made
 * beside a registrant's — are not merged, and the migration stops before
 * changing anything, naming both ids; so do two open offers of one account.
 * Somebody decides which to keep and runs it again. Every row already in the
 * spelling is left as it is.
 *
 * A verification link carries sha1 of the address it was sent to
 * (EmailVerificationController): a link sent to a rewritten login before this
 * ran is refused afterwards (403 `verification.invalid_link`), and the person
 * asks for another (POST /api/v1/email/verify/resend). The address it was
 * sent to is not recorded anywhere, so the old link cannot be honoured.
 * Reset tokens are 2026_05_05_000102's.
 *
 * The down migration does nothing, as theirs do.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(static function (): void {
            foreach ([
                '2026_05_04_000120_store_every_login_address_in_its_one_spelling.php',
                '2026_05_04_000121_store_every_invitation_address_in_its_one_spelling.php',
            ] as $file) {
                /** @var Migration $migration */
                $migration = require __DIR__.'/'.$file;
                $migration->up();
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: see the note above.
    }
};
