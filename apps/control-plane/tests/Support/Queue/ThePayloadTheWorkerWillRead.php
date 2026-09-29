<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use App\Queue\QueueRetryClocks;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\QueueManager;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * What a worker will actually be handed for each queued class: the payload
 * the framework builds, read back, rather than what the class's source looks
 * like it says.
 *
 * ---------------------------------------------------------------------------
 * Why not read the source
 * ---------------------------------------------------------------------------
 *
 * Laravel decides a message's queue, tries, backoff, timeout and deadline at
 * payload-construction time, from class properties, methods and attributes in
 * several spellings each, from traits and parents, from builder calls made at
 * the dispatch site, and — for anything the payload leaves unsaid — from the
 * worker's own options. A guard that re-derives that from source has to be
 * right about every input; the framework only has to be right once. F-08's
 * guard was widened five times, each time closing exactly the axis somebody
 * had named, and each time a new one was found: a comma-string backoff of
 * `'0,0,0'` read as a wait, a `retryUntil()` that switches `maxTries` off, a
 * declared `tries = 0` read as the smallest value when it means "never fail",
 * an `onQueue()` at the call site, a ceiling that lives in `config/horizon.php`.
 * So nothing here parses a class. Each class is sent through the same front
 * door production uses — `Events\Dispatcher::makeListener()` for a listener,
 * `Bus\Dispatcher::dispatch()` for a job, `Mailable::queue()`,
 * `Notifiable::notify()` — onto {@see AConnectionThatBuildsThePayloadAndWritesNothing},
 * and the JSON it would have written is decoded exactly as the worker decodes
 * it.
 *
 * ---------------------------------------------------------------------------
 * What it still cannot see, named rather than implied
 * ---------------------------------------------------------------------------
 *
 *  - **A caller's `->onQueue()`**. The sweep dispatches each class itself, so a
 *    class that names no queue and is routed onto `payments` at one call site
 *    is read on the queue it names. That the probe reads a call-site queue when
 *    one is given is pinned by a single real dispatch in the rule's test; that
 *    no caller does it is not.
 *  - **Values that depend on the event or the constructor arguments.** A
 *    listener's `backoff()` is called with a constructed-without-constructor
 *    event, and a job is built from placeholder arguments. A class whose
 *    answer changes with real data is read once, with these.
 *  - **Another payload hook.** A `Queue::createPayloadUsing()` callback can
 *    rewrite any key after the framework sets it. One is registered
 *    unconditionally by the framework itself (`ContextServiceProvider`), and it
 *    spreads those keys back unchanged. Because this reads the payload after
 *    every hook has run, an in-repo hook would be *seen*, not missed — unless
 *    it were registered only outside the test environment. The rule's test
 *    therefore fails the build the day any file under the production autoload
 *    roots, `bootstrap/`, `config/` or `routes/` names `createPayloadUsing` in
 *    code (comments are dropped before looking, so this sentence is not a
 *    registration). `tests/` is not scanned.
 */
final class ThePayloadTheWorkerWillRead
{
    /** Classes the sweep could not send, with the reason, from the last {@see sweep()}. */
    public array $unprobeable = [];

    /**
     * Swaps every `redis`-driver connection for the probe, for the rest of this
     * application instance.
     */
    public static function install(): self
    {
        /** @var QueueManager $manager */
        $manager = app(QueueFactory::class);

        $manager->extend('redis', static fn () => new class
        {
            /** @param array<string, mixed> $config */
            public function connect(array $config): AConnectionThatBuildsThePayloadAndWritesNothing
            {
                return new AConnectionThatBuildsThePayloadAndWritesNothing(
                    app('redis'),
                    $config['queue'] ?? 'default',
                    $config['connection'] ?? null,
                    $config['retry_after'] ?? 60,
                    $config['block_for'] ?? null,
                    $config['after_commit'] ?? null,
                );
            }
        });

        // Connections already resolved keep their old driver; forget them.
        (new \ReflectionProperty($manager, 'connections'))->setValue($manager, []);

        config()->set('queue.default', 'redis');

        AConnectionThatBuildsThePayloadAndWritesNothing::$built = [];

        return new self;
    }

    /**
     * Every queued class in the application, read.
     *
     * @return list<array{class: string, route: string, connection: string, queue: string, maxTries: ?int, backoff: ?string, timeout: ?int, retryUntil: ?int}>
     */
    public function sweep(): array
    {
        $readings = [];
        $this->unprobeable = [];

        foreach (QueuedClasses::all() as $class) {
            array_push($readings, ...$this->read($class));
        }

        return $readings;
    }

