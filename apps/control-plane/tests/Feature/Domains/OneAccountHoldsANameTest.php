<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rule that a name has one holder, enforced where it cannot be argued with.
 */
final class OneAccountHoldsANameTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function two_accounts_cannot_both_hold_the_same_name(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        Domain::factory()->create([
            'customer_id' => $first->getKey(),
            'name' => 'contested.test',
            'tld' => 'test',
            'state' => DomainState::Active,
        ]);

        /*
         * Application code checks this too, and application code races. The
         * database is the only place the rule holds under two requests that
         * pass their checks in the same millisecond.
         */
        $this->expectException(QueryException::class);

        Domain::factory()->create([
            'customer_id' => $second->getKey(),
            'name' => 'contested.test',
            'tld' => 'test',
            'state' => DomainState::RegistrationPending,
        ]);
    }

    #[Test]
    public function a_name_this_platform_lost_can_be_registered_again(): void
    {
        $first = Customer::factory()->create();
        $second = Customer::factory()->create();

        foreach ([DomainState::Deleted, DomainState::TransferredAway, DomainState::Failed] as $over) {
            Domain::factory()->create([
                'customer_id' => $first->getKey(),
                'name' => 'recycled.test',
                'tld' => 'test',
                'state' => $over,
            ]);
        }

        // Three dead rows for the same name, and the name still buyable. A
        // plain unique index would have made the first loss permanent.
        $again = Domain::factory()->create([
            'customer_id' => $second->getKey(),
            'name' => 'recycled.test',
            'tld' => 'test',
            'state' => DomainState::Active,
        ]);

        $this->assertTrue($again->exists);
    }

    #[Test]
    public function the_index_and_the_enum_say_the_same_thing(): void
    {
        /** @var ?object{indexdef: string} $index */
        $index = DB::selectOne(
            'select indexdef from pg_indexes where indexname = ?',
            ['domains_one_live_holder'],
        );

        $this->assertNotNull($index, 'the partial unique index is missing');

        preg_match_all("/'([a-z_]+)'/", $index->indexdef, $matches);

        $excludedByTheDatabase = $matches[1];
        sort($excludedByTheDatabase);

        $excludedByTheEnum = array_values(array_diff(
            array_map(static fn (DomainState $s): string => $s->value, DomainState::cases()),
            DomainState::thatHoldTheName(),
        ));
        sort($excludedByTheEnum);

        /*
         * The same rule is written twice — once in PHP, once in a WHERE clause
         * no PHP can see. Adding a state and forgetting the index means either
         * a name sold to two accounts or a name nobody can ever buy again, and
         * neither shows up until it does. This is the assertion that fails
         * first instead.
         */
        $this->assertSame(
            $excludedByTheEnum,
            $excludedByTheDatabase,
            'DomainState::holdsTheName() and the domains_one_live_holder index disagree',
        );
    }
}
