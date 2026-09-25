<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Dedicated\Application\Actions\ChangeDedicatedServerPower;
use Lynomia\Modules\Dedicated\Application\Actions\DedicatedIdempotencyKey;
use Lynomia\Modules\Dedicated\Application\Actions\ExpireAbandonedPowerClaims;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerOperationOutcome;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PowerOperationIndeterminateException;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\InterruptibleDedicatedProvider;

/**
 * A power request whose process died between its claim and its settle.
 *
 * ===========================================================================
 * THE DEFECT
 * ===========================================================================
 *
 * The claim row is committed before the management controller is called, in
 * its own transaction and on purpose — so that a request which dies mid-call
 * leaves evidence that the instruction may have been sent. The only ways out
 * of `claimed` were the three settles that run after the controller answers.
 * A deploy, an OOM kill or a SIGKILL inside that window left the row `claimed`
 * with no timeout, no sweeper and no operator path out, and every later request
 * carrying that key was refused as "still in flight" — for the life of the row.
 *
 * The key is required on this endpoint, and the request's own docblock calls it
 * "a promise that a repeat is free". The caller who repeats the key they were
 * told to repeat was refused for ever, and the only way out was to break the
 * idempotency contract themselves by inventing a new key.
 *
 * ===========================================================================
 * WHAT MUST STILL BE TRUE
 * ===========================================================================
 *
 * One intent reaches the chassis once. A naive "expire claims after N minutes
 * and let the next caller through" re-opens the double-send window the unique
 * index was added to close — and the one request it lets through is the one
 * most likely to interrupt a reset that is already running. So a lapsed claim
 * is SETTLED, as indeterminate, and never released: nothing deletes the row,
 * nothing permits a second claim on the key, and every replay is answered from
 * the row. The key stops being poisoned because it now has an answer, not
 * because it was freed.
 */
final class AnAbandonedPowerClaimIsAnsweredTest extends DedicatedApiTestCase
{
    private const int A_YEAR_IN_MINUTES = 60 * 24 * 365;

