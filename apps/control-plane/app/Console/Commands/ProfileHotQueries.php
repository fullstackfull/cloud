<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Queries\CustomerInvoices;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Queries\CustomerOrders;
use RuntimeException;

/**
 * What the customer-facing list queries cost once a table is large.
 *
 * The suite proves these queries are correct and that they do not issue one
 * statement per row. Neither says anything about what they cost against a table
 * with half a million rows in it, and that is a different failure: a plan that
 * is instant on a developer's four invoices and a sequential scan in production.
 *
 * The queries are taken from the platform's own query objects — CustomerInvoices,
 * CustomerOrders and the rest — rather than retyped here, so this measures what
 * the endpoints actually run and cannot drift from them.
 *
 * It writes into whatever database is configured, so it refuses to run in
 * production and refuses a database whose name does not say it is for this. Run
 * it against a scratch database:
 *
 *     DB_DATABASE=lynomia_perf php artisan perf:profile --customers=2000 --per-customer=50
 */
final class ProfileHotQueries extends Command
{
    protected $signature = 'perf:profile
        {--customers=500 : How many customer accounts to synthesise}
        {--per-customer=40 : Rows of each kind per customer}
        {--keep : Leave the synthesised rows in place afterwards}';

    protected $description = 'Measure the customer list queries against a large table';

    private const int CHUNK = 1_000;

    public function handle(): int
    {
        if (app()->isProduction()) {
            throw new RuntimeException('perf:profile writes millions of rows; it must never run in production.');
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_contains($database, 'perf') && ! str_contains($database, 'test')) {
            $this->errorLine(sprintf(
                'Refusing to write into "%s": name the database something containing "perf" so it cannot be mistaken for one holding real rows.',
                $database,
            ));

            return self::FAILURE;
        }

        $customers = max(1, (int) $this->option('customers'));
        $perCustomer = max(1, (int) $this->option('per-customer'));

        $this->comment(sprintf('Synthesising %d customers × %d rows…', $customers, $perCustomer));

        $customerIds = $this->seedCustomers($customers);
        $this->seedInvoices($customerIds, $perCustomer);
        $this->seedOrders($customerIds, $perCustomer);

        $subject = Customer::query()->findOrFail($customerIds[intdiv(count($customerIds), 2)]);

        $results = [
            'invoices (customer list)' => $this->explain(
                CustomerInvoices::of($subject)
                    ->orderByDesc('issued_at')
                    ->orderByDesc('id')
                    ->limit(25),
            ),
            'orders (customer list)' => $this->explain(
                CustomerOrders::of($subject)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(25),
            ),
            'invoices (open, all customers)' => $this->explain(
                Invoice::query()->where('status', 'open')->orderByDesc('due_at')->limit(25),
            ),
        ];

        $this->table(
            ['Query', 'ms', 'Rows', 'Plan', 'Sequential scans'],
            array_map(
                static fn (string $name, array $r): array => [
                    $name,
                    number_format($r['ms'], 2),
                    $r['rows'],
                    $r['node'],
                    $r['seq_scans'] === [] ? '—' : implode(', ', $r['seq_scans']),
                ],
                array_keys($results),
                array_values($results),
            ),
        );

        $counts = [
            'customers' => Customer::query()->count(),
            'invoices' => Invoice::query()->count(),
            'orders' => Order::query()->count(),
        ];

        $this->line(json_encode(['rows' => $counts, 'queries' => $results], JSON_THROW_ON_ERROR));

        if (! (bool) $this->option('keep')) {
            $this->comment('Removing the synthesised rows…');
            Customer::query()->whereIn('id', $customerIds)->forceDelete();
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function seedCustomers(int $count): array
    {
        /** @var array<string, mixed> $template */
        $template = Customer::factory()->make(['currency' => 'KWD', 'country' => 'KW'])->getAttributes();

        $ids = [];
        $batch = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::ulid();
            $ids[] = $id;

            $batch[] = array_merge($template, [
                'id' => $id,
                'billing_email' => sprintf('perf-%s@example.test', $id),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (count($batch) >= self::CHUNK) {
                DB::table('customers')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            DB::table('customers')->insert($batch);
        }

        return $ids;
    }

    /**
     * @param  list<string>  $customerIds
     */
    private function seedInvoices(array $customerIds, int $perCustomer): void
    {
        /** @var array<string, mixed> $template */
        $template = Invoice::factory()->make(['currency' => 'KWD'])->getAttributes();

        // A generated column cannot be written to.
        unset($template['amount_due_minor'], $template['customer_id'], $template['id']);

        $this->bulk('invoices', $customerIds, $perCustomer, function (string $customerId, int $n) use ($template): array {
            return array_merge($template, [
                'id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'number' => sprintf('PERF-%s-%06d', substr($customerId, -6), $n),
                'issued_at' => now()->subDays($n % 900),
                'created_at' => now()->subDays($n % 900),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<string>  $customerIds
     */
    private function seedOrders(array $customerIds, int $perCustomer): void
    {
        /** @var array<string, mixed> $template */
        $template = Order::factory()->make(['currency' => 'KWD'])->getAttributes();

        unset($template['customer_id'], $template['id']);

        $this->bulk('orders', $customerIds, $perCustomer, function (string $customerId, int $n) use ($template): array {
            return array_merge($template, [
                'id' => (string) Str::ulid(),
                'customer_id' => $customerId,
                'number' => sprintf('PERFORD-%s-%06d', substr($customerId, -6), $n),
                'idempotency_key' => null,
                'created_at' => now()->subDays($n % 900),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  list<string>  $customerIds
     * @param  callable(string, int): array<string, mixed>  $row
     */
    private function bulk(string $table, array $customerIds, int $perCustomer, callable $row): void
    {
        $batch = [];
        $written = 0;

        foreach ($customerIds as $customerId) {
            for ($n = 0; $n < $perCustomer; $n++) {
                $batch[] = $row($customerId, $n);

                if (count($batch) >= self::CHUNK) {
                    DB::table($table)->insert($batch);
                    $written += count($batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            DB::table($table)->insert($batch);
            $written += count($batch);
        }

        $this->comment(sprintf('  %s: %d rows', $table, $written));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $query
     * @return array{ms: float, rows: int, node: string, seq_scans: list<string>}
     */
    private function explain(mixed $query): array
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        /** @var list<object{'QUERY PLAN': string}> $rows */
        $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);

        /** @var array{Plan: array<string, mixed>, 'Execution Time': float} $plan */
        $plan = json_decode((string) $rows[0]->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];

        return [
            'ms' => (float) $plan['Execution Time'],
            'rows' => (int) $plan['Plan']['Actual Rows'],
            'node' => (string) $plan['Plan']['Node Type'],
            'seq_scans' => $this->sequentialScans($plan['Plan']),
        ];
    }

    /**
     * Sequential scans anywhere in the plan, named by the table they read.
     *
     * A sequential scan is not automatically wrong — on a small table it is the
     * cheapest thing there is — but on a table that grows with the customer base
     * it is the signal that an index is missing.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function sequentialScans(array $node): array
    {
        $found = [];

        if (($node['Node Type'] ?? null) === 'Seq Scan') {
            $found[] = (string) ($node['Relation Name'] ?? 'unknown');
        }

        /** @var list<array<string, mixed>> $children */
        $children = $node['Plans'] ?? [];

        foreach ($children as $child) {
            $found = array_merge($found, $this->sequentialScans($child));
        }

        return $found;
    }

    private function errorLine(string $message): void
    {
        $this->getOutput()->writeln('<error>'.$message.'</error>');
    }
}
