<?php

declare(strict_types=1);

namespace Tests\Feature\Dns;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcting a record before the provider has taken it.
 *
 * A customer adds an A record, sees it listed as pending, notices the address
 * has a digit wrong, and corrects it. That is the most ordinary sequence in the
 * module and it answered 500.
 *
 * Two tables in `DnsState` disagreed. `isEditable()` named five states a record
 * can be edited in — Active, Failed, Indeterminate, Pending and NeedsReview —
 * and `ChangeRecord` checks exactly that before doing anything. But `allowed()`
 * lists which states each state may move to, `ChangeRecord` always moves a
 * record to Pending, and three of those five had no Pending in their list. So
 * the guard passed and the transition then threw
 * IllegalDnsTransitionException, which answers 500 and whose own docblock says
 * "every one of these is a bug in this module rather than something a customer
 * did". It was right about that.
 *
 * ChangeRecord's comment had already stated the intended behaviour — "a record
 * that never reached the provider goes back to pending from wherever it was;
 * one that is live has to go through pending too, and the enum allows exactly
 * those moves" — so the enum was the table that was wrong, not the action.
 *
 * ## Why all three states matter, not just Pending
 *
 * Pending is the common one: every newly created record is pending until the
 * publish job runs, which in production is a queue hop away rather than
 * instant.
 *
 * Indeterminate is the one that mattered more. It means the provider did not
 * answer, and Wave 4's rule is that indeterminate is not failure and must not
 * be retried automatically. A customer looking at a record stuck in that state
 * had no way to correct it: the edit that a person deliberately asks for is
 * not an automatic retry, and it was the only move available to them.
 *
 * NeedsReview means somebody has looked. `allowed()` already let it go back to
 * Active, so letting a customer's correction put it through the provider again
 * is the same permission by a safer route.
 */
final class EditingARecordThatHasNotPublishedYetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: DnsState}>
     */
    public static function statesTheProductCallsEditable(): array
    {
        return array_map(
            static fn (DnsState $state): array => [$state],
            array_values(array_filter(
                DnsState::cases(),
                static fn (DnsState $state): bool => $state->isEditable(),
            )),
        );
    }

    #[Test]
    #[DataProvider('statesTheProductCallsEditable')]
    public function a_record_in_any_state_the_product_calls_editable_can_be_edited(DnsState $state): void
    {
        [$customer, $user] = $this->anAccount();

        $zone = DnsZone::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'edit-'.Str::lower(Str::random(6)).'.test',
            'state' => DnsState::Active,
        ]);

        $record = DnsRecord::factory()->create([
            'dns_zone_id' => $zone->id,
            'name' => 'www.'.$zone->name,
            'content' => '203.0.113.10',
            'state' => $state,
        ]);

        $this->actingAs($user);

        $response = $this->patchJson(
            "/api/v1/dns/zones/{$zone->id}/records/{$record->id}",
            ['content' => '203.0.113.11'],
            ['X-Lynomia-Customer' => $customer->id],
        );

        $this->assertLessThan(500, $response->getStatusCode(), sprintf(
            "Editing a record in the %s state answered %d.\n\n%s\n\n".
            '`DnsState::isEditable()` says a record in this state may be edited, so either that is '.
            'wrong or `allowed()` is missing a move to Pending. A 500 means the two tables disagree '.
            "and the customer is being shown a server error for something they are permitted to do.\n",
            $state->value,
            $response->getStatusCode(),
            substr((string) $response->getContent(), 0, 400),
        ));

        $response->assertOk();

        $stored = (array) DB::table('dns_records')->where('id', $record->id)->first();

        $this->assertSame('203.0.113.11', $stored['content'], 'The edit was accepted and then not applied.');
        $this->assertSame(
            DnsState::Pending->value,
            $stored['state'],
            'An edited record must be pending: it carries a value the provider has not taken yet, '
            .'and a screen must not show it as live.',
        );
    }

    /**
     * The two tables, compared directly.
     *
     * The test above proves the symptom through the API. This one states the
     * invariant the symptom came from, so a state added to `isEditable()` later
     * without a matching move fails here, next to the table, rather than in a
     * DNS feature test somebody has to trace back.
     */
    #[Test]
    public function every_editable_state_can_reach_pending(): void
    {
        $contradictions = [];

        foreach (DnsState::cases() as $state) {
            if (! $state->isEditable()) {
                continue;
            }

            if (! $state->canBecome(DnsState::Pending)) {
                $contradictions[] = $state->value;
            }
        }

        $this->assertSame([], $contradictions, sprintf(
            'isEditable() calls these states editable, and an edit re-publishes a record by moving '.
            "it to Pending, which allowed() forbids from here:\n\n  %s\n\n".
            "Editing a record in one of these states answers 500.\n",
            implode("\n  ", $contradictions),
        ));
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function anAccount(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'Asia/Kuwait']);

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }
}
