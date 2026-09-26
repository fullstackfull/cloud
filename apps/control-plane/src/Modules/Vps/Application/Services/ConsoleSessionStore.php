<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Application\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;

/**
 * Where a console permit lives for the sixty seconds it is worth anything.
 *
 * The cache rather than a table, and that is a deliberate choice rather than a
 * shortcut. A console permit has no history worth keeping, no reporting value
 * and a lifetime shorter than a page load; a table would need a reaper, and a
 * reaper that falls behind turns "short-lived" into "lives until Tuesday".
 * Expiry that the store itself enforces cannot fall behind — which makes the
 * TTL the first line of enforcement, not the only one: consume() also
 * compares the deadline written inside the record, for the reasons given
 * there.
 *
 * Two things are stored, and neither is the token:
 *
 *  - the session record, under the session id, holding a SHA-256 of the token.
 *    A dump of the cache is therefore not a set of usable console permits, in
 *    the same way a dump of the users table is not a set of usable passwords.
 *  - a consumption marker, written with add() — the one cache operation that
 *    is atomic across processes. Two gateway workers redeeming the same
 *    session id at the same instant both call add(); exactly one gets true.
 *    Reading the record and deleting it afterwards would let both through,
 *    and two simultaneous consoles on one permit is one console the customer
 *    did not open.
 *
 * The store must be shared between the API and the console gateway, which
 * means Redis in any real deployment. On an array or per-node file cache the
 * gateway would never see a session the API issued.
 */
