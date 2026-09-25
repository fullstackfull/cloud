<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Queue\QueueRetryClocks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Tries;
use Lynomia\Modules\Billing\Application\Listeners\RecordRefundAgainstTheInvoice;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Domains\Application\Listeners\RegisterDomainOnPayment;
use Lynomia\Modules\Orders\Application\Listeners\FulfilOrderOnSettlement;
use Lynomia\Modules\Orders\Application\Listeners\RecordFailedPaymentOnTheOrder;
use Lynomia\Modules\Orders\Application\Listeners\RecordRefundOnTheOrder;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Listeners\ReviveSubscriptionOnRenewalPayment;
use Lynomia\Modules\Subscriptions\Application\Listeners\StartDunningOnFailedPayment;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Queue\PlantsAQueuedClassInARealRoot;
use Tests\Support\Queue\QueuedClasses;
use Tests\Support\Queue\ThePayloadTheWorkerWillRead;
use Tests\TestCase;

/**
 * Nothing on the money queue retries without waiting, and nothing anywhere
 * outlives the reservation of the message it is running.
 *
 * F-08's configured half — `retry_after` below every supervisor's timeout — is
 * guarded by {@see EveryQueueOutlivesItsLongestJobTest}. This is the half a
 * single class can reinstate on its own, with both configuration files
 * untouched: a job's own `$timeout` beats the worker's, and a retried payments
 * listener with no wait between attempts turns one transient failure into five
 * executions inside the same second.
 *
 * Every assertion reads the payload the framework actually builds for each
 * class ({@see ThePayloadTheWorkerWillRead}), not the class's source. The sweep
 * is over every `ShouldQueue` class under the production autoload roots
 * ({@see QueuedClasses}), `app/` included.
 *
 * Scoped to `payments` for the ladder rule on purpose. Five classes elsewhere
 * carry `tries > 1` with no ladder — `InstallWordPressOnceTheAccountExists`
 * (provisioning, 5), `EnforceServiceStateForSubscription` (provisioning, 3),
 * and `NotifyOnProvisioningOutcome`, `NotifyOnSubscriptionChange`,
 * `NotifyOnBillingEvent` (notifications, 3). They converge on retry (a unique
 * idempotency key and in-lock guards for the first two; a unique index on the
 * notification row for the rest), so they are named here rather than hidden
 * behind a narrower predicate, and widening the rule to them is a decision for
 * the modules that own them.
 */
final class EveryRetriedPaymentsListenerWaitsBetweenAttemptsTest extends TestCase
{
    use PlantsAQueuedClassInARealRoot;

    /**
     * The nine listeners on the money queue, as the dispatcher routes them.
     *
     * The last two are F-19's: they record a declined first payment and a
     * full refund on the order. Neither moves money, but both hear money's
     * events on money's queue, and a retry with no wait is the same hazard
     * there as anywhere else on it.
     */
    private const array THE_MONEY_QUEUE = [
        RecordRefundAgainstTheInvoice::class,
        SettleInvoiceOnPaymentCaptured::class,
        RegisterDomainOnPayment::class,
        FulfilOrderOnSettlement::class,
        ResizeOnPlanChangeSettlement::class,
        ReviveSubscriptionOnRenewalPayment::class,
        StartDunningOnFailedPayment::class,
        RecordFailedPaymentOnTheOrder::class,
        RecordRefundOnTheOrder::class,
    ];

