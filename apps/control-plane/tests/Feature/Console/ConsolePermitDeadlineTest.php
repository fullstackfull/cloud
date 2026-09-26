<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;
use Lynomia\Modules\Vps\Http\Resources\ConsoleSessionResource;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The code, and not the cache, refuses a console permit past its deadline.
 *
 * The two older expiry tests — `an_expired_permit_is_refused` and
 * `the_session_is_short_lived` — travel Carbon's clock sixty-one seconds and
 * expect a refusal. Under `CACHE_STORE=array` the array store decides expiry
 * against Carbon, so the travel expires the key and the test passes whatever
 * consume() does. Under Redis, the production store, the TTL is counted by the
 * server's wall clock, which travel() cannot move: the key is still there and,
 * before consume() compared the deadline, the expired permit was redeemed.
 *
 * So nothing here travels past a TTL. Every test that asks a real cache
 * driver for a refusal, but one, writes the record with its deadline already
 * where the test wants it and a TTL of an HOUR, so no driver can be the thing
 * refusing: if the permit is refused, consume() refused it. Two more give the
 * store a mocked repository in place of a driver and watch the calls it makes.
 *
 * The one is the_deadline_the_client_is_told_is_the_deadline_the_code_enforces.
 * It keeps the sixty-second record issue() writes, because the truncation it
 * pins happens inside issue(), and travels to the deadline — short of that
 * TTL by the fraction of a second the truncation drops. The array store
 * expires a key at the precise instant, and Redis on a clock travel() does not
 * move, so under those two only the comparison can refuse there. The database
 * and file stores floor their expiry to the whole second and refuse at that
 * instant unaided: on those drivers, that test's refusal does not isolate the
 * code.
 *
 * Each of the hour-long refusals is followed by a control that demands a
 * success from the identical write path — the same record, rewritten by the
 * same helper with a live deadline. A refusal assertion on its own passes
 * against a store that keeps nothing, or a helper writing to the wrong key;
 * the control fails there, loudly, so this file cannot report green while
 * proving nothing.
 *
 * The controls sit ONE second inside the deadline, not thirty, because only a
 * control pins the early side of the comparison. A comparison clock running
 * fast refuses permits that are still live, and nothing but a demanded
 * success can catch that: with the controls thirty seconds out, a clock
 * twenty-nine seconds fast — refusing every permit for the last half of its
 * life — would still leave every test green. The late side, a clock running
 * slow that honours a permit past its deadline, is pinned by the refusal at
 * the instant of the deadline itself, however far out the controls sit.
 *
 * The clock is frozen at the start of a second because the deadline is stored
 * to the second (ISO-8601 as issue() writes it has no fraction); a clock left
 * running could cross a second between writing a one-second deadline and
 * reading it.
 */
final class ConsolePermitDeadlineTest extends TestCase
{
    /** Long enough that no cache driver expires a record while a test runs. */
    private const int AN_HOUR = 3600;

    /** Asks {@see recordWithDeadline()} to remove the deadline rather than rewrite it. */
    private const string NO_DEADLINE = "\0no deadline";

    private CarbonImmutable $now;

