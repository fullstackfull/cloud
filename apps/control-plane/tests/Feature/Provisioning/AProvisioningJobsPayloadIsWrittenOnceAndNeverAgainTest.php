<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Closure;
use FilesystemIterator;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * `provisioning_jobs.payload` is written once, when the job is created, and
 * never again — and this file is what says so.
 *
 * ===========================================================================
 * WHY THE BOUND MATTERS (F-15)
 * ===========================================================================
 *
 * A VPS create reserves its provider identity before it calls, and on every
 * later attempt decides whether a machine it finds under that identity is its
 * own by comparing the machine's name, exactly, against every name a create
 * under the identity was sent with (`reserved_provider_hostnames`). The name
 * comes from the payload and from nowhere else. So the claim rule is only as
 * narrow as the payload is stable: a second writer that changes the hostname
 * — a "harmless" normalisation added to the retry path, say — grows that list
 * by a name the order never had, and the rule widens with it. The list is
 * append-only, so the job's own machine is still recognised under the name it
 * was built with; what changes is that a machine at the identity answering to
 * the new name is claimed as this build's too, whoever built it — and that
 * claim is what licenses adopting a stranger's machine as the customer's. A
 * second writer that leaves the hostname alone ("stamp the retry into the
 * payload so the screen can show it") breaks the bound silently instead:
 * nothing changes until the writer that does change the hostname arrives
 * under its cover.
 *
 * Two pins, one per link. The behavioural one lives in the F-15 band and
 * drives every operator act the platform offers, asserting the payload comes
 * out byte-identical. This one is static: it reads every PHP file under
 * `src/` and counts the places that write a column called `payload`, in six
 * shapes, against a hand-written expectation.
 *
 * ===========================================================================
 * THE EXPECTATION IS SPLIT, AND ONE HALF MUST NEVER GROW
 * ===========================================================================
 *
 * `PROVISIONING_JOBS_PAYLOAD_WRITERS` holds the one writer the bound allows.
 * If you are here because it failed with a new entry: STOP. You have written
 * a second writer of a job's payload, and the platform's defence against
 * building a customer a second machine assumes there is none. Put what you
 * wanted to record somewhere else — `result`, a column of its own — and do
 * not add a line here.
 *
 * `OTHER_PAYLOAD_SITES` holds every other match: the model's own cast
 * declaration (a match in the best-covered shape that is neither a writer
 * nor another table, and which is here so that it cannot hide a real
 * model-resident writer behind it) and a column of the same name on another
 * table. Adding a line there is legitimate when the new site genuinely
 * belongs to another table; say which in the line.
 *
 * Occurrences are counted, not collapsed per file: a second writer in a file
 * that already writes is a count of two, not a file already on the list.
 *
 * ===========================================================================
 * WHAT THE SCAN SEES
 * ===========================================================================
 *
 * Whole files, comment-stripped. It catches a second writer in any of the six
 * shapes below, anywhere under `src/`, including in a file that already
 * holds one — and nothing more than that. The raw-SQL shape reads across
 * lines, across string concatenation and across heredocs, because the only
 * thing it will not cross is a `;`; and it reads both forms a SET clause
 * assigns a column in, `set …, payload = …` and the row constructor
 * `set (…, payload) = (…)`.
 *
 * The raw-SQL window is sized by a criterion, not a feeling: at least twice
 * the widest `UPDATE … SET` clause in `src/` today. An UPDATE is any `update`
 * followed by a `set` before the next `;`, whatever the target between them —
 * an alias, a schema, `only`, quotes, a method call, an interpolation — so
 * the measure has no target form to miss; and a clause is measured from its
 * `set` to the `;` that ends its statement, not to its first `where`, which
 * may be a subquery's. That over-counts (the WHERE and RETURNING clauses and
 * the bindings are in it), which is the safe direction for a floor. The
 * widest today is the reservation statement in
 * `ProvisioningJob::reserveProviderIdentity()` — the very statement a second
 * writer is likeliest to be appended to — at 1,310 characters,
 * comment-stripped. Twice that is 2,620; the window is the next round
 * thousand, 3,000.
 *
 * What holds that: `the_raw_sql_window_is_twice_the_widest_set_clause_in_the_tree`
 * re-measures the tree on every run and fails when any UPDATE's clause
 * outgrows half the window, which is also what fails when a writer is
 * planted further past its `set` than the census can read; and
 * `the_measure_sees_a_set_clause_whatever_form_its_update_takes` drives the
 * same measure with every target form and with a subquery in the SET clause,
 * so a measure that stops seeing one fails. The window's floor is held by the
 * first of those. `a_writer_most_of_the_window_past_its_set_is_seen` reads its
 * gap from the constant, so it does not hold the constant; it holds the
 * pattern to it. Widening cost nothing: over the clean tree the raw-SQL
 * shape, row constructor included, matches no file at widths 30, 200, 1,000,
 * 2,000, 3,000, 4,000, 6,000 and 20,000, all four expected entries being
 * `array key`; and the measure finds one UPDATE in `src/`, the same one the
 * narrower measure it replaced found.
 *
 * ===========================================================================
 * WHAT THIS SCAN STILL DOES NOT SEE
 * ===========================================================================
 *
 * - **A column name held in a variable** — `[$column => $value]`. The
 *   column is not spelled at the write site, so no textual scan can see it.
 * - **A statement assembled across statements** — `$sql = 'update … set ';
 *   $sql .= 'payload = ?';`. Letting the raw-SQL shape cross a `;` is what
 *   would turn it into a false-positive machine across whole files.
 * - **A `;` in the statement's text before the column** — in a SQL string
 *   literal, `set note = 'a; b', payload = ?`, or in a SQL comment inside a
 *   heredoc. Same reason: the window stops at the first `;`, and so does the
 *   measure of how wide the window must be; telling a literal's or a
 *   comment's `;` from a statement's would take a SQL tokenizer, not a
 *   pattern.
 * - **An UPDATE whose `update` is not in its statement's text before the
 *   `set`** — the verb held in a variable, or supplied after the `set` as a
 *   `sprintf()` argument. The raw-SQL shape does not need the verb and may
 *   still see the column; the measure does, and does not measure that
 *   statement.
 * - **Mass assignment** — `$job->update($request->validated())`. The model
 *   guards only `id`, so this would reach the column, and it is undecidable
 *   from the text. There is no such call anywhere in `src/`, `app/` or
 *   `routes/` today; what bounds it is the admin surface, which offers no
 *   route that edits a job — and that is asserted below rather than stated.
 *
 * Scope is `src/`. The other places code lives were censused by hand when this
 * was written: `app/`, `routes/`, `bootstrap/`, `config/` and `database/`
 * write `payload` only in factories and seeders inserting fresh rows, and no
 * migration updates `provisioning_jobs`.
 */