    private ThePayloadTheWorkerWillRead $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = ThePayloadTheWorkerWillRead::install();
    }

    /**
     * @param  list<array{class: string, queue: string}>  $readings
     * @return array<string, list<string>>
     */
    private function refusalsOf(array $readings): array
    {
        return ThePayloadTheWorkerWillRead::refusals($readings, QueueRetryClocks::fromConfig());
    }

    #[Test]
    public function every_queued_class_in_the_application_is_read(): void
    {
        $readings = $this->probe->sweep();

        $this->assertSame([], $this->probe->unprobeable, 'A class the sweep cannot send is a class nothing here measures.');
        $this->assertSame(
            QueuedClasses::all(),
            array_values(array_unique(array_column($readings, 'class'))),
            'Every queued class must produce at least one payload.',
        );
    }

    #[Test]
    public function every_class_on_the_money_queue_is_read_along_the_route_production_uses(): void
    {
        $onPayments = array_values(array_filter(
            $this->probe->sweep(),
            static fn (array $reading): bool => $reading['queue'] === 'payments',
        ));

        $classes = array_column($onPayments, 'class');
        sort($classes);
        $expected = self::THE_MONEY_QUEUE;
        sort($expected);

        // Exact, so a probe that stops looking reads as a failure rather than
        // as a clean queue.
        $this->assertSame($expected, $classes);
        $this->assertSame(['listener'], array_values(array_unique(array_column($onPayments, 'route'))));
    }

    #[Test]
    public function no_class_retries_on_payments_without_waiting_or_outlives_its_reservation(): void
    {
        $this->assertSame([], $this->refusalsOf($this->probe->sweep()));
    }

    #[Test]
    public function the_money_queue_is_read_with_the_numbers_the_worker_will_use(): void
    {
        $read = [];

        foreach ($this->probe->sweep() as $reading) {
            if ($reading['queue'] === 'payments') {
                $read[$reading['class']] = [$reading['maxTries'], $reading['backoff'], $reading['timeout'], $reading['retryUntil']];
            }
        }

        ksort($read);

        $expected = array_fill_keys(self::THE_MONEY_QUEUE, [5, '5,15,60,300', null, null]);
        ksort($expected);

        $this->assertSame($expected, $read);

        // And individually, so neither half of the set can hide behind the other.
        foreach (self::THE_MONEY_QUEUE as $class) {
            $this->assertSame([], $this->refusalsOf($this->probe->read($class)), $class);
        }
    }

    #[Test]
    public function tries_with_no_backoff_is_refused(): void
    {
        $this->assertRefused(new F08TriesWithNoBackoff, 'waits [0, 0, 0, 0]');
    }

    #[Test]
    public function a_zeroed_comma_ladder_is_refused_because_the_worker_casts_each_element(): void
    {
        $this->assertRefused(new F08ZeroedCommaLadder, 'waits [0, 0, 0, 0]');
        $this->assertRefused(new F08LadderStartingAtZero, 'waits [0, 60, 60, 60]');
        $this->assertRefused(new F08NonsenseLadder, 'waits [0, 0, 0, 0]');
    }

    #[Test]
    public function a_deadline_switches_the_ceiling_off_and_is_refused_without_a_wait(): void
    {
        $this->assertRefused(new F08DeadlineWithNoBackoff, 'unboundedly many');
    }

    #[Test]
    public function a_declared_zero_means_never_fail_and_is_refused_without_a_wait(): void
    {
        $this->assertRefused(new F08ZeroTries, 'unboundedly many');
    }

    #[Test]
    public function a_class_that_declares_no_ceiling_takes_the_supervisors(): void
    {
        // supervisor-payments carries `tries => 5`, so saying nothing is five
        // attempts, not one.
        $this->assertRefused(new F08SaysNothing, 'can run 5 times');
    }

    #[Test]
    public function an_attribute_is_read_exactly_as_a_property_is(): void
    {
        $this->assertRefused(new F08TriesByAttribute, 'can run 5 times');
    }

    #[Test]
    public function a_classs_own_timeout_past_the_clock_is_refused_on_any_queue(): void
    {
        $this->assertRefused(new F08OwnTimeoutPastTheClock, 'may run a job for 600 seconds');
        $this->assertRefused(new F08OwnTimeoutOfZero, 'installs no alarm');
    }

    #[Test]
    public function a_real_ladder_is_accepted(): void
    {
        $readings = $this->probe->readDispatchOf(new F08RealLadder);

        $this->assertCount(1, $readings);
        $this->assertSame('payments', $readings[0]['queue']);
        $this->assertSame([], $this->refusalsOf($readings));
    }

    #[Test]
    public function a_queue_named_at_the_call_site_is_the_queue_that_is_read(): void
    {
        $readings = $this->probe->readDispatchOf((new F08NamesNoQueue)->onQueue('payments'));

        $this->assertSame('payments', $readings[0]['queue'] ?? null);
        $this->assertArrayHasKey(F08NamesNoQueue::class, $this->refusalsOf($readings));
    }

    #[Test]
    public function the_sweep_reaches_the_directory_make_job_writes_to(): void
    {
        $this->planting(
            '    use \\'.Dispatchable::class.', \\'.Queueable::class.";\n\n    public int \$tries = 5;\n\n    public function __construct()\n    {\n        \$this->onQueue('payments');\n    }\n\n    public function handle(): void {}",
            'implements \\'.ShouldQueue::class,
            function (string $class): void {
                $this->assertContains($class, QueuedClasses::all());
                $this->assertArrayHasKey($class, $this->refusalsOf($this->probe->sweep()));
            },
        );

        $this->assertSame([], glob(base_path('app/Jobs/F08Planted*')) ?: []);
    }

    #[Test]
    public function the_sweep_reads_every_production_autoload_root_and_nothing_else(): void
    {
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $expected = [];

        foreach ($composer['autoload']['psr-4'] as $prefix => $directory) {
            $expected[$prefix] = rtrim(base_path((string) $directory), '/');
        }

        ksort($expected);

        $this->assertSame($expected, QueuedClasses::roots());
        $this->assertSame(base_path('app'), QueuedClasses::roots()['App\\'] ?? null);
        $this->assertArrayNotHasKey('Tests\\', QueuedClasses::roots());
    }

    #[Test]
    public function no_production_file_registers_a_payload_hook(): void
    {
        $found = [];

        $directories = [...array_values(QueuedClasses::roots()), base_path('bootstrap'), base_path('config'), base_path('routes')];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/bootstrap/cache/')) {
                    continue;
                }

                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    // Comments are dropped; string literals are kept, erring toward the alarm.
                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }

                    if (is_array($token) && str_contains($token[1], 'createPayloadUsing')) {
                        $found[] = $file->getPathname();
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($found)), 'A payload hook registered outside the test environment could change what a worker reads without the sweep seeing it.');
    }

    #[Test]
    public function the_hook_rule_tells_a_call_apart_from_a_sentence_about_one(): void
    {
        $call = "<?php\n\\Illuminate\\Queue\\Queue::createPayloadUsing(fn () => []);\n";
        $sentence = "<?php\n/** Nothing here calls createPayloadUsing. */\n// nor createPayloadUsing\n";

        $calls = static fn (string $source): bool => array_filter(
            token_get_all($source),
            static fn (mixed $token): bool => is_array($token)
                && ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                && str_contains($token[1], 'createPayloadUsing'),
        ) !== [];

        $this->assertTrue($calls($call));
        $this->assertFalse($calls($sentence));
    }

    private function assertRefused(object $job, string $because): void
    {
        $readings = $this->probe->readDispatchOf($job);

        $this->assertNotSame([], $readings, 'The probe built no payload for '.$job::class);

        $refusals = $this->refusalsOf($readings)[$job::class] ?? [];

        $this->assertNotSame([], $refusals, $job::class.' was not refused.');
        $this->assertStringContainsString($because, implode("\n", $refusals));
    }
}

