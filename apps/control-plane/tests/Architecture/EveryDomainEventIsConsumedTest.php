<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * An event nobody raises, and an event nobody hears, are both fictions.
 *
 * Phase 29 found three provisioning events raised into silence: a customer
 * could order a server, have it built, and never be told — because the event
 * that said so had no listener. This gate is what stops the fourth.
 *
 * Both halves are checked, because they fail differently. An event with no
 * dispatcher is a message the platform describes and never sends; an event
 * with no listener is one it sends and nobody acts on. The first is dead
 * weight, the second is a feature that silently does not happen.
 *
 * Zero listeners is allowed only where it is stated, in {@see UNCONSUMED},
 * with the reason. There are currently none.
 */
final class EveryDomainEventIsConsumedTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /**
     * Events that are deliberately raised and not listened to.
     *
     * Empty, and it should stay that way. An entry here is a promise that
     * something outside the application consumes the event — a broadcast, an
     * external subscriber — and that the gap it leaves is reported rather than
     * hidden.
     *
     * @var array<string, string>
     */
    private const array UNCONSUMED = [];

    #[Test]
    public function every_domain_event_is_both_raised_and_heard(): void
    {
        $events = $this->events();

        $this->assertNotSame([], $events, 'The scan found no domain events, so it proved nothing.');

        $sources = $this->sources();
        $wiring = implode("\n", array_values($sources));

        $unraised = [];
        $unheard = [];

        foreach ($events as $event => $path) {
            $dispatched = false;

            foreach ($sources as $file => $code) {
                if ($file === $path) {
                    continue;
                }

                if (preg_match('/(new\s+'.$event.'\s*\(|'.$event.'::dispatch)/', $code) === 1) {
                    $dispatched = true;

                    break;
                }
            }

            if (! $dispatched) {
                $unraised[] = $event;
            }

            if (array_key_exists($event, self::UNCONSUMED)) {
                continue;
            }

            /*
             * A listener is a mapping in the event provider or a subscriber
             * naming the event in its own subscribe() map. Both are how this
             * codebase wires listeners, and a search for one alone would call
             * every subscriber-handled event unheard.
             */
            if (preg_match('/'.$event.'::class\s*=>/', $wiring) !== 1) {
                $unheard[] = $event;
            }
        }

        sort($unraised);
        sort($unheard);

        $this->assertSame([], $unraised, sprintf(
            'These events are declared and nothing raises them: %s',
            implode(', ', $unraised),
        ));

        $this->assertSame([], $unheard, sprintf(
            'These events are raised and nothing listens, so whatever they were meant to cause does not happen: %s',
            implode(', ', $unheard),
        ));
    }

    #[Test]
    public function no_unconsumed_entry_outlives_its_event(): void
    {
        $events = array_keys($this->events());

        $stale = array_diff(array_keys(self::UNCONSUMED), $events);

        $this->assertSame([], array_values($stale), 'These events are excused and no longer exist.');
    }

    /**
     * @return array<string, string> class name => file
     */
    private function events(): array
    {
        $events = [];

        foreach ($this->filesIn('src') as $file) {
            if (! str_contains($file, '/Events/')) {
                continue;
            }

            $code = (string) file_get_contents($file);

            if (preg_match('/\bclass\s+(\w+)/', $code, $matches) === 1) {
                $events[$matches[1]] = $file;
            }
        }

        return $events;
    }

    /**
     * @return array<string, string> file => code
     */
    private function sources(): array
    {
        $sources = [];

        foreach (['src', 'app'] as $directory) {
            foreach ($this->filesIn($directory) as $file) {
                $sources[$file] = (string) file_get_contents($file);
            }
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $path = self::ROOT.'/'.$directory;

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