    /**
     * One class, read along the route production sends it by.
     *
     * @param  class-string  $class
     * @return list<array{class: string, route: string, connection: string, queue: string, maxTries: ?int, backoff: ?string, timeout: ?int, retryUntil: ?int}>
     */
    public function read(string $class): array
    {
        $before = count(AConnectionThatBuildsThePayloadAndWritesNothing::$built);

        try {
            $route = $this->send($class);
        } catch (Throwable $e) {
            $this->unprobeable[$class] = $e->getMessage();

            return [];
        }

        $built = array_slice(AConnectionThatBuildsThePayloadAndWritesNothing::$built, $before);

        if ($built === []) {
            $this->unprobeable[$class] = 'sent along the '.$route.' route and no payload was built on any Redis connection';

            return [];
        }

        return array_map(static fn (array $message): array => [
            'class' => $class,
            'route' => $route,
            'connection' => $message['connection'],
            'queue' => substr($message['queue'], strlen('queues:')),
            'maxTries' => self::intOrNull($message['payload']['maxTries'] ?? null),
            'backoff' => isset($message['payload']['backoff']) ? (string) $message['payload']['backoff'] : null,
            'timeout' => self::intOrNull($message['payload']['timeout'] ?? null),
            'retryUntil' => self::intOrNull($message['payload']['retryUntil'] ?? null),
        ], $built);
    }

    /**
     * Dispatches a job that is already built, the way a caller would — for
     * reading what a call-site builder changes.
     *
     * @return list<array{class: string, route: string, connection: string, queue: string, maxTries: ?int, backoff: ?string, timeout: ?int, retryUntil: ?int}>
     */
    public function readDispatchOf(object $job): array
    {
        $before = count(AConnectionThatBuildsThePayloadAndWritesNothing::$built);

        app(Bus::class)->dispatch($job);

        return array_map(static fn (array $message): array => [
            'class' => $job::class,
            'route' => 'call site',
            'connection' => $message['connection'],
            'queue' => substr($message['queue'], strlen('queues:')),
            'maxTries' => self::intOrNull($message['payload']['maxTries'] ?? null),
            'backoff' => isset($message['payload']['backoff']) ? (string) $message['payload']['backoff'] : null,
            'timeout' => self::intOrNull($message['payload']['timeout'] ?? null),
            'retryUntil' => self::intOrNull($message['payload']['retryUntil'] ?? null),
        ], array_slice(AConnectionThatBuildsThePayloadAndWritesNothing::$built, $before));
    }

    /**
     * Every way a reading lets one message run again without a wait, or run
     * past its reservation.
     *
     * Two rules:
     *
     *  - **Everywhere: a declared timeout must be shorter than the clock of
     *    every connection that pops the queue it lands on.** A job's own
     *    `timeout` beats the worker's `--timeout` (`Worker.php:353`), so one
     *    property on one class is enough to reinstate F-08 with both
     *    configuration files untouched. A declared `0` is the worst case —
     *    `pcntl_alarm(max(0, 0))` installs no alarm.
     *  - **On `payments`: more than one attempt means a wait before every
     *    retry.** `tries` without a backoff is not five attempts; it is one
     *    attempt five times, inside a single outage, on the queue that moves
     *    money. The number of attempts is the one the worker will use: a
     *    `retryUntil` switches `maxTries` off entirely (`Worker.php:709-713`)
     *    and a declared `0` means never fail (`:687`, `:713`), both unbounded;
     *    a payload with no `maxTries` takes the supervisor's `tries`
     *    (`Worker.php:679-689`), so a class that says nothing runs as many
     *    times as `config/horizon.php` allows. The waits are the ones the worker
     *    will compute — `Worker::calculateBackoff()` explodes the payload's
     *    comma string and casts each element with `(int)`, so `'0,0,0'`, `''`
     *    and `'nonsense'` are all no wait at all.
     *
     * @param  list<array{class: string, route: string, connection: string, queue: string, maxTries: ?int, backoff: ?string, timeout: ?int, retryUntil: ?int}>  $readings
     * @return array<string, list<string>> class => reasons
     */
    public static function refusals(array $readings, QueueRetryClocks $clocks): array
    {
        $refusals = [];

        foreach ($readings as $reading) {
            $supervisors = $clocks->supervisorsOf($reading['queue']);
            $where = sprintf('%s (%s route, queue "%s")', $reading['class'], $reading['route'], $reading['queue']);

            if ($reading['timeout'] !== null) {
                $connections = $supervisors === []
                    ? [$reading['connection']]
                    : array_values(array_unique(array_column($supervisors, 'connection')));

                foreach ($connections as $connection) {
                    foreach ($clocks->violationsForWorker($connection, $reading['timeout'], $where.'\'s own $timeout') as $violation) {
                        $refusals[$reading['class']][] = $violation;
                    }
                }
            }

            if ($reading['queue'] !== 'payments') {
                continue;
            }

            $attempts = self::attempts($reading, $supervisors);

            if ($attempts <= 1) {
                continue;
            }

            $waits = self::waits($reading, $supervisors, $attempts);

            if (min($waits) <= 0) {
                $refusals[$reading['class']][] = sprintf(
                    '%s can run %s times and waits [%s] seconds between attempts: at least one retry follows the last with no wait.',
                    $where,
                    $attempts === PHP_INT_MAX ? 'unboundedly many' : (string) $attempts,
                    implode(', ', $waits),
                );
            }
        }

        return $refusals;
    }

