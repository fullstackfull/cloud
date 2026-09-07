<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Console\Commands\GenerateOpenApiSpec;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The published API description, checked against the application it describes.
 *
 * A specification is only worth having if it cannot drift. Three things are
 * asserted here, and each of them fails the build rather than producing a
 * document that quietly stops being true:
 *
 *  - every route the application registers has an entry, and every entry names
 *    a route the application registers — so the document cannot omit an
 *    endpoint, and cannot invent one;
 *  - every API resource's published fields match the schema documented for it,
 *    read from the resource classes themselves rather than from a list
 *    somebody maintains;
 *  - the committed docs/openapi.yaml is exactly what the generator produces,
 *    so a change to either the routes or the meaning must be regenerated and
 *    committed together with it.
 *
 * Structural validity — that the file is a legal OpenAPI 3.1 document — is not
 * asserted here. It is checked by `npm run openapi:lint`, which runs a real
 * validator, in CI and locally. Asserting it in PHP would mean hand-writing a
 * partial OpenAPI validator, which would be wrong in ways nobody would notice.
 */
final class OpenApiSpecificationTest extends TestCase
{
    private const string SPEC = __DIR__.'/../../../../../docs/openapi.yaml';

    #[Test]
    public function every_route_is_documented_and_every_entry_names_a_real_route(): void
    {
        /** @var array<string, mixed> $operations */
        $operations = require base_path('resources/openapi/operations.php');

        $routes = array_keys(GenerateOpenApiSpec::describableRoutes());
        $documented = array_keys($operations);

        sort($routes);
        sort($documented);

        $this->assertSame(
            $routes,
            $documented,
            "The API description and the route table disagree.\n"
            .'Undocumented routes: '.implode(', ', array_diff($routes, $documented))."\n"
            .'Imaginary operations: '.implode(', ', array_diff($documented, $routes)),
        );
    }

    #[Test]
    public function every_resources_fields_are_the_fields_its_schema_documents(): void
    {
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = require base_path('resources/openapi/schemas.php');

        $missing = [];

        foreach (self::resourceFields() as $resource => $fields) {
            $schema = $schemas[$resource] ?? null;

            if ($schema === null) {
                $missing[] = sprintf('%s has no schema', $resource);

                continue;
            }

            /** @var array<string, mixed> $properties */
            $properties = $schema['properties'] ?? [];

            $documented = array_keys($properties);
            sort($documented);
            sort($fields);

            if ($documented !== $fields) {
                $missing[] = sprintf(
                    '%s: undocumented [%s], documented but absent [%s]',
                    $resource,
                    implode(', ', array_diff($fields, $documented)),
                    implode(', ', array_diff($documented, $fields)),
                );
            }
        }

        $this->assertSame([], $missing, "The API description and the resources disagree:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function the_committed_document_is_what_the_generator_produces(): void
    {
        $this->assertFileExists(self::SPEC, 'docs/openapi.yaml is missing. Run `php artisan openapi:generate`.');

        $exit = $this->artisan('openapi:generate', ['--check' => true]);

        // Regenerated and compared rather than spot-checked. A document that is
        // "mostly current" is a document a client has already been misled by.
        $exit->assertExitCode(0);
    }

    /**
     * Every API resource and the field names its `toArray()` publishes.
     *
     * Read from the source rather than from reflection on an instance: a
     * resource needs a model to construct, and building thirty fixtures to
     * learn thirty key lists would be a slower test that failed for unrelated
     * reasons.
     *
     * @return array<string, list<string>>
     */
    private static function resourceFields(): array
    {
        $fields = [];

        foreach (glob(base_path('src/Modules/*/Http/Resources/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/public function toArray\([^)]*\): array\s*\{(.*?)\n    \}/s', $source, $match) !== 1) {
                continue;
            }

            preg_match_all("/^\s*'([a-z0-9_]+)' =>/m", $match[1], $keys);

            $name = str_replace('Resource', '', basename($file, '.php'));

            $fields[$name] = array_values(array_unique($keys[1]));
        }

        return $fields;
    }
}