/*
 * The shapes, one per class, each landing on `payments` through the
 * constructor idiom this repository uses. Declared here rather than under an
 * autoload root so that the application sweep never reads them.
 */

abstract class F08OnPayments implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct()
    {
        $this->onQueue('payments');
    }

    public function handle(): void {}
}

final class F08TriesWithNoBackoff extends F08OnPayments
{
    public int $tries = 5;
}

final class F08ZeroedCommaLadder extends F08OnPayments
{
    public int $tries = 5;

    public string $backoff = '0,0,0';
}

final class F08LadderStartingAtZero extends F08OnPayments
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [0, 60];
}

final class F08NonsenseLadder extends F08OnPayments
{
    public int $tries = 5;

    public string $backoff = 'nonsense';
}

final class F08DeadlineWithNoBackoff extends F08OnPayments
{
    public int $tries = 5;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }
}

final class F08ZeroTries extends F08OnPayments
{
    public int $tries = 0;
}

final class F08SaysNothing extends F08OnPayments {}

#[Tries(5)]
final class F08TriesByAttribute extends F08OnPayments {}

final class F08OwnTimeoutPastTheClock extends F08OnPayments
{
    public int $tries = 1;

    public int $timeout = 600;
}

final class F08OwnTimeoutOfZero extends F08OnPayments
{
    public int $tries = 1;

    public int $timeout = 0;
}

final class F08RealLadder extends F08OnPayments
{
    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }
}

final class F08NamesNoQueue implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    public function handle(): void {}
}
