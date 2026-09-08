<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role as DomainRole;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Mints the API token the load harness authenticates with.
 *
 * Separate from the harness so that no shell script has to know how this
 * platform issues credentials, and separate from the seeders so that a
 * throwaway load-test account cannot appear in a development database by
 * accident.
 *
 * It refuses production outright, and it refuses any database whose name does
 * not mark it as scratch. A command that creates a login with a known token is
 * exactly the sort of convenience that must not be able to run anywhere real.
 */
final class MintPerformanceToken extends Command
{
    protected $signature = 'perf:token {--quiet-output : Print only the token}';

    protected $description = 'Create (or reuse) the load-test account and print an API token for it';

    private const string EMAIL = 'perf-harness@example.test';

    public function handle(): int
    {
        if (app()->isProduction()) {
            throw new RuntimeException('perf:token creates a login with a printed token; it must never run in production.');
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_contains($database, 'perf') && ! str_contains($database, 'test')) {
            $this->getOutput()->writeln(sprintf(
                '<error>Refusing to create a load-test login in "%s".</error>',
                $database,
            ));

            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Load Harness',
                'password' => bin2hex(random_bytes(16)),
                'email_verified_at' => now(),
                'password_changed_at' => now(),
            ],
        );

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('billing_email', self::EMAIL)->first();

        if ($customer === null) {
            /*
             * The account with the most invoices, so the list endpoints are
             * measured against a customer that actually has rows.
             *
             * Counted with a query rather than `withCount('invoices')` because
             * Customer deliberately has no invoices() relation: every read of an
             * invoice on the customer surface goes through CustomerInvoices, so
             * that an unscoped invoice query is never in anybody's hand.
             */
            $busiest = DB::table('invoices')
                ->select('customer_id')
                ->groupBy('customer_id')
                ->orderByRaw('count(*) desc')
                ->first();

            $customer = ($busiest !== null ? Customer::query()->find($busiest->customer_id) : null)
                ?? Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        }

        // The baseline platform role, without which even the catalogue refuses:
        // customer-scoped authority comes from the membership below, but
        // catalog.view is a platform permission.
        if (Role::query()->where('name', DomainRole::Customer->value)->exists()) {
            $user->syncRoles([DomainRole::Customer->value]);
        }

        $customer->members()->firstOrCreate(
            ['user_id' => $user->id],
            ['role' => CustomerRole::Owner, 'accepted_at' => now()],
        );

        $user->tokens()->where('name', 'load-harness')->delete();

        $issued = $user->createToken('load-harness');

        /*
         * The authenticated API is limited to 120 requests a minute per user by
         * default, and a token may carry its own ceiling. A load harness that
         * did not raise it would spend its run measuring the rate limiter —
         * which is a real and correct part of the platform, but not the part
         * being measured, and 429s would read as failures rather than as the
         * limiter doing its job. The ceiling is raised on this token only, in a
         * scratch database, by the mechanism the platform already provides.
         */
        $issued->accessToken->forceFill(['rate_limit_per_minute' => 1_000_000])->save();

        $token = $issued->plainTextToken;

        if ((bool) $this->option('quiet-output')) {
            $this->getOutput()->writeln($token);

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'user' => $user->email,
            'customer' => $customer->id,
            'invoices' => DB::table('invoices')->where('customer_id', $customer->id)->count(),
            'token' => $token,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