final class AProvisioningJobsPayloadIsWrittenOnceAndNeverAgainTest extends TestCase
{
    /**
     * The one writer of `provisioning_jobs.payload`. Never add to this list.
     *
     * @var array<string, array<string, int>>
     */
    private const array PROVISIONING_JOBS_PAYLOAD_WRITERS = [
        'src/Modules/Provisioning/Application/Actions/CreateProvisioningJob.php' => ['array key' => 1],
    ];

    /**
     * Every other match, each with what it is.
     *
     * @var array<string, array<string, int>>
     */
    private const array OTHER_PAYLOAD_SITES = [
        // The cast declaration in casts(): not a write.
        'src/Modules/Provisioning/Infrastructure/Models/ProvisioningJob.php' => ['array key' => 1],
        // payment_webhook_events.payload, another table.
        'src/Modules/Payments/Application/Actions/IngestWebhookEvent.php' => ['array key' => 1],
        // The same table's cast declaration.
        'src/Modules/Payments/Infrastructure/Models/WebhookEvent.php' => ['array key' => 1],
    ];

    /**
     * How far the raw-SQL shape reads past a `set` to find the column. See
     * the class docblock for why this number.
     */
    private const int RAW_SQL_WINDOW = 3000;

    /**
     * Six shapes a write to a column named `payload` takes in this codebase's
     * idioms.
     *
     * @return array<string, string>
     */
    private static function writeShapes(): array
    {
        return [
            // ['payload' => …]: create(), update(), forceFill(), fill(), insert().
            'array key' => '/["\']payload["\']\s*=>/',
            // ['payload->hostname' => …]: a JSON path update.
            'json path key' => '/["\']payload->[^"\']*["\']\s*=>/',
            // $job->payload = …, $job->payload['x'] = …, $job->payload ??= ….
            'property assignment' => '/->payload\s*(?:\[[^\]]*\]\s*)*(?:=(?![=>])|\?\?=)/',
            // $job->setAttribute('payload', …).
            'setAttribute' => '/setAttribute\(\s*["\']payload["\']/',
            // update … set …, payload = … — in a string, a concatenation or a
            // heredoc — and the row-constructor form, set (…, payload) = (…).
            'raw SQL' => '/\bset\b[^;]{0,'.self::RAW_SQL_WINDOW.'}?(?:\([^();]*?["\'`]?\bpayload\b["\'`]?[^();]*\)|["\'`]?\bpayload\b["\'`]?)\s*=(?![=>])/is',
            // jsonb_set(payload, …) / jsonb_insert(payload, …): the value a SET would write.
            'jsonb function' => '/\bjsonb_(?:set|insert)\s*\(\s*["\'`]?payload\b/i',
        ];
    }