    /**
     * @param  array{maxTries: ?int, retryUntil: ?int}  $reading
     * @param  list<array{tries: int}>  $supervisors
     */
    private static function attempts(array $reading, array $supervisors): int
    {
        if ($reading['retryUntil'] !== null) {
            return PHP_INT_MAX;
        }

        $tries = $reading['maxTries'];

        if ($tries === null) {
            // No supervisor: nothing sets a ceiling, which is the dangerous answer.
            $ceilings = array_column($supervisors, 'tries');
            $tries = $ceilings === [] || in_array(0, $ceilings, true) ? 0 : max($ceilings);
        }

        return $tries === 0 ? PHP_INT_MAX : $tries;
    }

    /**
     * The wait before each retry, as `Worker::calculateBackoff()` will compute
     * it: element `attempt - 1`, else the last element.
     *
     * @param  array{backoff: ?string}  $reading
     * @param  list<array{backoff: string}>  $supervisors
     * @return non-empty-list<int>
     */
    private static function waits(array $reading, array $supervisors, int $attempts): array
    {
        $declared = $reading['backoff'];

        if ($declared === null) {
            // The worker's --backoff; the shortest of the supervisors that may pop it.
            $candidates = array_column($supervisors, 'backoff');
            $declared = $candidates === [] ? '0' : (string) array_reduce(
                $candidates,
                static fn (?string $shortest, string $candidate): string => $shortest === null || self::first($candidate) < self::first($shortest) ? $candidate : $shortest,
            );
        }

        $ladder = array_map(static fn (string $wait): int => (int) $wait, explode(',', $declared));

        // One wait per retry. Past the end of the ladder the last element
        // repeats, so an unbounded run needs only one more than its length.
        $retries = $attempts === PHP_INT_MAX ? count($ladder) + 1 : $attempts - 1;
        $waits = [];

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $waits[] = $ladder[$attempt - 1] ?? $ladder[count($ladder) - 1];
        }

        return $waits;
    }

    private static function first(string $ladder): int
    {
        return (int) explode(',', $ladder)[0];
    }

    /**
     * Sends a class along the route production uses for it, returning the
     * route's name.
     *
     * @param  class-string  $class
     */
    private function send(string $class): string
    {
        $events = $this->eventsListenedToBy($class);

        if ($events !== []) {
            foreach ($events as $event) {
                $instance = (new ReflectionClass($event))->newInstanceWithoutConstructor();

                app('events')->makeListener($class)($event, [$instance]);
            }

            return 'listener';
        }

        $instance = $this->construct($class);

        if ($instance instanceof Mailable) {
            $instance->queue(app(QueueFactory::class));

            return 'mailable';
        }

        if ($instance instanceof Notification) {
            (new AnonymousNotifiable)->route('mail', 'probe@example.test')->notify($instance);

            return 'notification';
        }

        app(Bus::class)->dispatch($instance);

        return 'job';
    }

    /**
     * The events a class is registered against, as the dispatcher holds them.
     *
     * @return list<class-string>
     */
    private function eventsListenedToBy(string $class): array
    {
        $events = [];

        foreach (app('events')->getRawListeners() as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                $name = is_string($listener) ? explode('@', $listener)[0] : (is_array($listener) ? ($listener[0] ?? null) : null);

                if ($name === $class && class_exists((string) $event)) {
                    $events[] = (string) $event;
                }
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * Builds a class the way a caller would, with placeholder arguments.
     *
     * Constructed rather than instantiated without a constructor, because a
     * queue assigned in the constructor — `$this->onQueue(...)`, the idiom this
     * repository uses most — is only ever visible on a constructed instance.
     *
     * @param  class-string  $class
     */
    private function construct(string $class): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            $type = $parameter->getType();

            if ($type === null || $type->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            if (! $type instanceof ReflectionNamedType) {
                throw new RuntimeException('Cannot build a placeholder for parameter $'.$parameter->getName().' of type '.$type);
            }

            $arguments[] = match ($type->getName()) {
                'string' => 'probe',
                'int' => 1,
                'float' => 1.0,
                'bool' => false,
                'array' => [],
                default => app($type->getName()),
            };
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
