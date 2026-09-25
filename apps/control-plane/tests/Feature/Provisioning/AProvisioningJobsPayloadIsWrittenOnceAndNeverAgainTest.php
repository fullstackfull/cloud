<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Closure;
use Database\Seeders\RolePermissionSeeder;
use FilesystemIterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Admin\Http\Controllers\ProvisioningController;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
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
 * to two names, and the job's own machine read under the old name becomes a
 * stranger that licenses a repoint and a second build. A second writer that
 * leaves the hostname alone ("stamp the retry into the payload so the screen
 * can show it") breaks the bound silently instead: nothing changes until the
 * writer that does change the hostname arrives under its cover.
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
 * thing it will not cross is a `;`.
 *
 * The raw-SQL window is sized by a criterion, not a feeling: at least twice
 * the widest `UPDATE … SET` clause in `src/` today, which is the reservation
 * statement in `ProvisioningJob::reserveProviderIdentity()` — the very
 * statement a second writer is likeliest to be appended to. Measured when
 * this was written at 825 characters, comment-stripped, from `set` to the
 * `where` where a new column would be appended; hence 2,000. A test below
 * re-measures it on every run and fails when any raw UPDATE outgrows half the
 * window, and another fails when the window is narrowed below a fixture wide
 * enough to matter. Widening cost nothing: over the clean tree the raw-SQL
 * shape matches no file at widths 30, 200, 400, 800, 1,500, 3,000, 4,000 and
 * 6,000, all four expected entries being `array key`.
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
 * - **A `;` inside a string literal in a SET clause, before the column** —
 *   `set note = 'a; b', payload = ?`. Same reason: the window stops at the
 *   first `;`, and telling a literal's `;` from a statement's would take a
 *   SQL tokenizer, not a pattern.
 * - **Mass assignment** — `$job->update($request->validated())`. The model
 *   guards only `id`, so this would reach the column, and it is undecidable
 *   from the text. There is no such call anywhere in `src/`, `app/` or
 *   `routes/` today; what bounds it is the admin surface, which offers
 *   exactly one route that writes onto a job something a request supplies —
 *   the hosting-domain correction (F-04) — and that route is named below and
 *   driven with an overposted body, which is how it is shown to write one
 *   named column and leave the payload byte for byte as it was. That is
 *   asserted below rather than stated.
 *
 * Scope is `src/`. The other places code lives were censused by hand when this
 * was written: `app/`, `routes/`, `bootstrap/`, `config/` and `database/`
 * write `payload` only in factories and seeders inserting fresh rows, and no
 * migration updates `provisioning_jobs`.
 */
final class AProvisioningJobsPayloadIsWrittenOnceAndNeverAgainTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one writer of `provisioning_jobs.payload`. Never add to this list.
     *
     * @var array<string, array<string, int>>
     */
    private const array PROVISIONING_JOBS_PAYLOAD_WRITERS = [
        'src/Modules/Provisioning/Application/Actions/CreateProvisioningJob.php' => ['array key' => 1],
    ];

    /**
     * The one route on the provisioning admin surface that writes onto a job
     * something the request supplies: the operator's correction of the
     * domain a stopped hosting build will serve (F-04). It writes
     * `operator_named_domain`, by name, and never the payload — which is why
     * it is not a writer on the list above, and why it is driven below rather
     * than listed.
     */
    private const string THE_ONE_ROUTE_THAT_WRITES_WHAT_A_REQUEST_SUPPLIES = 'PUT api/admin/provisioning/jobs/{job}/hosting-domain';

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
    private const int RAW_SQL_WINDOW = 2000;

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
            // update … set …, payload = … — in a string, a concatenation or a heredoc.
            'raw SQL' => '/\bset\b[^;]{0,'.self::RAW_SQL_WINDOW.'}?["\'`]?\bpayload\b["\'`]?\s*=(?![=>])/is',
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
        $this->assertArrayHasKey(array_key_first(self::PROVISIONING_JOBS_PAYLOAD_WRITERS), $files);
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
     * Driven through the entry point, a census that splits the source, or
     * that reads files for itself instead of the sources it is handed, fails.
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
        $widest = 0;
        $where = '';

        foreach ($this->sourceTree() as $path => $source) {
            $code = self::normalise($source);

            if (preg_match_all('/\bupdate\s+(?:["`]?\w+["`]?\s+)?set\b/i', $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as [$text, $offset]) {
                $clause = substr($code, $offset + strlen($text) - 3);

                if (preg_match('/\bwhere\b|;/i', $clause, $end, PREG_OFFSET_CAPTURE) === 1 && $end[0][1] > $widest) {
                    $widest = $end[0][1];
                    $where = $path;
                }
            }
        }

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
     * The other direction: a window narrowed below a gap that exists is seen
     * as a failure, not as a quieter census.
     *
     * Every other raw-SQL fixture in this file has a gap of a few dozen
     * characters, so without this one the window could be cut to 30 and
     * nothing here would notice. This gap is most of the window.
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
     * exactly — two reads, three acts that take nothing from the request but
     * evidence and a reference, and ONE route that writes onto a job a value
     * the request supplies.
     *
     * A route added here that takes a job's attributes is a mass-assignment
     * writer of the payload that no textual scan can see. The one route that
     * writes what an operator typed is not in the list of routes that do not;
     * it is named on its own, pinned to the controller method it runs, and
     * driven by the test after this one, which is what makes it safe to have
     * rather than a line somebody added to make this pass.
     */
    #[Test]
    public function the_admin_surface_offers_no_route_that_edits_a_job(): void
    {
        $routes = [];
        $actions = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/provisioning')) {
                continue;
            }

            $key = implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' '.$route->uri();
            $routes[] = $key;
            $actions[$key] = $route->getActionName();
        }

        sort($routes);

        $this->assertSame([
            'GET api/admin/provisioning/jobs',
            'GET api/admin/provisioning/needs-review',
            'POST api/admin/provisioning/jobs/{job}/adopt',
            'POST api/admin/provisioning/jobs/{job}/repoint',
            'POST api/admin/provisioning/jobs/{job}/retry',
        ], array_values(array_diff($routes, [self::THE_ONE_ROUTE_THAT_WRITES_WHAT_A_REQUEST_SUPPLIES])));

        $this->assertContains(
            self::THE_ONE_ROUTE_THAT_WRITES_WHAT_A_REQUEST_SUPPLIES,
            $routes,
            'The hosting-domain correction is gone; if that is deliberate, remove it and its drive below with it.',
        );

        // Pinned to the method the drive below exercises: the same URI behind
        // a different controller method would be a writer nobody has driven.
        $this->assertSame(
            ProvisioningController::class.'@nameHostingDomain',
            $actions[self::THE_ONE_ROUTE_THAT_WRITES_WHAT_A_REQUEST_SUPPLIES],
        );
    }

    /**
     * Why the one route that writes what a request supplies is safe: it
     * writes one named column, and the payload comes out byte-identical.
     *
     * Driven with the body a mass-assignment writer would obey — a new
     * payload, a JSON-path key into it, a status, an attempt count and the
     * very column it writes, under their own names — and asserted on the row
     * as the database holds it, every column: the payload is the same bytes,
     * and the only columns that moved are `operator_named_domain`, to the
     * folded name from `domain` and not the overposted value, and
     * `updated_at`. A refused correction moves nothing at all.
     */
    #[Test]
    public function the_one_route_that_writes_a_job_writes_one_named_column_and_never_the_payload(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => Customer::factory()->create()->getKey(),
            'status' => ProvisioningJobStatus::Failed,
            'failure_class' => FailureClass::Permanent,
            'attempts' => 1,
            'max_attempts' => 3,
            'payload' => [
                'hosting_package_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
                'primary_domain' => 'as-created.example.test',
                'username' => 'ascreated',
                'contact_email' => 'owner@example.test',
            ],
        ]);

        $before = $this->rowOf($job);

        $this->actingAs($operator)
            ->putJson('/api/admin/provisioning/jobs/'.$job->id.'/hosting-domain', [
                'domain' => ' Named.Example.Test. ',
                'evidence' => 'customer confirmed the name by ticket 4411',
                'payload' => ['primary_domain' => 'overposted.example.test', 'hostname' => 'someone-elses-box'],
                'payload->primary_domain' => 'overposted.example.test',
                'status' => 'queued',
                'attempts' => 0,
                'operator_named_domain' => 'overposted.example.test',
                'reserved_provider_hostnames' => ['someone-elses-box'],
            ])
            ->assertOk()
            ->assertJsonPath('data.primary_domain', 'named.example.test')
            ->assertJsonPath('data.previous_domain', 'as-created.example.test');

        $after = $this->rowOf($job);

        $this->assertSame($before['payload'], $after['payload'], 'The hosting-domain route rewrote the job\'s payload: it is a second writer.');

        $moved = array_keys(array_filter(
            $after,
            static fn (mixed $value, string $column): bool => $value !== $before[$column],
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($moved);

        $this->assertSame(['operator_named_domain', 'updated_at'], $moved, 'The hosting-domain route wrote more of the job than its one column.');
        $this->assertSame('named.example.test', $after['operator_named_domain']);

        // And a refusal writes nothing: the same body against a job that has
        // not stopped leaves every column as it was.
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['status' => ProvisioningJobStatus::Running->value]);
        $running = $this->rowOf($job);

        $this->actingAs($operator)
            ->putJson('/api/admin/provisioning/jobs/'.$job->id.'/hosting-domain', [
                'domain' => 'other.example.test',
                'evidence' => 'customer confirmed the name by ticket 4412',
                'payload' => ['primary_domain' => 'overposted.example.test'],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.job_not_settled');

        $this->assertSame($running, $this->rowOf($job));
    }

    /**
     * Every column of the job as the database holds it, uncast: a writer that
     * re-encodes the payload without changing its meaning has still written.
     *
     * @return array<string, mixed>
     */
    private function rowOf(ProvisioningJob $job): array
    {
        return (array) DB::table('provisioning_jobs')->where('id', $job->id)->first();
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
