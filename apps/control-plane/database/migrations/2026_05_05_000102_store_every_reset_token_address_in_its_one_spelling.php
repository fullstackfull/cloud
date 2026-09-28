<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/*
 * Every outstanding password reset token's address rewritten to the one
 * spelling login addresses are stored in (LoginAddress), as
 * 2026_05_04_000120 and 2026_05_05_000101 did for users.email.
 *
 * The broker finds a token by the login's address
 * (Illuminate\Auth\Passwords\DatabaseTokenRepository reads
 * password_reset_tokens where email = the user's address). 000120 rewrote
 * users.email and left this table alone, so a link issued to `Ärger@…`
 * before it ran was not found once the login read `ärger@…`, and the reset
 * answered 422 `password.reset_failed` (B9-2, re-audit after round eight).
 * The token column holds a hash of the token alone, not of the address, so
 * rewriting the address keeps the link working.
 *
 * `email` is this table's primary key, so two rows that are one address once
 * rewritten cannot both stay. The broker keeps one token per address, and the
 * most recently issued one is the link the person last asked for: that row is
 * kept (the latest created_at; a row with none counts as the oldest) and the
 * others are deleted. A deleted token's link stops working; the person asks
 * for another. Unlike users, nothing is lost that a person cannot ask for
 * again in one step, so the migration does not stop for it.
 *
 * Verification links are not tokens in this table: they carry sha1 of the
 * login's address, and 2026_05_05_000101's note says what happens to them.
 *
 * The down migration does nothing: the spelling a row had before is not
 * recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(static function (): void {
            /** @var array<string, list<object{email: string, created_at: string|null}>> $byAddress */
            $byAddress = [];

            foreach (DB::table('password_reset_tokens')->select(['email', 'created_at'])->orderBy('email')->cursor() as $row) {
                $byAddress[LoginAddress::normalise((string) $row->email)][] = $row;
            }

            foreach ($byAddress as $address => $rows) {
                usort($rows, static fn (object $a, object $b): int => strcmp((string) $b->created_at, (string) $a->created_at));
                $kept = array_shift($rows);

                foreach ($rows as $row) {
                    DB::table('password_reset_tokens')->where('email', $row->email)->delete();
                }

                if ($kept->email !== $address) {
                    DB::table('password_reset_tokens')->where('email', $kept->email)->update(['email' => $address]);
                }
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: see the note above.
    }
};
