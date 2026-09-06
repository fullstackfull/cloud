<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Provisioning\Application\Actions\AdoptOrphanResource;
use Lynomia\Modules\Provisioning\Application\Actions\CreateProvisioningJob;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Domain\Contracts\ProvisioningHandler;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningAttempt;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Two workers, two real database connections, one job.
 *
 * This test deliberately does not use RefreshDatabase. That trait wraps the
 * whole test in a transaction on one connection, which is precisely what makes
 * a concurrency test meaningless: a second connection cannot see the fixtures,
 * and two queries issued on one connection are serialised by definition, so
 * they can never race. Rows are therefore committed for real and removed again
 * in tearDown.
 *
 * The window under test is the one every queue eventually opens. A message is
 * delivered twice, or a worker is restarted while its lease is still live, and
 * two processes hold the same job at the same moment. Both read it as queued.
 * The only thing standing between that and two servers billed to one customer
 * is that the claim — the read, the check and the write — is a single atomic
 * step under a row lock.
 */
final class ProvisioningConcurrencyTest extends ProvisioningTestCase
{
    private const string SECOND_CONNECTION = 'worker_b';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();

        // A genuinely separate connection to the same database: the same
        // credentials, a different PDO handle and therefore a different
        // transaction.
        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.config('database.default'),
            ),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SECOND_CONNECTION);
        $this->wipe();

        parent::tearDown();
    }

    #[Test]
    public function only_one_of_two_workers_claims_a_queued_job(): void
    {
        $job = $this->committedJob();
        $default = (string) config('database.default');
        $interleaved = false;

        /*
         * Worker B runs to completion — claim, provider call, settle, commit —
         * while worker A is inside its claim transaction but has not yet taken
         * the row lock. Both workers therefore started from the same fact: a
         * queued job. Swapping the default connection is what puts B on its
         * own handle; A's transaction belongs to the connection it began on
         * and is untouched by the switch.
         */
        Event::listen(function (TransactionBeginning $event) use (&$interleaved, $default, $job): void {
            if ($interleaved || $event->connection->getName() !== $default) {
                return;
            }

            $interleaved = true;

            DB::setDefaultConnection(self::SECOND_CONNECTION);

            try {
                $this->runWorker($job->id);
            } finally {
                DB::setDefaultConnection($default);
            }
        });

        $this->runWorker($job->id);

        $this->assertTrue($interleaved, 'The second worker never ran inside the window under test.');

        $job = $job->fresh();
        $this->assertNotNull($job);

        // One claim, one attempt, one call to the provider. The second worker
        // found a job that was no longer queued and left it alone.
        $this->assertSame(1, $job->attempts);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertSame(1, ProvisioningAttempt::query()->where('provisioning_job_id', $job->id)->count());

        /*
         * This assertion is what separates a real race from a simulation. Had
         * both workers shared one connection, B's work would have been a
         * savepoint inside A's transaction rather than a committed fact A
         * could see.
         */
        $this->assertNotSame(
            DB::connection((string) config('database.default'))->getPdo(),
            DB::connection(self::SECOND_CONNECTION)->getPdo(),
        );
    }

    #[Test]
    public function the_remote_job_id_is_committed_before_the_handler_returns(): void
    {
        $observer = new class implements ProvisioningHandler
        {
            public ?string $seenByAnotherConnection = null;

            public function kind(): ProvisioningJobKind
            {
                return ProvisioningJobKind::CreateVps;
            }

            public function execute(ProvisioningJob $job): ProvisioningResult
            {
                $job->recordRemoteJobId('remote-upid-4242');

                // Read from a different connection entirely. Anything visible
                // there is committed, which is the only definition of
                // "survives this worker being killed" that means anything.
                $this->seenByAnotherConnection = DB::connection('worker_b')
                    ->table('provisioning_jobs')
                    ->where('id', $job->getKey())
                    ->value('remote_job_id');

                return ProvisioningResult::succeeded('remote-upid-4242', 'vm-4242');
            }
        };

        $this->handlers->register($observer);

        $this->runWorker($this->committedJob()->id);

        /*
         * If this is null, a worker killed one line later leaves a job that
         * may have built a machine nobody can find: the platform cannot ask
         * the provider "what happened to this request?", so every recovery is
         * a guess, and the safe-looking guess builds a second server.
         */
        $this->assertSame('remote-upid-4242', $observer->seenByAnotherConnection);
    }

    #[Test]
    public function two_connections_creating_the_same_idempotency_key_produce_one_job(): void
    {
        $create = app(CreateProvisioningJob::class);
        $default = (string) config('database.default');

        DB::setDefaultConnection(self::SECOND_CONNECTION);

        try {
            $winner = $create->execute($this->request());
        } finally {
            DB::setDefaultConnection($default);
        }

        $loser = $create->execute($this->request());

        // The second caller's insert met a unique index it never read, raised
        // by a row committed on a connection it cannot see inside a
        // transaction it is not part of, and converged on it.
        $this->assertSame($winner->id, $loser->id);
        $this->assertTrue($winner->wasRecentlyCreated);
        $this->assertFalse($loser->wasRecentlyCreated);
        $this->assertSame(1, ProvisioningJob::query()->count());
    }

    #[Test]
    public function two_operators_cannot_adopt_the_same_orphan_onto_two_jobs(): void
    {
        /*
         * Two people — or two runs of a reconciler — find the same stray
         * machine and each attach it to their own timed-out job. The guard
         * against it is a SELECT for an existing claimant, and a SELECT sees
         * only what is committed: with the reads overlapping, both find
         * nothing and both write. Nothing in the schema stops them, because
         * there is no unique index over result->provider_reference to fall
         * back on.
         *
         * The result is two jobs pointing at one machine, which is the state
         * the module exists to prevent: the next termination deletes a server
         * somebody else is still paying for.
         */
        $first = $this->committedJob(['status' => ProvisioningJobStatus::NeedsReview->value]);
        $second = $this->committedJob(['status' => ProvisioningJobStatus::NeedsReview->value]);

        $adopt = app(AdoptOrphanResource::class);
        $default = (string) config('database.default');
        $interleaved = false;
        $secondFailed = false;

        // Bounded so that a correct implementation, which makes the second
        // adoption wait for the first, ends the test instead of hanging on it.
        DB::connection(self::SECOND_CONNECTION)->statement("set lock_timeout = '2s'");

        /*
         * The interleaving point is AFTER the first adoption has checked for a
         * claimant and BEFORE it commits — the only window in which the race
         * is real. Hooking any earlier would let the second adoption commit in
         * time to be seen, and the test would pass against code that has no
         * protection at all.
         */
        DB::listen(function ($query) use (&$interleaved, &$secondFailed, $default, $adopt, $second): void {
            if ($interleaved || $query->connectionName !== $default || ! str_contains($query->sql, 'provider_reference')) {
                return;
            }

            $interleaved = true;

            DB::setDefaultConnection(self::SECOND_CONNECTION);

            try {
                $adopt->execute($second->fresh(), 'vm-900');
            } catch (Throwable) {
                // Blocked by the first adoption and abandoned, which is the
                // correct outcome: the reference is not free to claim.
                $secondFailed = true;
            } finally {
                DB::setDefaultConnection($default);
            }
        });

        $adopt->execute($first, 'vm-900');

        $this->assertTrue($interleaved, 'The second adoption never ran inside the window under test.');
        $this->assertTrue($secondFailed, 'The second adoption was allowed to claim a reference the first was claiming.');

        $claimants = ProvisioningJob::query()
            ->whereRaw("result->>'provider_reference' = ?", ['vm-900'])
            ->pluck('id')
            ->all();

        // Exactly one job owns the machine.
        $this->assertSame([$first->id], $claimants);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $second->fresh()?->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function committedJob(array $attributes = []): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'payload' => ['hostname' => 'vps-race', 'fake' => ['outcome' => 'succeed']],
            ...$attributes,
        ]);
    }

    private function request(): ProvisioningJobRequest
    {
        return new ProvisioningJobRequest(
            kind: ProvisioningJobKind::CreateVps,
            idempotencyKey: 'race:one-key',
            provider: 'fake',
            payload: ['hostname' => 'vps-race'],
        );
    }

    /**
     * Committed rows outlive the test, so they are removed either side of it
     * rather than rolled back.
     */
    private function wipe(): void
    {
        DB::statement('truncate table provisioning_attempts, provisioning_jobs, services, customers restart identity cascade');
    }
}