    #[Test]
    public function the_payload_has_exactly_the_writers_this_file_expects(): void
    {
        $census = $this->census();

        $writers = array_intersect_key($census, self::PROVISIONING_JOBS_PAYLOAD_WRITERS);

        $this->assertSame(
            self::PROVISIONING_JOBS_PAYLOAD_WRITERS,
            $writers,
            'The one writer of provisioning_jobs.payload no longer looks as expected.',
        );

        $expected = [...self::PROVISIONING_JOBS_PAYLOAD_WRITERS, ...self::OTHER_PAYLOAD_SITES];
        ksort($expected);

        $this->assertSame(
            $expected,
            $census,
            "Something under src/ now writes a column called `payload` that this file does not expect.\n\n"
            .'If it is provisioning_jobs.payload: STOP. A VPS create decides whether a machine it finds is its own '
            .'by the names its payload gave it, and that is only safe while the payload is written once. A second '
            ."writer is how a customer is built a second machine. Record what you wanted somewhere else.\n\n"
            ."If it is genuinely another table's column: add it to OTHER_PAYLOAD_SITES and say which table.",
        );
    }

    #[Test]
    public function the_never_grow_list_holds_one_writer(): void
    {
        $this->assertCount(1, self::PROVISIONING_JOBS_PAYLOAD_WRITERS);
        $this->assertSame([1], array_values(array_map('array_sum', self::PROVISIONING_JOBS_PAYLOAD_WRITERS)));
    }

    #[Test]
    public function the_scan_reads_the_whole_source_tree(): void
    {
        $files = iterator_to_array($this->sourceTree());

        // A census that read nothing would agree with an empty expectation.
        $this->assertGreaterThan(1000, count($files));

        // And one that read lines would miss every writer whose `set` and
        // column sit on different lines: the tree yields each file whole.
        foreach ([array_key_first(self::PROVISIONING_JOBS_PAYLOAD_WRITERS), array_key_first(self::OTHER_PAYLOAD_SITES)] as $path) {
            $this->assertArrayHasKey($path, $files);
            $this->assertSame((string) file_get_contents(base_path($path)), $files[$path], $path.' was not read whole.');
        }
    }

