<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Queue\QueueRetryClocks;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\StringInput;
use Tests\TestCase;

/**
 * `scripts/serve.sh` starts its workers the way Horizon would, clock for clock.
 *
 * The development script is the one place this repository starts a worker by
 * hand, and the hand-started worker is what walks past a Horizon-only check:
 * it shipped as a single `queue:work` on the default connection with the
 * command's default 60-second timeout, draining provisioning — whose
 * supervisor allows 5,700 seconds — and payments alike. Split into one worker
 * per connection, each worker must satisfy both halves of F-08's inequality
 * for every queue it drains: shorter than its connection's `retry_after`, and
 * no shorter than the supervisor that would otherwise run that queue, so a
 * build is not killed halfway on a laptop that production would have let
 * finish.
 *
 * A queue Horizon supervises and the script does not drain fails here too:
 * a developer would see that work sit on the queue for ever.
 */
final class TheDevelopmentScriptDrainsEveryQueueTest extends TestCase
{
    /**
     * Every `queue:work` the script starts, bound to the command's definition.
     *
     * @return list<array{connection: string, queues: list<string>, timeout: int}>
     */
    private function workers(): array
    {
        $script = (string) file_get_contents(base_path('../../scripts/serve.sh'));

        preg_match_all('/php artisan (queue:work[^)\n]*)/', $script, $matches);

        $this->assertNotSame([], $matches[1], 'serve.sh starts no queue worker at all.');

        $definition = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all()['queue:work'];
        $definition->mergeApplicationDefinition();

        $workers = [];

        foreach ($matches[1] as $line) {
            $input = new StringInput(trim($line));
            $input->bind($definition->getDefinition());

            $connection = $input->getArgument('connection');

            $workers[] = [
                'connection' => is_string($connection) && $connection !== '' ? $connection : (string) config('queue.default'),
                'queues' => array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('queue'))))),
                'timeout' => (int) $input->getOption('timeout'),
            ];
        }

        return $workers;
    }

    #[Test]
    public function every_supervised_queue_has_a_worker_in_the_development_script(): void
    {
        $drained = array_merge(...array_column($this->workers(), 'queues'));

        foreach (QueueRetryClocks::fromConfig()->supervisors() as $supervisor) {
            foreach ($supervisor['queues'] as $queue) {
                $this->assertContains($queue, $drained, "serve.sh starts nothing that drains \"{$queue}\".");
            }
        }
    }

    #[Test]
    public function every_development_worker_keeps_the_clock_its_supervisor_keeps(): void
    {
        $clocks = QueueRetryClocks::fromConfig();

        foreach ($this->workers() as $worker) {
            $who = sprintf('serve.sh\'s worker on %s (%s)', $worker['connection'], implode(',', $worker['queues']));

            $this->assertSame([], $clocks->violationsForWorker($worker['connection'], $worker['timeout'], $who));

            foreach ($worker['queues'] as $queue) {
                foreach ($clocks->supervisorsOf($queue) as $supervisor) {
                    $this->assertSame(
                        $supervisor['connection'],
                        $worker['connection'],
                        "{$who} drains \"{$queue}\" on a different clock from {$supervisor['name']}.",
                    );
                    $this->assertGreaterThanOrEqual(
                        $supervisor['timeout'],
                        $worker['timeout'],
                        "{$who} kills a \"{$queue}\" job sooner than {$supervisor['name']} would.",
                    );
                }
            }
        }
    }
}