    /** @var list<string> */
    private array $issued = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::instance($this->freezeSecond());
    }

    protected function tearDown(): void
    {
        // An hour-long record outlives the test under any driver but `array`.
        foreach ($this->issued as $id) {
            Cache::forget(ConsoleSessionStore::PREFIX.$id);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_permit_past_its_deadline_is_refused_while_the_cache_still_holds_it(): void
    {
        [$id, $token] = $this->issue();

        $this->rewriteDeadline($id, $this->now->subSecond());

        $this->assertIsArray(
            Cache::get(ConsoleSessionStore::PREFIX.$id),
            'The cache no longer holds the record, so the cache and not the code would be refusing it.',
        );
        $this->assertNull(
            $this->store()->consume($id, $token),
            'A permit past its own deadline was redeemed because the cache still held it.',
        );

        $this->rewriteDeadline($id, $this->now->addSecond());

        $session = $this->store()->consume($id, $token);

        $this->assertNotNull($session, 'The control was refused, so the refusal above proved nothing.');
        $this->assertTrue(
            $session->expiresAt->equalTo($this->now->addSecond()),
            'The session handed back does not carry the deadline that was enforced.',
        );
    }

    #[Test]
    public function a_permit_is_live_one_second_before_its_deadline_and_refused_at_it(): void
    {
        /*
         * The boundary is `>=`: a permit lives in [issue, deadline), and the
         * instant of the deadline is already too late. The equality case is
         * only testable because the clock is at a whole second — against a
         * clock at …:21.4 a deadline of …:21 is strictly past, and a `>`
         * comparison would pass this test too.
         */
        [$id, $token] = $this->issue();

        $this->rewriteDeadline($id, $this->now);

        $this->assertNull(
            $this->store()->consume($id, $token),
            'A permit was redeemed at the instant of its own deadline.',
        );

        $this->rewriteDeadline($id, $this->now->addSecond());

        $this->assertNotNull(
            $this->store()->consume($id, $token),
            'A permit one second short of its deadline was refused.',
        );
    }

    #[Test]
    public function an_expired_permit_is_refused_without_being_deleted_or_burnt(): void
    {
        /*
         * Refused with no write of any kind. Deleting the stale record would
         * be a write on a path anyone reaches by guessing an id, and would let
         * an expired permit tell itself apart from an unknown one by its side
         * effect. The cache's own TTL removes it soon enough.
         */
        [$id, $token] = $this->issue();

        $this->rewriteDeadline($id, $this->now->subMinute());
        $before = Cache::get(ConsoleSessionStore::PREFIX.$id);

        $this->assertNull($this->store()->consume($id, $token));
        $this->assertSame(
            $before,
            Cache::get(ConsoleSessionStore::PREFIX.$id),
            'Refusing an expired permit changed or deleted its record.',
        );

        // No consumed-marker either: the same record, made live, is still
        // redeemable exactly once.
        $this->rewriteDeadline($id, $this->now->addSecond());

        $this->assertNotNull(
            $this->store()->consume($id, $token),
            'Refusing an expired permit burnt it.',
        );
    }

    #[Test]
    public function a_deadline_in_any_shape_but_the_one_issue_writes_is_refused(): void
    {
        /*
         * issue() writes toIso8601String(), which is DATE_ATOM, and consume()
         * reads exactly that. Every one of these is something a more lenient
         * reader accepts and reads as a moment in the future — "tomorrow" and
         * "+30 seconds" are the future by construction, whenever they are
         * read. A deadline read generously is a deadline nobody can state;
         * failing closed costs the customer one more request for a permit.
         */
        $future = $this->now->addSeconds(30);

        foreach ([
            'a date and time without the ATOM shape' => $future->format('Y-m-d H:i:s'),
            'a relative time' => '+30 seconds',
            'a relative day' => 'tomorrow',
            'a fraction of a second issue() never writes' => $future->format('Y-m-d\TH:i:s.uP'),
            'trailing data' => $future->toIso8601String().'x',
        ] as $shape => $deadline) {
            $this->assertRefusedAndControlled($shape, $deadline);
        }

        /*
         * And the zone. issue() writes it as `±HH:MM`; createFromFormat(
         * DATE_ATOM) on its own accepts every one of these in that place — so
         * a strict format is not, by itself, a strict reading. Each is checked
         * to be one the parser alone reads as the future, so none of them can
         * pass here for a reason that has nothing to do with the zone.
         */
        $clock = $future->format('Y-m-d\TH:i:s');

        foreach ([
            'a Z suffix issue() never writes' => $clock.'Z',
            'a lower-case z suffix issue() never writes' => $clock.'z',
            'an offset without its colon' => $clock.'+0000',
            'an offset without its minutes' => $clock.'+00',
            'the negative zero offset issue() never writes' => $clock.'-00:00',
            'an offset whose minutes do not exist' => $clock.'-00:99',
            'a zone abbreviation' => $clock.'UTC',
            'another zone abbreviation' => $clock.'GMT',
            'a zone abbreviation that moves the instant' => $clock.'EST',
        ] as $shape => $deadline) {
            $this->assertTheParserAloneReadsTheFuture($shape, $deadline);
            $this->assertRefusedAndControlled($shape, $deadline);
        }
    }

    #[Test]
    public function a_deadline_that_names_no_real_instant_is_refused_rather_than_rolled_forward(): void
    {
        /*
         * createFromFormat(DATE_ATOM) accepts clock and calendar values that
         * do not exist and rolls them over into a LATER instant: minute 99 is
         * read as the next hour and 39 minutes, hour 24 as the next midnight,
         * 31 September as 1 October. A deadline nothing wrote, read as one
         * further away than anything written — the generous reading the strict
         * format was chosen to rule out. Built from the frozen clock so every
         * one of them rolls forward into the future whenever the test runs,
         * and checked to, so none is refused merely for being in the past.
         */
        $offset = $this->now->format('P');
        $nextYear = $this->now->addYear()->format('Y');

        foreach ([
            'minute 99' => $this->now->format('Y-m-d\TH').':99:00'.$offset,
            'hour 24' => $this->now->format('Y-m-d').'T24:00:00'.$offset,
            'second 60' => $this->now->format('Y-m-d\TH:i').':60'.$offset,
            'the 31st of September' => $nextYear.'-09-31T00:00:00'.$offset,
            'the 30th of February' => $nextYear.'-02-30T00:00:00'.$offset,
            'month 13' => $this->now->format('Y').'-13-01T00:00:00'.$offset,
        ] as $shape => $deadline) {
            $this->assertTheParserAloneReadsTheFuture($shape, $deadline);
            $this->assertRefusedAndControlled($shape, $deadline);
        }
    }

    #[Test]
    public function a_record_without_a_readable_deadline_is_refused_rather_than_trusted(): void
    {
        /*
         * A record whose deadline is missing used to fail inside consume()
         * with "Undefined array key" — after the atomic add() had already
         * spent the permit. A refusal, like every other wrong permit, is the
         * answer; and a null that casts to "" and parses as "now" is not a
         * deadline either.
         */
        foreach ([
            'removed' => self::NO_DEADLINE,
            'null' => null,
            'an empty string' => '',
            'not a date' => 'not a date',
            'a unix timestamp' => $this->now->addSeconds(30)->getTimestamp(),
            'an array' => [$this->now->addSeconds(30)->toIso8601String()],
        ] as $shape => $deadline) {
            [$id, $token] = $this->issue();

            $this->rewriteDeadline($id, $deadline);

            $this->assertNull(
                $this->store()->consume($id, $token),
                "A permit whose deadline is {$shape} was redeemed.",
            );

            $this->rewriteDeadline($id, $this->now->addSecond());

            $this->assertNotNull(
                $this->store()->consume($id, $token),
                "The control for a deadline that is {$shape} was refused, so its refusal proved nothing.",
            );
        }
    }

    #[Test]
    public function an_expired_permit_is_refused_before_anything_is_written(): void
    {
        /*
         * The driver-level test above sees what is left in the cache; this
         * one sees every call. The deadline is compared before the token and
         * before the atomic add(), so an expired permit costs the store one
         * read and nothing else — indistinguishable from one that never
         * existed.
         */
        [$id, $token] = $this->issue();

        $expired = Mockery::mock(Repository::class);
        $expired->shouldReceive('get')->once()
            ->with(ConsoleSessionStore::PREFIX.$id)
            ->andReturn($this->recordWithDeadline($id, $this->now->subSecond()));
        $expired->shouldNotReceive(
            'add', 'put', 'forget', 'forever', 'pull', 'increment', 'decrement',
            'remember', 'rememberForever', 'sear', 'set', 'setMultiple', 'delete', 'deleteMultiple', 'clear',
        );

        $this->assertNull($this->storeOver($expired)->consume($id, $token));

        // Control: the identical record, live, goes all the way through.
        $live = Mockery::mock(Repository::class);
        $live->shouldReceive('get')->once()
            ->with(ConsoleSessionStore::PREFIX.$id)
            ->andReturn($this->recordWithDeadline($id, $this->now->addSecond()));
        $live->shouldReceive('add')->once()->andReturnTrue();
        $live->shouldReceive('forget')->once()->with(ConsoleSessionStore::PREFIX.$id)->andReturnTrue();

        $this->assertNotNull(
            $this->storeOver($live)->consume($id, $token),
            'The control was refused, so the refusal above proved nothing.',
        );
    }

    #[Test]
    public function the_cache_is_still_told_to_expire_the_permit_and_agrees_with_its_deadline(): void
    {
        /*
         * The TTL is the first line of enforcement and stays so; the deadline
         * comparison is the second. This pins the first by watching the call
         * rather than a clock — a clock the production cache does not share
         * with the test — and pins that the two lines agree: the deadline
         * written inside the record is the TTL counted from the same instant.
         */
        $id = (string) Str::ulid();
        $written = null;

        $repository = Mockery::mock(Repository::class);
        $repository->shouldReceive('put')->once()
            ->with(ConsoleSessionStore::PREFIX.$id, Mockery::capture($written), ConsoleSessionStore::TTL_SECONDS)
            ->andReturnTrue();
        $repository->shouldReceive('get')->once()
            ->with(ConsoleSessionStore::PREFIX.$id)
            ->andReturnUsing(function () use (&$written): mixed {
                return $written;
            });
        $repository->shouldReceive('add')->once()
            ->with(Mockery::type('string'), true, ConsoleSessionStore::TTL_SECONDS)
            ->andReturnTrue();
        $repository->shouldReceive('forget')->once()->with(ConsoleSessionStore::PREFIX.$id)->andReturnTrue();

        $store = $this->storeOver($repository);
        $store->issue('vm-1', 'customer-1', null, $id, 'the-token');

        $this->assertIsArray($written);
        $this->assertSame(
            $this->now->addSeconds(ConsoleSessionStore::TTL_SECONDS)->toIso8601String(),
            $written['expires_at'] ?? null,
            'The deadline inside the record and the TTL handed to the cache disagree.',
        );

        $this->assertNotNull(
            $store->consume($id, 'the-token'),
            'A record exactly as issue() wrote it was refused.',
        );
    }

    #[Test]
    public function the_deadline_the_client_is_told_is_the_deadline_the_code_enforces(): void
    {
        /*
         * issue() stores toIso8601String(), which drops the fraction of a
         * second, so a permit issued at 10:00:00.700 is enforced until
         * 10:01:00 and not 10:01:00.700: its real lifetime is a little under
         * sixty seconds. That is harmless only because the resource renders
         * the same truncated string — the instant the client is told and the
         * instant the code enforces are one instant. Before consume()
         * compared the deadline, the array store's own sub-second expiry
         * still held the permit at that instant, and it was redeemed.
         */
        $this->travelTo($this->now->addMilliseconds(700));

        [$early, $earlyToken, $earlySession] = $this->issueWithSession();
        [$late, $lateToken] = $this->issueWithSession();

        $told = (new ConsoleSessionResource($earlySession))->toArray(Request::create('/'))['expires_at'];

        $this->assertSame($this->now->addSeconds(ConsoleSessionStore::TTL_SECONDS)->toIso8601String(), $told);

        $deadline = CarbonImmutable::createFromFormat(DATE_ATOM, $told);
        $this->assertInstanceOf(CarbonImmutable::class, $deadline);

        $this->travelTo($deadline->subSecond());
        $this->assertNotNull(
            $this->store()->consume($early, $earlyToken),
            'A permit was refused one second before the deadline its client was told.',
        );

        $this->travelTo($deadline);
        $this->assertNull(
            $this->store()->consume($late, $lateToken),
            'A permit was redeemed at the deadline its client was told.',
        );
    }

    private function store(): ConsoleSessionStore
    {
        return app(ConsoleSessionStore::class);
    }

    private function storeOver(Repository $repository): ConsoleSessionStore
    {
        $cache = Mockery::mock(CacheFactory::class);
        $cache->shouldReceive('store')->andReturn($repository);

        return new ConsoleSessionStore($cache);
    }

    /**
     * @return array{string, string}
     */
    private function issue(): array
    {
        [$id, $token] = $this->issueWithSession();

        return [$id, $token];
    }

    /**
     * @return array{string, string, ConsoleSession}
     */
    private function issueWithSession(): array
    {
        $id = (string) Str::ulid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $session = $this->store()->issue('vm-1', 'customer-1', null, $id, $token);
        $this->issued[] = $id;

        return [$id, $token, $session];
    }

    /**
     * The record issue() wrote, with its deadline replaced — or removed.
     *
     * Read back rather than rebuilt, so every record a test puts in front of
     * consume() is the one issue() produced with exactly one field changed.
     * It must be there: a helper that found nothing would make every refusal
     * below pass for free.
     *
     * @return array<string, mixed>
     */
    private function recordWithDeadline(string $id, mixed $deadline): array
    {
        $record = Cache::get(ConsoleSessionStore::PREFIX.$id);

        $this->assertIsArray(
            $record,
            'The record issue() wrote is not at the key this test reads, so nothing below would be about it.',
        );

        if ($deadline === self::NO_DEADLINE) {
            unset($record['expires_at']);
        } else {
            $record['expires_at'] = $deadline instanceof CarbonInterface ? $deadline->toIso8601String() : $deadline;
        }

        /** @var array<string, mixed> $record */
        return $record;
    }

    /**
     * The one write statement every driver-level test goes through, so each
     * control covers the exact write its refusal used. An hour of TTL, so no
     * driver is what refuses.
     */
    private function rewriteDeadline(string $id, mixed $deadline): void
    {
        Cache::put(ConsoleSessionStore::PREFIX.$id, $this->recordWithDeadline($id, $deadline), self::AN_HOUR);
    }

    /**
     * A deadline the store must refuse, followed by the control that proves
     * the refusal was about the deadline: the same record, rewritten by the
     * same statement with a live one, is redeemed.
     */
    private function assertRefusedAndControlled(string $shape, mixed $deadline): void
    {
        [$id, $token] = $this->issue();

        $this->rewriteDeadline($id, $deadline);

        $this->assertNull(
            $this->store()->consume($id, $token),
            "A permit whose deadline is {$shape} was redeemed.",
        );

        $this->rewriteDeadline($id, $this->now->addSecond());

        $this->assertNotNull(
            $this->store()->consume($id, $token),
            "The control for {$shape} was refused, so its refusal proved nothing.",
        );
    }

    /**
     * The premise of a strictness case: createFromFormat(DATE_ATOM) on its
     * own accepts the string and reads it as a moment after now. Without it,
     * a refusal could be the parser's or the clock's rather than the
     * strictness the test is about.
     */
    private function assertTheParserAloneReadsTheFuture(string $shape, string $deadline): void
    {
        $read = CarbonImmutable::createFromFormat(DATE_ATOM, $deadline);

        $this->assertTrue(
            $read instanceof CarbonImmutable && $read->greaterThan($this->now),
            "createFromFormat(DATE_ATOM) alone does not read {$shape} as the future, so refusing it proves nothing about how strictly consume() reads.",
        );
    }
}