    #[Test]
    public function each_shape_sees_what_it_must_and_nothing_it_must_not(): void
    {
        $must = [
            'array key' => ["ProvisioningJob::query()->create(['payload' => \$p]);", '$job->forceFill(["payload" => []]);'],
            'json path key' => ["\$q->update(['payload->hostname' => 'x']);"],
            'property assignment' => ['$job->payload = $p;', "\$job->payload['hostname'] = 'x';", '$job->payload ??= [];', '$job->payload   =   [];'],
            'setAttribute' => ["\$job->setAttribute('payload', \$p);", '$job->setAttribute( "payload", $p);'],
            'raw SQL' => [
                "DB::update('update provisioning_jobs set payload = ? where id = ?', \$b);",
                "DB::update('update provisioning_jobs set attempts = ?, payload = ? where id = ?', \$b);",
                "DB::update('update provisioning_jobs set \"payload\" = ? where id = ?', \$b);",
                "DB::update('update provisioning_jobs set '\n    .'attempts = attempts + 1, '\n    .'payload = ? where id = ?', \$b);",
                "DB::update(<<<'SQL'\n    update provisioning_jobs\n    set\n        payload = payload || ?::jsonb\n    where id = ?\n    SQL, \$b);",
                "DB::update('update provisioning_jobs set (updated_at, payload) = (now(), payload || ?::jsonb) where id = ?', \$b);",
                "DB::update('update provisioning_jobs set (payload) = row(?::jsonb) where id = ?', \$b);",
                "DB::update('update provisioning_jobs set ('\n    .'updated_at, \"payload\", attempts'\n    .') = (select now(), ?::jsonb, 1) where id = ?', \$b);",
            ],
            'jsonb function' => ["DB::update('update provisioning_jobs set x = 1, result = jsonb_set(payload, ...)');"],
        ];

        $mustNot = [
            '$payload = $job->payload;',
            '$job->payload === $other;',
            "\$job->payload['hostname'] ?? null;",
            '$fn = fn () => $job->payload;',
            "DB::select('select payload from provisioning_jobs where id = ?');",
            "\$q->where('payload->hostname', 'web-01');",
            "\$settings = ['payloads' => 1];",
            "DB::update('update provisioning_jobs set (updated_at, attempts) = (now(), 1) where id = ?', \$b);",
            "DB::update('update provisioning_jobs set (updated_at, payloads) = (now(), 1) where id = ?', \$b);",
        ];

        foreach ($must as $shape => $sources) {
            foreach ($sources as $source) {
                $this->assertArrayHasKey($shape, self::shapesIn("<?php\n".$source), sprintf('The %s shape must see: %s', $shape, $source));
            }
        }

        foreach ($mustNot as $source) {
            $this->assertSame([], self::shapesIn("<?php\n".$source), 'No shape may see: '.$source);
        }
    }

    /**
     * The census's own entry point, driven with sources that exist nowhere
     * on disk.
     *
     * This is the gate on how census() reads, and it goes through census()
     * rather than through a helper beside it. An earlier version gated a
     * helper; rewriting census() itself to scan line by line — the ordinary
     * shape of an edit made to stop it holding every file in memory at once —
     * then reopened the heredoc hole with every test here still green.
     * Driven through the entry point, a census() that splits every source it
     * scans, or that reads files for itself instead of the sources it is
     * handed, fails. A census() that splits only what it reads from disk and
     * keeps what it is handed whole passes, and no test that hands it sources
     * could see that: it is exactly the case such code does not split. What
     * does see the ordinary streaming edit, one that makes `sourceTree()`
     * yield lines, is `the_scan_reads_the_whole_source_tree`.
     */
    #[Test]
    public function the_census_reads_whole_files_and_not_lines(): void
    {
        $sources = static fn (): iterable => yield from [
            'src/Planted/Heredoc.php' => "<?php\nDB::update(<<<'SQL'\n    update provisioning_jobs\n    set\n        payload = ?\n    where id = ?\n    SQL, \$bindings);\n",
            'src/Planted/Concatenated.php' => "<?php\nDB::update('update provisioning_jobs set '\n    .'attempts = attempts + 1, '\n    .'payload = ? '\n    .'where id = ?', \$bindings);\n",
            'src/Planted/Twice.php' => "<?php\n\$job->payload = \$a;\n\$other->payload = \$b;\n",
        ];

        $this->assertSame([
            'src/Planted/Concatenated.php' => ['raw SQL' => 1],
            'src/Planted/Heredoc.php' => ['raw SQL' => 1],
            'src/Planted/Twice.php' => ['property assignment' => 2],
        ], $this->census($sources));
    }

    /**
     * The window is at least twice the widest raw UPDATE's SET clause in
     * `src/`, measured now rather than remembered.
     */
    #[Test]
    public function the_raw_sql_window_is_twice_the_widest_set_clause_in_the_tree(): void
    {
        [$widest, $where] = self::widestSetClause($this->sourceTree());

        // The statement the class docblock names: if this stops being found,
        // the measurement is measuring something else.
        $this->assertSame('src/Modules/Provisioning/Infrastructure/Models/ProvisioningJob.php', $where);
        $this->assertGreaterThanOrEqual(
            2 * $widest,
            self::RAW_SQL_WINDOW,
            sprintf('A raw UPDATE in %s has a SET clause %d characters wide; the raw-SQL window must be at least twice that.', $where, $widest),
        );
    }

