<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use Illuminate\Queue\RedisQueue;

/**
 * A Redis queue connection that builds every payload exactly as the real one
 * does, keeps it, and pushes nothing.
 *
 * It is the real `RedisQueue`, constructed from the connection's own
 * configuration, so everything that decides what a worker will read is the
 * framework's and not ours: `createPayload()` resolves `maxTries`, `backoff`,
 * `timeout` and `retryUntil` from the class's properties, methods and
 * attributes, and `getQueue()` applies the `queues:` prefix, queue routing and
 * `enum_value()` flattening to name the list the message would land on. The
 * payload kept is the JSON string the worker would pop, after every payload
 * hook has run — not the array a hook sees halfway through construction,
 * where `data.commandName` is still the job object and only becomes a class
 * name after the hooks return (`Queue.php:176` and `:209`).
 *
 * ---------------------------------------------------------------------------
 * Why `push()` and `later()` rather than `pushRaw()` and `laterRaw()`
 * ---------------------------------------------------------------------------
 *
 * Not because an after-commit job could otherwise hide its payload.
 * `RedisQueue::push()` evaluates `createPayload()` as an argument to
 * `enqueueUsing()`, before the after-commit branch is chosen, so the payload
 * would be built either way. Something in this repository does opt into
 * after-commit dispatch — `SetReverseDns` dispatches `PublishReverseDnsRecord`
 * with `->afterCommit()`, which sets the same `$afterCommit` property
 * `Queue::shouldDispatchAfterCommit()` reads — and it is immaterial here for
 * that reason, and because the sweep dispatches classes itself rather than
 * through `PendingDispatch`.
 *
 * What overriding the outer pair buys is narrower and real: `enqueueUsing()`
 * is never entered, so no `JobQueueing` or `JobQueued` event is raised for a
 * message that was never queued (`Queue.php:385-389`), and nothing is written
 * to Redis, so a sweep over every queued class in the application needs no
 * Redis server and cannot leave messages behind for a real worker.
 */
final class AConnectionThatBuildsThePayloadAndWritesNothing extends RedisQueue
{
    /**
     * Every payload built on any probe connection, in order.
     *
     * @var list<array{connection: string, queue: string, payload: array<string, mixed>}>
     */
    public static array $built = [];

    public function push($job, $data = '', $queue = null)
    {
        $list = $this->getQueue($queue);

        return $this->keep($list, $this->createPayload($job, $list, $data));
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $list = $this->getQueue($queue);

        return $this->keep($list, $this->createPayload($job, $list, $data, $delay));
    }

    private function keep(string $list, string $payload): string
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        self::$built[] = [
            'connection' => (string) $this->getConnectionName(),
            'queue' => $list,
            'payload' => $decoded,
        ];

        return (string) ($decoded['uuid'] ?? '');
    }
}
