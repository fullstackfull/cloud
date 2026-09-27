<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\Activity\TheFeedAnswersWhatTheDescriptionPublishesTest;
use Tests\TestCase;

/**
 * An operation `docs/openapi.yaml` publishes with a page envelope is one
 * whose controller builds that page's meta.
 *
 * Nine customer operations were published with `PaginationMeta` (page,
 * per_page, total, last_page, max_per_page) and answered without it: seven
 * with `{"data": [...]}` alone, one with a meta of another shape, and the
 * notification inbox without `max_per_page`. The activity feed was published
 * with it too, while it answers `next_cursor` and `per_page`. Nothing
 * compared the envelope `resources/openapi/operations.php` names with what
 * the controller returns.
 *
 * What it reads: for every operation whose envelope is `page` or `cursor`,
 * the source text of the controller method its route names (by reflection,
 * from its first line to its last). A `page` method must call
 * `$this->paginated(` (the `ListsAcrossTenants` helper, which writes all
 * five keys) or spell all five keys as `'key' =>`; a `cursor` method must
 * spell `'next_cursor' =>` and `'per_page' =>`. It does not follow a call
 * into another method, and it reads spellings, not the response: a key
 * spelled in the method and not sent passes. The activity feed's response
 * is compared with its published schema by
 * {@see TheFeedAnswersWhatTheDescriptionPublishesTest}.
 * Operations published with the `list` envelope are not read.
 */
final class EveryPublishedPageBuildsItsMetaTest extends TestCase
{
    private const array SPELLED = [
        'page' => ["'page' =>", "'per_page' =>", "'total' =>", "'last_page' =>", "'max_per_page' =>"],
        'cursor' => ["'next_cursor' =>", "'per_page' =>"],
    ];

    #[Test]
    public function every_page_operation_builds_the_meta_its_envelope_publishes(): void
    {
        /** @var array<string, array<string, mixed>> $operations */
        $operations = require base_path('resources/openapi/operations.php');
        $read = 0;
        $wrong = [];

        foreach ($operations as $name => $operation) {
            $envelope = is_array($operation['response'] ?? null) ? ($operation['response']['envelope'] ?? null) : null;

            if (! is_string($envelope) || ! isset(self::SPELLED[$envelope])) {
                continue;
            }

            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} is not a route.");

            $action = $route->getActionName();
            [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
            $body = self::body($class, $method);
            $read++;

            if ($envelope === 'page' && str_contains($body, '$this->paginated(')) {
                continue;
            }

            $missing = array_values(array_filter(self::SPELLED[$envelope], static fn (string $key): bool => ! str_contains($body, $key)));

            if ($missing !== []) {
                $wrong[] = sprintf('%s (%s, %s::%s) does not spell %s', $name, $envelope, class_basename($class), $method, implode(' ', $missing));
            }
        }

        $this->assertGreaterThan(30, $read, 'Barely any page operations were read; the envelope reading has stopped matching.');
        $this->assertSame([], $wrong, "These operations are published with a page envelope whose meta their controller does not build:\n  ".implode("\n  ", $wrong));
    }

    /**
     * The reading is of the one method: this file spells every key (in
     * SPELLED and the method below), and the method with no meta reads as
     * spelling none of them.
     */
    #[Test]
    public function the_reading_is_of_the_method_and_not_its_file(): void
    {
        $this->assertStringNotContainsString("'total' =>", self::body(self::class, 'aMethodThatBuildsNoMeta'));
        $this->assertStringNotContainsString("'max_per_page' =>", self::body(self::class, 'aMethodThatBuildsNoMeta'));
        $this->assertStringContainsString("'total' =>", self::body(self::class, 'aMethodThatBuildsSomeMeta'));
    }

    /** @return array<string, mixed> */
    public static function aMethodThatBuildsNoMeta(): array
    {
        return ['data' => []];
    }

    /** @return array<string, mixed> */
    public static function aMethodThatBuildsSomeMeta(): array
    {
        return ['data' => [], 'meta' => ['total' => 0]];
    }

    private static function body(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, (int) $reflection->getStartLine() - 1, (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1));
    }
}