    /**
     * The measure above, driven with statements that exist nowhere on disk:
     * it sees the SET clause of an UPDATE whatever its target looks like, and
     * however far the clause runs.
     *
     * An earlier measure recognised only `update <one word> set`, so a wide
     * UPDATE whose table carried an alias was never measured, and a writer
     * more than the window past its `set` was seen by nothing: in the tree,
     * that measure and the census both stayed green with such a writer
     * executing. And one that stopped at the first `where` stopped inside a
     * subquery in the SET clause, short of the clause's end.
     */
    #[Test]
    public function the_measure_sees_a_set_clause_whatever_form_its_update_takes(): void
    {
        $filler = str_repeat('a = a, ', 200);

        $statements = [
            'plain' => "DB::update('update provisioning_jobs set ".$filler."payload = ? where id = ?', \$b);",
            'with an alias after as' => "DB::update('update provisioning_jobs as pj set ".$filler."payload = ? where pj.id = ?', \$b);",
            'with a bare alias' => "DB::update('update provisioning_jobs pj set ".$filler."payload = ? where pj.id = ?', \$b);",
            'schema-qualified' => "DB::update('update public.provisioning_jobs set ".$filler."payload = ? where id = ?', \$b);",
            'with only' => "DB::update('update only provisioning_jobs set ".$filler."payload = ? where id = ?', \$b);",
            'quoted' => "DB::update('update \"provisioning_jobs\" set ".$filler."payload = ? where id = ?', \$b);",
            'with the table from a method' => "DB::update('update '.\$this->getTable().' set ".$filler."payload = ? where id = ?', \$b);",
            'with the table interpolated' => 'DB::update("update {$table} set '.$filler.'payload = ? where id = ?", $b);',
            'as an upsert' => "DB::statement('insert into provisioning_jobs (id) values (?) on conflict (id) do update set ".$filler."payload = ?', \$b);",
            'with a subquery in its set clause' => "DB::update('update provisioning_jobs set updated_at = (select now() where true), ".$filler."payload = ? where id = ?', \$b);",
        ];

        $unmeasured = [];

        foreach ($statements as $form => $statement) {
            [$width] = self::widestSetClause(['src/Planted/Wide.php' => "<?php\n".$statement]);

            if ($width <= strlen($filler)) {
                $unmeasured[$form] = $width;
            }
        }

        $this->assertSame([], $unmeasured, sprintf('The measure does not reach the end of a SET clause %d characters wide.', strlen($filler)));
    }

    /**
     * A second writer appended to the reservation statement itself — the one
     * the window is sized for — is seen.
     *
     * This is the verification's own reproduction, kept: the column appended
     * to the end of the SET clause of the real statement, read out of the
     * real file, so the gap is whatever that statement's gap is today.
     */
    #[Test]
    public function a_writer_appended_to_the_reservation_statement_is_seen(): void
    {
        $path = 'src/Modules/Provisioning/Infrastructure/Models/ProvisioningJob.php';
        $source = (string) file_get_contents(base_path($path));
        $anchor = "            .'where id = ? and (reserved_cluster_id is null or reserved_cluster_id = ?) '\n";

        $this->assertSame(1, substr_count($source, $anchor), 'The reservation statement is not where this test expects it.');

        $planted = str_replace(
            $anchor,
            "            .', payload = payload || jsonb_build_object(?::text, ?::text) '\n".$anchor,
            $source,
        );

        $this->assertSame(['array key' => 1], self::shapesIn($source));
        $this->assertSame(['array key' => 1, 'raw SQL' => 1], self::shapesIn($planted));
    }

    /**
     * The pattern is held to the window: a raw-SQL shape given a narrower
     * quantifier of its own than `RAW_SQL_WINDOW` fails here, because this gap
     * is most of the window.
     *
     * Every other raw-SQL fixture in this file has a gap of a few dozen
     * characters, so without this one the pattern's reach could be cut to 30
     * and nothing here would notice. The gap is read from the constant, so
     * narrowing the constant itself is not seen here; that is
     * `the_raw_sql_window_is_twice_the_widest_set_clause_in_the_tree`'s, which
     * fails when the constant is under twice the widest clause in the tree.
     */
    #[Test]
    public function a_writer_most_of_the_window_past_its_set_is_seen(): void
    {
        $gap = self::RAW_SQL_WINDOW - 100;
        $filler = str_repeat("'.'a = a, ", intdiv($gap, 10));

        $source = "<?php\nDB::update('update provisioning_jobs set ".$filler."payload = ? where id = ?', \$b);";

        $this->assertGreaterThan(self::RAW_SQL_WINDOW - 110, strlen($filler));
        $this->assertSame(['raw SQL' => 1], self::shapesIn($source));
    }

