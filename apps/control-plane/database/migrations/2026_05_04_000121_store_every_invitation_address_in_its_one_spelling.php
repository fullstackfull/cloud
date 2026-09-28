<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\ValueObjects\LoginAddress;

/*
 * Every team invitation's address rewritten to the one spelling login
 * addresses are stored in (LoginAddress), as 2026_05_04_000120 did for
 * users.email.
 *
 * InviteMember used to store mb_strtolower(trim()) without Unicode normal form
 * C, so an offer to a decomposed `ärger@…` (`a` + U+0308) stayed decomposed.
 * Once login addresses had one spelling, a new offer to `Ärger@…` was written
 * composed; customer_invitations_one_live_offer, which compares
 * (customer_id, lower(email)), saw two addresses, and one account held two
 * live offers for one address, both redeemable (verifier of round eight,
 * after a08802c).
 *
 * The index's predicate decides what may fold. It covers every offer that is
 * not accepted, declined or revoked — an expired offer that was never closed
 * included, because expiry is not in the predicate. Two such offers of one
 * account that are one address once rewritten would break the index, and
 * which of them the account meant to keep is not a migration's decision: the
 * migration stops before changing anything, naming their ids, and somebody
 * revokes one (the team screen does) and runs it again. Every other offer —
 * accepted, declined or revoked, or live and alone — is rewritten, however
 * many closed offers share its address.
 *
 * The down migration does nothing: the spelling a row had before is not
 * recorded, and every spelling it could have had is the same address now.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(static function (): void {
            /** @var list<array{id: string, email: string, address: string}> $rows */
            $rows = [];
            /** @var array<string, list<string>> $live */
            $live = [];

            $query = DB::table('customer_invitations')
                ->select(['id', 'customer_id', 'email', 'accepted_at', 'declined_at', 'revoked_at'])
                ->orderBy('id');

            foreach ($query->cursor() as $row) {
                $address = LoginAddress::normalise((string) $row->email);
                $rows[] = ['id' => (string) $row->id, 'email' => (string) $row->email, 'address' => $address];

                if ($row->accepted_at === null && $row->declined_at === null && $row->revoked_at === null) {
                    $live[$row->customer_id."\0".$address][] = (string) $row->id;
                }
            }

            $collisions = array_values(array_filter($live, static fn (array $ids): bool => count($ids) > 1));

            if ($collisions !== []) {
                throw new RuntimeException(
                    'These open team invitations are one address written differently, in one account; revoke all but one '
                    .'of each set before invitation addresses can be normalised: '
                    .implode('; ', array_map(static fn (array $ids): string => implode(' and ', $ids), $collisions)).'.'
                );
            }

            foreach ($rows as $row) {
                if ($row['email'] !== $row['address']) {
                    DB::table('customer_invitations')->where('id', $row['id'])->update(['email' => $row['address']]);
                }
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: see the note above.
    }
};
