<?php

declare(strict_types=1);

namespace Tests\Architecture;

use BackedEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;
use Throwable;

/**
 * A record that has just been created already knows what state it is in.
 *
 * ---------------------------------------------------------------------------
 * The defect this exists to prevent
 * ---------------------------------------------------------------------------
 *
 * A column default declared in a migration is applied by PostgreSQL during the
 * INSERT. It is not applied to the model object that `create()` returns. Those
 * two facts are individually unremarkable and together produce a bug that is
 * invisible in every test that reloads the row:
 *
 *     $server = ManagedServer::create([...]);   // connection_state => null
 *     return new ServerResource($server);       // renders null
 *     $server->fresh()->connection_state;       // 'not_tested' — the assertion passes
 *
 * The caller that renders its own result — which is every store endpoint, every
 * action returning what it made — reads null. Depending on what the resource
 * does with it, that is a blank state on a screen or a 500 on a `->value` call
 * against null. It reproduces only on the create response and never on the
 * subsequent read, which is why it survives review and reaches an operator.
 *
 * This platform has now paid for it twice: once on a WordPress order, and once
 * on `RegisterServer` in this phase.
 *
 * ---------------------------------------------------------------------------
 * What is asserted
 * ---------------------------------------------------------------------------
 *
 * The rule is not "never use column defaults" — a default is a real safety net
 * for rows written outside Eloquent, and dropping it would make a direct INSERT
 * or a data migration produce a stateless row. The rule is that the model must
 * declare the SAME initial state, so both paths agree:
 *
 *   1. every state-shaped column with a database default is declared in the
 *      model's $attributes, with the identical value — so the two cannot drift
 *      into disagreeing about what "new" means;
 *   2. an unsaved model already reports that state, through its cast, so code
 *      reading `$model->status->something` cannot dereference null;
 *   3. a record created through its factory reports the same value in memory as
 *      the row that was persisted — the round trip the defect fails.
 *
 * Test 1 is driven from the live schema rather than a list, so a new table with
 * a defaulted state column joins the rule the day it is migrated, without
 * anybody remembering this file exists.
 */
final class ANewRecordKnowsItsOwnStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns holding the answer to "where is this thing up to". These are the
     * ones a screen renders the moment it is created, and the ones whose null
     * has no sensible rendering. A nullable timestamp or a counter defaulting
     * to zero is not in this class: null genuinely means "not yet" there.
     *
     * Two ways of recognising one, because the first version of this test used
     * only the name and missed `readiness` — a column that is a state by every
     * property except its spelling. An enum cast is the better signal: it says
     * the column's values are a closed set the code branches on, which is
     * exactly the kind whose null reaches a `->value` and throws.
     */
    private const string STATE_SHAPED = '/^(state|status)$|_(state|status)$/';

    #[Test]
    public function every_defaulted_state_column_is_also_declared_on_its_model(): void
    {
        $violations = [];

        foreach ($this->defaultedStateColumns() as [$table, $column, $default, $model]) {
            if ($model === null) {
                // A table with no model is written by something other than
                // Eloquent, so the database default is the only mechanism there
                // is and it is doing its job.
                continue;
            }

            $declared = (new ReflectionClass($model))->getDefaultProperties()['attributes'] ?? [];

            if (! array_key_exists($column, $declared)) {
                $violations[] = sprintf(
                    '%s: %s.%s defaults to %s in the database, but the model does not, '
                    ."so create() returns null for it. Add \$attributes = ['%s' => '%s'].",
                    class_basename($model), $table, $column, var_export($default, true), $column, $default,
                );

                continue;
            }

            if ($declared[$column] !== $default) {
                $violations[] = sprintf(
                    '%s: %s.%s defaults to %s in the database but %s on the model. '
                    .'A row now means different things depending on who wrote it.',
                    class_basename($model), $table, $column,
                    var_export($default, true), var_export($declared[$column], true),
                );
            }
        }

        $this->assertSame([], $violations, "Initial state that only the database knows:\n\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function an_unsaved_model_can_be_asked_for_its_state_without_dereferencing_null(): void
    {
        $violations = [];

        foreach ($this->defaultedStateColumns() as [$table, $column, , $model]) {
            if ($model === null) {
                continue;
            }

            try {
                $value = (new $model)->getAttribute($column);
            } catch (Throwable $e) {
                // Almost always a cast pointing at an enum that has no case for
                // the declared default — the two drifted apart.
                $violations[] = class_basename($model)."->{$column} threw ".$e::class.': '.$e->getMessage();

                continue;
            }

            if ($value === null) {
                $violations[] = class_basename($model)."->{$column} is null on a new instance ({$table}).";
            }
        }

        $this->assertSame([], $violations, "State unreadable before save:\n  ".implode("\n  ", $violations));
    }

    #[Test]
    public function a_created_record_reports_the_same_state_it_was_persisted_with(): void
    {
        /*
         * The round trip the defect actually fails. The two tests above are
         * static enough to be satisfied by a declaration that is wrong in
         * practice — a cast that mutates on the way to the database, a model
         * event that overwrites the attribute. This one writes a row and reads
         * it back.
         */
        $violations = [];
        $exercised = 0;

        foreach ($this->defaultedStateColumns() as [, $column, , $model]) {
            if ($model === null || ! in_array(HasFactory::class, class_uses_recursive($model), true)) {
                continue;
            }

            try {
                /** @var Model $created */
                $created = $model::factory()->create();
            } catch (Throwable) {
                // A factory needing fixtures this test has no business building.
                // The two tests above still cover the model's declaration.
                continue;
            }

            $exercised++;

            $inMemory = $created->getAttribute($column);
            $persisted = $created->fresh()?->getAttribute($column);

            if ($inMemory != $persisted) { // @phpstan-ignore notEqual.notAllowed
                $violations[] = sprintf(
                    '%s->%s is %s on the object create() returned but %s in the row it wrote.',
                    class_basename($model), $column,
                    var_export($this->readable($inMemory), true),
                    var_export($this->readable($persisted), true),
                );
            }
        }

        $this->assertSame([], $violations, "Created record disagrees with its own row:\n  ".implode("\n  ", $violations));

        // Guards the guard: if factories stop resolving, the loop above passes
        // by never running, and a green test reports on nothing.
        $this->assertGreaterThan(10, $exercised, 'Too few models were actually created for this to prove anything.');
    }

    private function readable(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * Every state-shaped column carrying a database default, with the model
     * that owns its table.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: ?class-string<Model>}>
     */
    private function defaultedStateColumns(): array
    {
        $models = $this->modelsByTable();
        $found = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            foreach (Schema::getColumns($name) as $column) {
                $model = $models[$name] ?? null;

                if (! $this->isStateLike($column['name'], $model)) {
                    continue;
                }

                $default = $column['default'];

                if ($default === null) {
                    continue;
                }

                // PostgreSQL reports a string default as "'value'::character varying".
                if (preg_match("/^'(.*)'::/", $default, $m) !== 1) {
                    continue;
                }

                $found[] = [$name, $column['name'], $m[1], $model];
            }
        }

        return $found;
    }

    /**
     * Is this column one whose initial value a caller will render?
     *
     * @param  ?class-string<Model>  $model
     */
    private function isStateLike(string $column, ?string $model): bool
    {
        if (preg_match(self::STATE_SHAPED, $column) === 1) {
            return true;
        }

        if ($model === null) {
            return false;
        }

        $cast = (new $model)->getCasts()[$column] ?? null;

        return is_string($cast) && enum_exists($cast) && is_a($cast, BackedEnum::class, true);
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private function modelsByTable(): array
    {
        $models = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }

            if (preg_match('/^(?:final\s+)?class\s+(\w+)\s+extends\s+Model\b/m', $source, $class) !== 1) {
                continue;
            }

            /** @var class-string<Model> $fqcn */
            $fqcn = $namespace[1].'\\'.$class[1];

            if (! class_exists($fqcn)) {
                continue;
            }

            $models[(new $fqcn)->getTable()] = $fqcn;
        }

        return $models;
    }
}