    /**
     * What bounds mass assignment: the admin surface for provisioning jobs,
     * exactly — two reads and three acts, none of which edits a job.
     *
     * A route added here that takes a job's attributes is a mass-assignment
     * writer of the payload that no textual scan can see.
     */
    #[Test]
    public function the_admin_surface_offers_no_route_that_edits_a_job(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/provisioning')) {
                continue;
            }

            $routes[] = implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' '.$route->uri();
        }

        sort($routes);

        $this->assertSame([
            'GET api/admin/provisioning/jobs',
            'GET api/admin/provisioning/needs-review',
            'POST api/admin/provisioning/jobs/{job}/adopt',
            'POST api/admin/provisioning/jobs/{job}/repoint',
            'POST api/admin/provisioning/jobs/{job}/retry',
        ], $routes);
    }

    // ---------------------------------------------------------------------
    // The instrument
    // ---------------------------------------------------------------------

    /**
     * Every match, as file => shape => occurrences, sorted by file.
     *
     * The sources are handed in, so the gate on how this reads can drive this
     * very method; the default is every PHP file under `src/`, read whole.
     *
     * @param  ?Closure(): iterable<string, string>  $sources
     * @return array<string, array<string, int>>
     */
    private function census(?Closure $sources = null): array
    {
        $census = [];

        foreach ($sources !== null ? $sources() : $this->sourceTree() as $path => $source) {
            $shapes = self::shapesIn($source);

            if ($shapes !== []) {
                $census[$path] = $shapes;
            }
        }

        ksort($census);

        return $census;
    }

    /**
     * The widest `UPDATE … SET` clause among the sources, and where it is.
     *
     * An UPDATE is any `update` followed by a `set` before the next `;`. That
     * is the whole of Postgres's grammar for the target between them — a
     * table, schema-qualified or not, quoted or not, with or without `only`,
     * with or without an alias — and it is also whatever PHP assembles the
     * target from, a method call or an interpolation, and an upsert's
     * `do update set`. Nothing in the target needs recognising, so nothing in
     * it can be missed.
     *
     * A clause is measured from its `set` to the `;` that ends the statement,
     * not to its `where`: the first `where` may be a subquery's inside the SET
     * clause, and the `;` is where the raw-SQL shape stops reading anyway.
     * That over-counts — it includes the WHERE and RETURNING clauses and the
     * bindings — which is the safe direction for a floor.
     *
     * @param  iterable<string, string>  $sources
     * @return array{int, string}
     */
    private static function widestSetClause(iterable $sources): array
    {
        $widest = 0;
        $where = '';

        foreach ($sources as $path => $source) {
            $code = self::normalise($source);

            if (preg_match_all('/\bupdate\b[^;]*?\bset\b/i', $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $clause = substr($code, $offset + strlen($text) - 3);
                $end = strpos($clause, ';');
                $width = $end === false ? strlen($clause) : $end;

                if ($width > $widest) {
                    $widest = $width;
                    $where = $path;
                }
            }
        }

        return [$widest, $where];
    }

    /**
     * @return array<string, int> shape => occurrences, only those that occur
     */
    private static function shapesIn(string $source): array
    {
        $code = self::normalise($source);
        $found = [];

        foreach (self::writeShapes() as $shape => $pattern) {
            $count = preg_match_all($pattern, $code);

            if ($count > 0) {
                $found[$shape] = $count;
            }
        }

        return $found;
    }

    /**
     * The source with its comments removed and everything else kept.
     *
     * Strings stay, because raw SQL lives in them. Comments go so that a
     * docblock explaining a write shape is not counted as one — defensive
     * rather than operative today: measured when this was written, no file
     * under `src/` has a comment matching any shape, so over `src/` this
     * changes nothing. It would matter the day somebody documents a write
     * next to the code, which this file's own docblocks, were they under
     * `src/`, would already do.
     *
     * Newlines inside a comment are kept so that nothing after it moves
     * lines; the raw-SQL window is counted in characters, and a stripped
     * comment is at most its own newlines wide.
     */
    private static function normalise(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Every PHP file under `src/`, relative path => whole contents.
     *
     * @return \Generator<string, string>
     */
    private function sourceTree(): \Generator
    {
        $root = base_path();
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        foreach ($files as $file) {
            yield substr($file, strlen($root) + 1) => (string) file_get_contents($file);
        }
    }
}
