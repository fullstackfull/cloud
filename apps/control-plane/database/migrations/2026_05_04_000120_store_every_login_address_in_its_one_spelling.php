<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/*
 * Every stored login address rewritten to its one spelling (LoginAddress).
 *
 * Registration stored addresses through strtolower(), which lowercases ASCII
 * only, so a row written before this can hold `Ärger@…` or a decomposed
 * `A` + U+0308; every lookup now compares the normalised spelling, and would
 * not find it (R4, verifier of round eight). Rewriting the rows, rather than
 * comparing lower(email) in SQL, keeps one definition of the spelling: what
 * PostgreSQL's lower() does to a non-ASCII letter depends on the database's
 * LC_CTYPE, and mb_strtolower() does not.
 *
 * Deleted logins included: their rows still own the address under
 * users_email_unique, and an invitation restores them by it.
 *
 * Two rows that already collide — two logins whose addresses are one
 * address once normalised, which the old spellings allowed — are not merged
 * and not rewritten: which of them is the mailbox owner's is not something
 * a migration can know, and each may own customer accounts. The migration
 * stops before changing anything, naming the ids of every such pair, and an
 * operator decides (keep one, change or delete the other) and runs it again.
 * Until it has run, a collision stays what it was.
 *
 * The down migration does nothing: the spelling a row had before is not
 * recorded, and every spelling it could have had is the same login now.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(static function (): void {
            /** @var array<string, list<array{id: string, email: string}>> $byAddress */
            $byAddress = [];

            foreach (DB::table('users')->select(['id', 'email'])->orderBy('id')->cursor() as $row) {
                $byAddress[LoginAddress::normalise((string) $row->email)][] = ['id' => (string) $row->id, 'email' => (string) $row->email];
            }

            $collisions = array_filter($byAddress, static fn (array $rows): bool => count($rows) > 1);

            if ($collisions !== []) {
                $described = array_map(
                    static fn (array $rows): string => implode(' and ', array_column($rows, 'id')),
                    array_values($collisions),
                );

                throw new RuntimeException(
                    'These logins hold one address written differently, and one of each set must be changed or removed '
                    .'by hand before login addresses can be normalised: '.implode('; ', $described).'.'
                );
            }

            foreach ($byAddress as $address => [$row]) {
                if ($row['email'] !== $address) {
                    DB::table('users')->where('id', $row['id'])->update(['email' => $address]);
                }
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: see the note above.
    }
};
