<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * An unhandled failure is answered with the generic sentence whether or not
 * APP_DEBUG is on (X9-2, re-audit after round eight).
 *
 * Measured at 6c234dc: the renderer's last arm sent the exception's own
 * message whenever debug mode was enabled, so the operator invitation's
 * unique-length failure answered 500 `server.error` carrying the SQLSTATE,
 * the statement and its bound values. APP_DEBUG is a configuration value,
 * true in every example environment file, and one setting was all that stood
 * between a customer and the query text. The message now goes to the log
 * (the exception is reported) and never to the response, on any API route.
 */
final class AnUnhandledFailureDisclosesNothingInDebugModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function routesAndModes(): array
    {
        return [
            'customer API, debug on' => ['/api/v1/__test/crash', true],
            'customer API, debug off' => ['/api/v1/__test/crash', false],
            'operator API, debug on' => ['/api/admin/__test/crash', true],
        ];
    }

    #[Test]
    #[DataProvider('routesAndModes')]
    public function the_response_carries_the_generic_sentence_and_nothing_of_the_failure(string $uri, bool $debug): void
    {
        config()->set('app.debug', $debug);

        Route::middleware('api')->get($uri, static function (): never {
            throw new RuntimeException('SQLSTATE[22001]: String data, right truncated: 7 ERROR:  value too long for type character varying(255) (Connection: pgsql, SQL: insert into "users" ("email") values (secret@example.com))');
        });

        $response = $this->actingAs(User::factory()->create())->getJson($uri);

        $response->assertStatus(500)->assertJsonPath('error.code', 'server.error');
        $this->assertSame(__('errors.server.error'), $response->json('error.message'));

        foreach (['SQLSTATE', 'character varying', 'insert into', 'secret@example.com', 'pgsql', 'RuntimeException', 'trace'] as $internal) {
            $this->assertStringNotContainsString($internal, (string) $response->getContent());
        }
    }
}