    private int $lease;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lease = (int) config('dedicated.power.claim_lease_minutes', 15);
    }

    #[Test]
    public function a_key_whose_request_died_mid_call_is_answered_rather_than_refused_for_ever(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);
        $controller = $this->interruptible($server);

        $crashedAt = CarbonImmutable::now();
        $this->travelTo($crashedAt);

        $controller->duringCall = static function (): never {
            throw new RuntimeException('the worker was killed while the controller was being asked');
        };

        $this->cycle($user, $server, 'reboot-after-crash')->assertStatus(500);

        // The precondition, stated rather than assumed: the crash left a claim
        // and nothing came back for it.
        $this->assertSame(PowerOperationOutcome::Claimed, $this->onlyOperation()->outcome);
        $this->assertSame(1, $controller->mutations);

        /*
         * The same key, repeated exactly as a client is told to repeat it —
         * once just past the lease, and once a year later. Both answers are
         * collected before anything is asserted, so a red run prints what the
         * key said at each: a year of waiting changed nothing, against a
         * process that had not existed for either interval.
         */
        $answers = [];

        foreach (['the lease and a minute later' => $this->lease + 1, 'a year later' => self::A_YEAR_IN_MINUTES] as $when => $minutes) {
            $this->travelTo($crashedAt->addMinutes($minutes));

            $response = $this->cycle($user, $server, 'reboot-after-crash');

            $answers[$when] = [$response->status(), $response->json('error.code')];
        }

        $this->assertSame([
            // 504 and indeterminate, not 409 "ask again shortly": nobody knows
            // whether the reset the dead worker sent reached the chassis, and
            // that is the literal truth about a crashed request.
            'the lease and a minute later' => [504, 'dedicated.power_operation_indeterminate'],
            'a year later' => [504, 'dedicated.power_operation_indeterminate'],
        ], $answers);

        // Answered, not re-sent.
        $this->assertSame(1, $controller->mutations, 'a repeat of the key reached the chassis a second time');
    }

    #[Test]
    public function answering_an_abandoned_claim_never_sends_a_second_intent_to_the_chassis(): void
    {
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $controller = $this->interruptible($server);

        $this->dieMidCall($controller, $server, DedicatedPowerAction::Cycle, 'crashed-cycle');

        $this->travel($this->lease + 1)->minutes();

        try {
            app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::Cycle, 'crashed-cycle');

            $this->fail('An abandoned claim was answered with success.');
        } catch (PowerOperationIndeterminateException $e) {
            $this->assertSame(504, $e->httpStatus());
        }

        /*
         * The call count is the assertion that matters. Deleting the lapsed
         * row and claiming the key afresh would also stop the refusal — and it
         * would send a second reset to a chassis the first one may still be
         * acting on.
         */
        $this->assertSame(1, $controller->mutations, 'the retry reached the chassis');
        $this->assertSame(1, DedicatedPowerOperation::query()->count(), 'the key was claimed a second time');

        $row = $this->onlyOperation();
        $this->assertSame(PowerOperationOutcome::Indeterminate, $row->outcome);
        $this->assertSame('dedicated.power_claim_abandoned', $row->failure_code);
        $this->assertNotNull($row->settled_at);
    }

    #[Test]
    public function a_claim_inside_its_lease_is_still_in_flight(): void
    {
        /*
         * The lease bounds the claim; it does not shorten it. A request still
         * talking to a slow controller is refused exactly as before, because
         * the alternative is a second reset of a chassis that may be going
         * down.
         */
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $controller = $this->interruptible($server);

        $this->dieMidCall($controller, $server, DedicatedPowerAction::On, 'slow-controller');

        $this->travel($this->lease - 1)->minutes();

        try {
            app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::On, 'slow-controller');

            $this->fail('A claim inside its lease was answered rather than refused as in flight.');
        } catch (DedicatedOperationRefusedException $e) {
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertSame(PowerOperationOutcome::Claimed, $this->onlyOperation()->outcome);

        app(ExpireAbandonedPowerClaims::class)->execute();

        $this->assertSame(
            PowerOperationOutcome::Claimed,
            $this->onlyOperation()->outcome,
            'the sweep gave up on a claim whose lease had not lapsed',
        );
        $this->assertSame(1, $controller->mutations);
    }

    #[Test]
    public function a_repeat_after_the_sweep_is_answered_from_the_row_the_sweep_wrote(): void
    {
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $controller = $this->interruptible($server);

        $this->dieMidCall($controller, $server, DedicatedPowerAction::Off, 'crashed-shutdown');

        $this->travel($this->lease + 1)->minutes();

        $this->assertSame(1, app(ExpireAbandonedPowerClaims::class)->execute()['settled']);
        $this->assertSame('dedicated.power_claim_abandoned', $this->onlyOperation()->failure_code);

        $this->expectException(PowerOperationIndeterminateException::class);

        try {
            app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::Off, 'crashed-shutdown');
        } finally {
            $this->assertSame(1, $controller->mutations);
        }
    }

    #[Test]
    public function the_sweep_settles_every_lapsed_claim_and_nothing_else(): void
    {
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);

        $lapsed = [
            $this->operation($server, PowerOperationOutcome::Claimed, minutesAgo: $this->lease + 1),
            $this->operation($server, PowerOperationOutcome::Claimed, minutesAgo: self::A_YEAR_IN_MINUTES),
        ];

        $live = $this->operation($server, PowerOperationOutcome::Claimed, minutesAgo: $this->lease - 1);

        $settled = [
            $this->operation($server, PowerOperationOutcome::Accepted, minutesAgo: self::A_YEAR_IN_MINUTES),
            $this->operation($server, PowerOperationOutcome::Refused, minutesAgo: self::A_YEAR_IN_MINUTES, failureCode: 'dedicated.server_control_unavailable'),
            $this->operation($server, PowerOperationOutcome::Indeterminate, minutesAgo: self::A_YEAR_IN_MINUTES, failureCode: 'dedicated.power_operation_indeterminate'),
        ];

        $before = array_map(static fn (DedicatedPowerOperation $row): array => $row->fresh()?->getAttributes() ?? [], $settled);

        $outcome = app(ExpireAbandonedPowerClaims::class)->execute();

        $this->assertSame(['examined' => 2, 'settled' => 2], $outcome);

        foreach ($lapsed as $row) {
            $row->refresh();
            $this->assertSame(PowerOperationOutcome::Indeterminate, $row->outcome);
            $this->assertSame('dedicated.power_claim_abandoned', $row->failure_code);
            $this->assertNotNull($row->settled_at);
        }

        $this->assertSame(PowerOperationOutcome::Claimed, $live->fresh()?->outcome);

        $this->assertSame(
            $before,
            array_map(static fn (DedicatedPowerOperation $row): array => $row->fresh()?->getAttributes() ?? [], $settled),
            'the sweep rewrote a row that had already been settled',
        );
    }

    #[Test]
    public function the_sweep_does_not_overwrite_an_outcome_a_live_process_already_recorded(): void
    {
        /*
         * The interleaving the conditional write exists for, staged directly.
         *
         * The sweep reads a lapsed claim; before it writes, the process that
         * owned the claim comes back with a real answer from a real
         * controller and settles the row. A sweep that wrote unconditionally
         * would turn a customer's completed reboot into "we do not know", and
         * every replay of the key would raise a 504 for an operation that was
         * accepted.
         *
         * The row has to be selected for this to prove anything — the sweep's
         * own query only reads `claimed` rows, so a row settled BEFORE the
         * sweep looks never reaches the write at all. The answer therefore
         * lands between the read and the write, which is the only moment the
         * write's own condition is the thing standing in the way.
         */
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);

        $row = $this->operation($server, PowerOperationOutcome::Claimed, minutesAgo: $this->lease + 1);

        $answered = $this->answerWhenRead($row);

        $outcome = app(ExpireAbandonedPowerClaims::class)->execute();

        $this->assertTrue($answered(), 'the interleaving was not staged: the sweep never read the claim');
        $this->assertSame(['examined' => 1, 'settled' => 0], $outcome);

        $row->refresh();
        $this->assertSame(PowerOperationOutcome::Accepted, $row->outcome, 'the sweep overwrote the live process\'s answer');
        $this->assertNull($row->failure_code);
    }

    #[Test]
    public function a_repeat_that_finds_its_claim_answered_meanwhile_keeps_the_answer(): void
    {
        /*
         * The other path to the same write. A repeat of the key reads a lapsed
         * claim and is about to give up on it when the owning process comes
         * back with an answer. The repeat must return that answer, not
         * overwrite it with a guess.
         */
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $controller = $this->interruptible($server);

        $this->dieMidCall($controller, $server, DedicatedPowerAction::On, 'answered-late');

        $this->travel($this->lease + 1)->minutes();

        $answered = $this->answerWhenRead($this->onlyOperation());

        $operation = app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::On, 'answered-late');

        $this->assertTrue($answered(), 'the interleaving was not staged: the repeat never read the claim');
        $this->assertTrue($operation->accepted);
        $this->assertSame(PowerOperationOutcome::Accepted, $this->onlyOperation()->outcome);
        $this->assertSame(1, $controller->mutations);
    }

    #[Test]
    public function a_request_that_comes_back_after_its_claim_was_given_up_records_what_the_controller_said(): void
    {
        /*
         * The third writer of `outcome`, and the one that is deliberately not
         * conditional.
         *
         * A controller that answers after the lease has lapsed and the sweep
         * has written "we do not know" has still answered, and its answer is a
         * fact where the sweep's was a guess. The late verdict wins. No second
         * instruction is sent — the row ends more correct, not doubled.
         */
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);
        $controller = $this->interruptible($server);

        $controller->duringCall = function (): void {
            $this->travel($this->lease + 1)->minutes();

            app(ExpireAbandonedPowerClaims::class)->execute();

            $this->assertSame(PowerOperationOutcome::Indeterminate, $this->onlyOperation()->outcome);
        };

        $operation = app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::On, 'slow-but-alive');

        $this->assertTrue($operation->accepted);

        $row = $this->onlyOperation();
        $this->assertSame(PowerOperationOutcome::Accepted, $row->outcome);
        $this->assertNull($row->failure_code);

        // And the key now answers with what the controller said.
        $this->assertTrue(app(ChangeDedicatedServerPower::class)->execute($server, DedicatedPowerAction::On, 'slow-but-alive')->accepted);
        $this->assertSame(1, $controller->mutations);
    }

    #[Test]
    public function the_scheduled_command_runs_the_sweep(): void
    {
        [$customer] = $this->accountWith();
        $server = $this->serverFor($customer);

        $row = $this->operation($server, PowerOperationOutcome::Claimed, minutesAgo: $this->lease + 1);

        $this->artisan('dedicated:expire-abandoned-power-claims')->assertSuccessful();

        $this->assertSame(PowerOperationOutcome::Indeterminate, $row->fresh()?->outcome);
    }

    private function interruptible(DedicatedServer $server): InterruptibleDedicatedProvider
    {
        $endpoint = $server->preferredBmcEndpoint();
        $this->assertNotNull($endpoint);

        $provider = new InterruptibleDedicatedProvider(new FakeDedicatedProvider);

        $this->app->make(DedicatedProviderFactory::class)->swap($endpoint, $provider);

        return $provider;
    }

    private function dieMidCall(
        InterruptibleDedicatedProvider $controller,
        DedicatedServer $server,
        DedicatedPowerAction $action,
        string $key,
    ): void {
        $controller->duringCall = static function (): never {
            throw new RuntimeException('the worker was killed while the controller was being asked');
        };

        try {
            app(ChangeDedicatedServerPower::class)->execute($server, $action, $key);

            $this->fail('The staged crash did not happen.');
        } catch (RuntimeException $e) {
            $this->assertSame('the worker was killed while the controller was being asked', $e->getMessage());
        }

        $this->assertSame(PowerOperationOutcome::Claimed, $this->onlyOperation()->outcome);
    }

    private function cycle(User $user, DedicatedServer $server, string $key): TestResponse
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle']);
    }

    private function onlyOperation(): DedicatedPowerOperation
    {
        return DedicatedPowerOperation::query()->sole();
    }

    private function operation(
        DedicatedServer $server,
        PowerOperationOutcome $outcome,
        int $minutesAgo,
        ?string $failureCode = null,
    ): DedicatedPowerOperation {
        $requestedAt = CarbonImmutable::now()->subMinutes($minutesAgo);

        return DedicatedPowerOperation::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'customer_id' => $server->customer_id,
            'action' => DedicatedPowerAction::Cycle,
            'idempotency_key' => DedicatedIdempotencyKey::for($server, 'power:cycle', bin2hex(random_bytes(8))),
            'outcome' => $outcome,
            'accepted' => $outcome === PowerOperationOutcome::Accepted ? true : null,
            'failure_code' => $failureCode,
            'requested_at' => $requestedAt,
            'settled_at' => $outcome->isSettled() ? $requestedAt->addSeconds(5) : null,
        ]);
    }

    /**
     * Settle the row as accepted the moment anything reads it, once — the
     * owning process answering between a reader's read and its write.
     *
     * @return callable(): bool whether it happened
     */
    private function answerWhenRead(DedicatedPowerOperation $row): callable
    {
        $answered = false;

        DedicatedPowerOperation::retrieved(static function (DedicatedPowerOperation $read) use (&$answered, $row): void {
            if ($answered || $read->getKey() !== $row->getKey() || $read->outcome !== PowerOperationOutcome::Claimed) {
                return;
            }

            $answered = true;

            // Through the query builder, as another process's write would be:
            // the reader's model instance does not see it.
            DB::table('dedicated_power_operations')->where('id', $row->getKey())->update([
                'outcome' => PowerOperationOutcome::Accepted->value,
                'accepted' => true,
                'provider_operation' => 'power_on',
                'settled_at' => now(),
            ]);
        });

        return static function () use (&$answered): bool {
            return $answered;
        };
    }
}