final readonly class ConsoleSessionStore
{
    /**
     * Sixty seconds is the window between the API answering and a browser
     * opening a socket. Anything longer is a credential sitting in a tab.
     */
    public const int TTL_SECONDS = 60;

    /**
     * Public so a test can reach the record this store writes and rewrite its
     * deadline. That is how a test puts a permit past its deadline in front of
     * consume() while the cache still holds it, which is the state the
     * deadline comparison exists for. In this class, issue() writes the key
     * and consume() deletes it once the permit is spent.
     */
    public const string PREFIX = 'vps:console:session:';

    private const string CONSUMED_PREFIX = 'vps:console:consumed:';

    public function __construct(
        private CacheFactory $cache,
    ) {}

    /**
     * @return ConsoleSession the session as issued, carrying the clear token
     */
    public function issue(string $virtualMachineId, string $customerId, ?string $userId, string $id, string $token): ConsoleSession
    {
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);

        $this->store()->put(self::PREFIX.$id, [
            'virtual_machine_id' => $virtualMachineId,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt->toIso8601String(),
        ], self::TTL_SECONDS);

        return new ConsoleSession(
            id: $id,
            virtualMachineId: $virtualMachineId,
            customerId: $customerId,
            userId: $userId,
            expiresAt: $expiresAt,
            token: $token,
        );
    }

    /**
     * Consume a session, or refuse.
     *
     * Returns null for every way a redemption can be wrong — unknown, expired,
     * already consumed, wrong token — because the caller must answer all four
     * identically. A gateway that could distinguish them would let anybody who
     * can reach it enumerate live sessions.
     *
     * An expired permit is refused twice over. The cache's TTL is the first
     * line, and in real operation almost always the one that fires: the store
     * drops the key at sixty seconds and there is nothing left to compare.
     * Not always, though. The deadline is stored to the whole second and the
     * TTL is counted from put(). So even with no clock skew, the deadline falls
     * due up to about a second before the key goes, and in that last fraction
     * the comparison is what refuses.
     *
     * The deadline inside the record is the second line, because the TTL is
     * a property of whichever driver a deployment configures — Redis counts
     * it on the server's own clock, the array store on Carbon's — which this
     * repository can neither state, nor test, nor keep across a driver swap;
     * and because the ways it can be wrong are ordinary rather than exotic: a
     * put() whose TTL drifts from TTL_SECONDS, a driver that rounds the TTL,
     * skew between the host that wrote the deadline and the server counting
     * the TTL, a future writer that reaches this key some other way.
     *
     * The two are one decision, not two guards that can disagree. The record
     * is read first, so the comparison only ever sees what the TTL has not
     * already removed: the verdict is the earlier of the two, and the
     * comparison can refuse earlier than the TTL alone, never later. What it
     * trades is skew. The TTL is relative and immune to it; a deadline is
     * absolute and is not. A consuming clock ahead by δ refuses up to δ early,
     * which costs the customer one more request for a permit; a clock behind
     * gives nothing back, because the TTL still drops the record at sixty
     * seconds. For a sixty-second bearer credential onto a root console, that
     * is the right way round.
     *
     * The deadline is compared before the token and before the atomic add(),
     * so an expired permit costs one read and writes nothing. It is not burnt,
     * and its record is deliberately not deleted: a delete would be a write on
     * a path anyone reaches by guessing an id, and would let an expired permit
     * tell itself apart from an unknown one by its side effect. The TTL
     * removes it soon enough.
     */
    public function consume(string $id, string $token): ?ConsoleSession
    {
        /** @var array<string, mixed>|null $record */
        $record = $this->store()->get(self::PREFIX.$id);

        if (! is_array($record)) {
            return null;
        }

        $expiresAt = self::deadlineOf($record);

        // At the deadline is already too late: a permit lives in
        // [issue, deadline), and the resource tells the client that deadline.
        if ($expiresAt === null || $expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            return null;
        }

        $hash = is_string($record['token_hash'] ?? null) ? $record['token_hash'] : '';

        if (! hash_equals($hash, hash('sha256', $token))) {
            /*
             * Deliberately not consumed. Burning the session on a wrong token
             * would let anyone who can guess a session id — the id travels in
             * a URL, the token does not — destroy a customer's console permit
             * as fast as they can be issued.
             */
            return null;
        }

        // The only atomic step. Whoever wins this writes the marker; everyone
        // else is looking at a session that has already been used.
        if (! $this->store()->add(self::CONSUMED_PREFIX.$id, true, self::TTL_SECONDS)) {
            return null;
        }

        $this->store()->forget(self::PREFIX.$id);

        return new ConsoleSession(
            id: $id,
            virtualMachineId: (string) $record['virtual_machine_id'],
            customerId: (string) $record['customer_id'],
            userId: is_string($record['user_id'] ?? null) ? $record['user_id'] : null,
            expiresAt: $expiresAt,
        );
    }

    /**
     * The deadline issue() wrote, read in exactly the shape issue() writes it,
     * or null.
     *
     * issue() stores toIso8601String(), which is DATE_ATOM, and nothing else is
     * accepted. Strict on purpose: a lenient parse() reads "tomorrow" or
     * "+30 seconds" as the future whenever it is read, and turns a null into
     * "" and "" into now. A deadline that is missing or unreadable is not
     * trusted; failing closed costs the customer one more request for a
     * permit.
     *
     * The format alone is not that strict, so the string must also be exactly
     * what the instant it was read as formats back to. On its own,
     * createFromFormat(DATE_ATOM) takes zone spellings issue() never writes
     * (Z, +0000, UTC, EST). It also takes clock and calendar values that do
     * not exist and rolls them forward to a later instant: 10:99 is read as
     * 11:39, 24:00 as the next midnight, 31 September as 1 October. A string
     * that formats back to itself names one real instant, spelled the way
     * issue() spells one. Any other string is unreadable here.
     *
     * The offset's value is not policed beyond that. issue() writes the
     * application's own offset, which need not be +00:00, and an offset only
     * renames an instant: any deadline refused for an unusual offset could be
     * written again as the same instant in +00:00. The comparison then judges
     * that instant, exactly as written.
     *
     * The format has no fraction of a second, so a permit issued at
     * 10:00:00.700 is enforced until 10:01:00 and not 10:01:00.700 — a
     * lifetime a little under sixty seconds. That is harmless because
     * ConsoleSessionResource renders the same truncated string as expires_at:
     * the instant the client is told and the instant enforced here are one
     * instant.
     *
     * @param  array<string, mixed>  $record
     */
    private static function deadlineOf(array $record): ?CarbonImmutable
    {
        $written = $record['expires_at'] ?? null;

        if (! is_string($written)) {
            return null;
        }

        try {
            $deadline = CarbonImmutable::createFromFormat(DATE_ATOM, $written);
        } catch (InvalidFormatException) {
            return null;
        }

        return $deadline instanceof CarbonImmutable && $deadline->format(DATE_ATOM) === $written
            ? $deadline
            : null;
    }

    private function store(): Repository
    {
        return $this->cache->store();
    }
}
