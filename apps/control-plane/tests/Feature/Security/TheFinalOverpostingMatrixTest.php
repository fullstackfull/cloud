<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Infrastructure\DomainRegistrarFactory;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every customer write, sent the fields the platform never offered.
 *
 * W5.9 §6 asks for a final write audit. CustomerWritesResistOverpostingTest
 * already covers the profile, a role change, a new ticket and a new token; this
 * covers the rest of the writes that create or change a row a customer owns,
 * and it covers them the way §6 describes: submit the legitimate payload plus
 * `customer_id`, `status`, `total_minor`, `verified`, `created_at` and the rest,
 * then read the stored row rather than the response. A field accepted and then
 * hidden from the response would pass a body assertion and still be a
 * privilege escalation.
 *
 * ## Why this list of endpoints
 *
 * Seventeen of the ninety-five models declare `$guarded = []`, which makes
 * every column mass-assignable, and seven of those seventeen are things a
 * customer writes: DnsRecord, DnsZone, Domain, DomainContact, WordPressSite,
 * Notification and CountryCurrencyChange. For those seven there is no
 * model-layer defence at all — the validator's allow-list is the entire
 * control. That is a perfectly sound design and it is also the design with the
 * least margin for a mistake, so it is the design that gets tested from the
 * outside.
 *
 * ## The sentinel sweep
 *
 * Each case sends poison whose every value is recognisable — a ULID belonging
 * to another account the caller also owns, the integer 424242, the string
 * `lyn-overpost-sentinel`, the date 1999-01-01 — and then asserts that no
 * column of the written row holds any of them. One assertion per case that
 * catches any field name the case thought to send, rather than a list of
 * per-column assertions that only catches the ones somebody remembered.
 *
 * The second account is the point of the ULID sentinel: the caller is a genuine
 * Owner of both accounts, so `customer_id` naming the other one is a request
 * the platform could satisfy without any authorisation error at all. If it were
 * honoured, the row would simply be filed under the wrong account.
 *
 * Status-like fields get a named assertion as well, because a sentinel cannot
 * express them: `status` has to be a value the enum accepts to be interesting,
 * and a value the enum accepts might also be the value the platform legitimately
 * chose. So those are checked against what the platform should have set.
 */
final class TheFinalOverpostingMatrixTest extends TestCase
{
    use BuildsACustomerObjectGraph;
    use RefreshDatabase;

    private const SENTINEL_TEXT = 'lyn-overpost-sentinel';

    private const SENTINEL_INT = 424242;

    private const SENTINEL_DATE = '1999-01-01T00:00:00+00:00';

    private Customer $customer;

    private Customer $otherAccount;

    private User $user;

    /** @var array<string, string> */
    private array $graph;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'timezone' => 'Asia/Kuwait',
        ]);

        $this->customer = $this->accountOwnedByTheUser();
        $this->otherAccount = $this->accountOwnedByTheUser();

        /*
         * The fake registrar keeps its portfolio in memory unless it is given
         * a file, and the factory builds a fresh one for every call — so a
         * holding registered through one instance is invisible to the instance
         * the next request resolves. A path makes the fake behave like the
         * separate system of record it is standing in for.
         */
        config()->set('domains.fake.state_path', storage_path('framework/testing/w59-registrar-'.uniqid().'.json'));

        $this->graph = $this->objectGraphFor($this->customer, $this->user);

        $this->actingAs($this->user);
    }

    /**
     * The fields §6 lists, with sentinel values.
     *
     * @return array<string, mixed>
     */
    private function poison(): array
    {
        return [
            // Ownership.
            'customer_id' => $this->otherAccount->id,
            'account_id' => $this->otherAccount->id,
            'user_id' => $this->otherAccount->id,
            'owner_id' => $this->otherAccount->id,
            'service_id' => $this->otherAccount->id,
            'requested_by_user_id' => $this->otherAccount->id,

            // Where the platform decided to put the thing.
            'provider_id' => self::SENTINEL_TEXT,
            'provider' => self::SENTINEL_TEXT,
            'node_id' => self::SENTINEL_TEXT,
            'cluster_id' => self::SENTINEL_TEXT,
            'datastore' => self::SENTINEL_TEXT,

            // Money. Authority for all of it is the server's.
            'price' => self::SENTINEL_INT,
            'price_minor' => self::SENTINEL_INT,
            'subtotal_minor' => self::SENTINEL_INT,
            'discount_minor' => self::SENTINEL_INT,
            'tax_minor' => self::SENTINEL_INT,
            'total_minor' => self::SENTINEL_INT,
            'amount_minor' => self::SENTINEL_INT,
            'amount_paid_minor' => self::SENTINEL_INT,
            'balance_minor' => self::SENTINEL_INT,
            'recurring_amount_minor' => self::SENTINEL_INT,

            // Internal state and flags.
            'verified' => true,
            'email_verified_at' => self::SENTINEL_DATE,
            'is_internal_note' => true,
            'permissions' => ['*'],
            'abilities' => ['*'],
            'poll_count' => self::SENTINEL_INT,
            'attempts' => self::SENTINEL_INT,
            'retention_days' => self::SENTINEL_INT,

            // Time. A row that lets a caller pick its own timestamps is a row
            // whose history can be rewritten.
            'created_at' => self::SENTINEL_DATE,
            'updated_at' => self::SENTINEL_DATE,
        ];
    }

    #[Test]
    public function a_new_dns_zone_cannot_choose_its_account_its_state_or_its_provider(): void
    {
        $name = 'overpost-'.Str::lower(Str::random(6)).'.test';

        $response = $this->write('POST', 'dns/zones', [
            'name' => $name,
            ...$this->poison(),
            'state' => 'active',
        ]);

        $this->assertLessThan(300, $response, 'Creating a zone '.$this->refusal($response));

        $row = $this->row('dns_zones', ['name' => $name]);

        $this->assertNoSentinelLanded('dns_zones', $row);
        $this->assertSame($this->customer->id, $row['customer_id'], 'A zone was filed under an account the form did not name.');

        /*
         * Compared against a zone created with no poison at all, rather than
         * against a literal state.
         *
         * Asserting `!== 'active'` here was wrong about the product: ClaimZone
         * writes `DnsState::Pending`, and then — with the queue running inline,
         * as it does under test — the publish job reaches the provider and the
         * zone legitimately arrives `active`. The assertion failed on correct
         * behaviour and would have been "fixed" by weakening it.
         *
         * What overposting actually means here is that the poison made a
         * difference. So the comparison is against the platform's own answer to
         * the same request without it.
         */
        $clean = $this->row('dns_zones', ['name' => $this->aCleanZone()]);

        $this->assertSame(
            $clean['state'],
            $row['state'],
            'A zone created with poison reached a different state than one created without it.',
        );
    }

    /**
     * The one body field on this form that is genuinely an identifier.
     *
     * `POST /dns/zones` reads `service_id` from the body — a zone can be
     * attached to the service it serves — so unlike the rest of the poison
     * this field is *meant* to be there. What must not happen is it naming
     * another account's service, and the caller owns a second account, so the
     * id they send is real and theirs. `ownServiceId()` resolves it through the
     * acting customer and answers null when it does not belong, which is the
     * §3 rule applied to a write rather than to a path.
     */
    #[Test]
    public function a_new_zone_cannot_be_attached_to_a_service_in_another_account(): void
    {
        $foreignService = Service::factory()->create([
            'customer_id' => $this->otherAccount->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
        ]);

        $name = 'attach-'.Str::lower(Str::random(6)).'.test';

        $response = $this->write('POST', 'dns/zones', [
            'name' => $name,
            'service_id' => $foreignService->id,
        ]);

        $this->assertLessThan(300, $response, 'Creating a zone '.$this->refusal($response));

        $row = $this->row('dns_zones', ['name' => $name]);

        $this->assertSame($this->customer->id, $row['customer_id']);
        $this->assertNotSame(
            (string) $foreignService->id,
            (string) ($row['service_id'] ?? ''),
            'A zone in one account was attached to a service in another.',
        );
    }

    #[Test]
    public function a_new_dns_record_cannot_choose_its_zone_or_its_state(): void
    {
        /*
         * Inside the zone and more than one label. The zone refuses both a
         * single-label name and a name outside itself, and both refusals are
         * correct — they are just not what this case is asking about.
         */
        $label = 'overpost-'.Str::lower(Str::random(6)).'.'.$this->graph['zone_name'];

        $response = $this->write('POST', "dns/zones/{$this->graph['zone']}/records", [
            'type' => 'A',
            'name' => $label,
            'content' => '203.0.113.42',
            ...$this->poison(),
            'dns_zone_id' => $this->foreignZone(),
            'state' => 'active',
        ]);

        $this->assertLessThan(300, $response, 'Creating a record '.$this->refusal($response));

        $row = $this->row('dns_records', ['name' => $label]);

        $this->assertNoSentinelLanded('dns_records', $row);
        $this->assertSame(
            $this->graph['zone'],
            $row['dns_zone_id'],
            'A record was written into a zone named in the body rather than the one named in the address.',
        );
        $this->assertNotSame('active', $row['state'], 'A record claimed the provider already has it.');
    }

    #[Test]
    public function editing_a_dns_record_cannot_move_it_to_another_zone(): void
    {
        $foreign = $this->foreignZone();

        // The graph's record carries the factory's default name, which sits
        // outside this zone; the endpoint refuses that before reaching
        // anything this case is about. So the case edits a record it made.
        $record = $this->aRecordInsideTheZone();

        $response = $this->write('PATCH', "dns/zones/{$this->graph['zone']}/records/{$record}", [
            'content' => '203.0.113.43',
            ...$this->poison(),
            'dns_zone_id' => $foreign,
        ]);

        $this->assertLessThan(300, $response, 'Editing a record '.$this->refusal($response));

        $row = $this->row('dns_records', ['id' => $record]);

        $this->assertNoSentinelLanded('dns_records', $row);
        $this->assertSame($this->graph['zone'], $row['dns_zone_id'], 'A record was moved into another account\'s zone by a body field.');
    }

    #[Test]
    public function updating_a_registrant_cannot_reassign_the_domain(): void
    {
        $this->makeTheRegistrarHoldTheDomain();

        $response = $this->write('PUT', "domains/{$this->graph['domain']}/contacts", [
            'registrant' => [
                'name' => 'Nadia Al-Sabah',
                'email' => 'nadia@example.test',
                'phone' => '+96522334455',
                'address_line_one' => '12 Gulf Road',
                'city' => 'Kuwait City',
                'country' => 'KW',
                // Inside the nested object as well as beside it: a validator
                // that allow-lists `registrant.*` by prefix rather than by
                // name would take these.
                ...$this->poison(),
            ],
            ...$this->poison(),
        ]);

        $this->assertLessThan(300, $response, 'Updating contacts '.$this->refusal($response));

        $domain = $this->row('domains', ['id' => $this->graph['domain']]);
        $contact = $this->row('domain_contacts', ['domain_id' => $this->graph['domain'], 'role' => 'registrant']);

        $this->assertNoSentinelLanded('domains', $domain);
        $this->assertNoSentinelLanded('domain_contacts', $contact);
        $this->assertSame($this->customer->id, $domain['customer_id'], 'A domain changed hands through the contacts form.');
    }

    #[Test]
    public function toggling_auto_renew_cannot_change_anything_else_about_the_domain(): void
    {
        $before = $this->row('domains', ['id' => $this->graph['domain']]);

        $response = $this->write('PUT', "domains/{$this->graph['domain']}/auto-renew", [
            'auto_renew' => false,
            ...$this->poison(),
            'state' => 'active',
            'expires_at' => '2099-01-01T00:00:00+00:00',
            'term_years' => 10,
        ]);

        $this->assertLessThan(300, $response, "Toggling auto-renew answered {$response}.");

        $after = $this->row('domains', ['id' => $this->graph['domain']]);

        $this->assertNoSentinelLanded('domains', $after);
        $this->assertFalse((bool) $after['auto_renew'], 'The one field the form does offer was not applied.');

        /*
         * Everything except the flag and the timestamps must be byte-identical.
         * Stated as a diff rather than as a list of columns, so a column added
         * to this table later is covered without anybody remembering to add it.
         */
        $changed = array_keys(array_diff_assoc(
            array_diff_key($before, array_flip(['auto_renew', 'updated_at'])),
            array_diff_key($after, array_flip(['auto_renew', 'updated_at'])),
        ));

        $this->assertSame([], $changed, sprintf(
            'A form offering one field changed: %s',
            implode(', ', $changed),
        ));
    }

    #[Test]
    public function a_new_support_reply_cannot_be_an_internal_note_or_be_attributed_to_an_operator(): void
    {
        $body = 'My machine is unreachable. '.self::SENTINEL_TEXT.'-body';

        $response = $this->write('POST', "support/tickets/{$this->graph['ticket']}/replies", [
            'body' => $body,
            ...$this->poison(),
            'author_kind' => 'operator',
            'ticket_id' => $this->graph['ticket'],
        ]);

        $this->assertLessThan(300, $response, 'Replying '.$this->refusal($response));

        $row = $this->row('support_messages', ['body' => $body]);

        $this->assertFalse((bool) $row['is_internal_note'], 'A customer wrote a message the operator surface treats as private.');
        $this->assertSame('customer', $row['author_kind'], 'A customer\'s reply was attributed to an operator.');
        $this->assertNotSame($this->otherAccount->id, $row['author_id'] ?? null);
    }

    #[Test]
    public function a_new_invitation_cannot_be_an_owner_or_belong_to_another_account(): void
    {
        $email = 'invited-'.Str::lower(Str::random(6)).'@example.test';

        $response = $this->write('POST', 'team/invitations', [
            'email' => $email,
            'role' => 'member',
            ...$this->poison(),
            'accepted_at' => self::SENTINEL_DATE,
            'expires_at' => '2099-01-01T00:00:00+00:00',
            'sent_count' => self::SENTINEL_INT,
        ]);

        $this->assertLessThan(300, $response, 'Inviting '.$this->refusal($response));

        $row = $this->row('customer_invitations', ['email' => $email]);

        $this->assertNoSentinelLanded('customer_invitations', $row);
        $this->assertSame($this->customer->id, $row['customer_id'], 'An invitation was issued into another account.');
        $this->assertSame('member', $row['role'], 'An invitation was issued at a role the form did not send.');
        $this->assertNull($row['accepted_at'], 'An invitation arrived already accepted.');
    }

    #[Test]
    public function a_new_change_request_cannot_arrive_already_approved(): void
    {
        /*
         * The object graph opens one of these, and an account may have only
         * one open at a time — a rule worth keeping, so the case withdraws it
         * rather than working around it.
         */
        $withdrawn = $this->write('POST', "account/country-currency-changes/{$this->graph['change']}/withdraw", []);
        $this->assertLessThan(300, $withdrawn, 'Could not withdraw the open request '.$this->refusal($withdrawn));

        $response = $this->write('POST', 'account/country-currency-changes', [
            'country' => 'AE',
            'currency' => 'AED',
            'reason' => 'The business is relocating to Dubai.',
            ...$this->poison(),
            'state' => 'scheduled',
            'decided_at' => self::SENTINEL_DATE,
            'decision_note' => 'Approved by me, myself.',
            'decided_by_user_id' => $this->user->id,
            'impact' => ['facts' => [], 'blockers' => [], 'warnings' => []],
        ]);

        $this->assertLessThan(300, $response, 'Requesting a change '.$this->refusal($response));

        $row = $this->row('customer_country_currency_changes', ['customer_id' => $this->customer->id, 'to_currency' => 'AED']);

        $this->assertNoSentinelLanded('customer_country_currency_changes', $row);
        $this->assertNotSame('scheduled', $row['state'], 'A customer scheduled their own currency change.');
        $this->assertNull($row['decided_at'], 'A change request arrived already decided.');
        $this->assertNull($row['decision_note'], 'A customer wrote the operator\'s decision note.');
        $this->assertNull($row['decided_by_user_id'], 'A customer recorded themselves as the decider.');
    }

    #[Test]
    public function a_new_backup_cannot_choose_its_machine_its_datastore_or_its_retention(): void
    {
        /*
         * A machine the hypervisor has confirmed. The platform refuses to back
         * up a machine with no provider identifier — there is nothing out
         * there to snapshot yet — and it refuses with a 500 that says so,
         * deliberately, because nothing about the request is wrong and the
         * customer cannot fix it. The graph's machine is deliberately
         * unconfirmed, so this case confirms one.
         */
        // Confirmed by the hypervisor and placed on a node: the platform
        // refuses to snapshot a machine that is neither.
        DB::table('virtual_machines')
            ->where('id', $this->graph['vm'])
            ->update([
                'provider_id' => '9001',
                'node_id' => ComputeNode::factory()->create([
                    'cluster_id' => DB::table('virtual_machines')->where('id', $this->graph['vm'])->value('cluster_id'),
                ])->id,
            ]);

        /*
         * And a datastore for the cluster it runs on. Which PBS host on which
         * disks in which building is a physical decision the application
         * cannot infer, so it is configuration, and without it the platform
         * refuses — correctly — before reaching anything this case is about.
         */
        $cluster = DB::table('virtual_machines')->where('id', $this->graph['vm'])->value('cluster_id');
        $slug = DB::table('compute_clusters')->where('id', $cluster)->value('slug');
        config()->set('backups.datastores.'.$slug, 'pbs-test-01');

        $before = DB::table('backups')->count();

        $response = $this->write('POST', "vps/{$this->graph['vm']}/backups", [
            ...$this->poison(),
            'state' => 'succeeded',
            'verified' => true,
            'size_bytes' => self::SENTINEL_INT,
        ]);

        $this->assertLessThan(300, $response, 'Requesting a backup '.$this->refusal($response));
        $this->assertGreaterThan($before, DB::table('backups')->count(), 'No backup row was written.');

        $row = (array) DB::table('backups')->orderByDesc('created_at')->first();

        $this->assertSame($this->customer->id, $row['customer_id'], 'A backup was filed under another account.');
        $this->assertSame($this->graph['vm'], $row['virtual_machine_id'], 'A backup was attached to a machine named in the body.');
        /*
         * The fields, not the lifecycle.
         *
         * `state` and `verified` are decided by the provider flow, which under
         * test runs inline and legitimately reaches `succeeded` — asserting
         * `!== 'succeeded'` failed on correct behaviour, the same way it did
         * for a newly claimed zone. Comparing against a poison-free request
         * would settle it, and cannot be done here: the fake's task-id counter
         * lives in one instance while the factory builds a new instance per
         * request, so every request is handed `UPID:fake:1` and a second
         * backup in the same transaction collides on the real unique index
         * over (provider, provider_task_id). That is a limit of the fake, not
         * of the platform — Proxmox task ids are unique — and the lifecycle
         * itself is what tests/Feature/Backups covers.
         *
         * What is asserted here is what a body could have reached: who the
         * backup belongs to, which machine it is of, where it was written, how
         * big it says it is, and how long it claims to be kept.
         */
        $this->assertNotSame(self::SENTINEL_INT, (int) $row['size_bytes'], 'A customer declared how large their own backup is.');
        $this->assertNotSame(self::SENTINEL_INT, (int) $row['retention_days'], 'A customer chose how long the platform keeps their backup.');
        $this->assertNotSame(self::SENTINEL_INT, (int) $row['poll_count'], 'A customer wrote the platform\'s own counter.');
        $this->assertNotSame(self::SENTINEL_TEXT, $row['datastore']);
        $this->assertNotSame(self::SENTINEL_TEXT, $row['provider']);
    }

    #[Test]
    public function setting_reverse_dns_cannot_reassign_the_address(): void
    {
        $before = $this->row('ip_assignments', ['id' => $this->graph['assignment']]);

        $response = $this->write('PUT', "ips/{$this->graph['assignment']}/rdns", [
            'hostname' => 'mail.example.test',
            ...$this->poison(),
            'is_primary' => false,
            'released_at' => self::SENTINEL_DATE,
        ]);

        $this->assertLessThan(300, $response, 'Setting reverse DNS '.$this->refusal($response));

        $after = $this->row('ip_assignments', ['id' => $this->graph['assignment']]);

        $this->assertNoSentinelLanded('ip_assignments', $after);
        $this->assertSame($before, $after, 'A reverse DNS form changed the address assignment itself.');
    }

    #[Test]
    public function notification_preferences_cannot_be_set_for_somebody_else(): void
    {
        $response = $this->write('PUT', 'me/notification-preferences', [
            /*
             * Operational on email, because that is a combination the product
             * lets a customer turn off. Security and billing cannot be: those
             * messages carry obligations, and the endpoint refuses rather than
             * accepting a setting it will not honour. Correct, and not what
             * this case is asking about.
             */
            'category' => 'operational',
            'channel' => 'email',
            'enabled' => false,
            ...$this->poison(),
        ]);

        $this->assertLessThan(300, $response, 'Saving preferences '.$this->refusal($response));

        $rows = DB::table('notification_preferences')->get()->map(fn ($r): array => (array) $r)->all();

        $this->assertNotEmpty($rows, 'No preference row was written, so nothing was proved.');

        foreach ($rows as $row) {
            $this->assertNoSentinelLanded('notification_preferences', $row);
            /*
             * Keyed on the user rather than the account — a person's choice
             * about their own inbox follows them between the accounts they
             * belong to — so `user_id` is the field worth checking here.
             */
            $this->assertSame(
                $this->user->id,
                $row['user_id'],
                'A preference was saved against somebody else.',
            );
        }
    }

    // ── plumbing ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     */
    private string $lastBody = '';

    /**
     * @param  array<string, mixed>  $body
     */
    private function write(string $method, string $uri, array $body): int
    {
        $response = $this->call($method, "/api/v1/{$uri}", [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'w59-overpost-'.bin2hex(random_bytes(8)),
            'HTTP_X_LYNOMIA_CUSTOMER' => $this->customer->id,
        ], json_encode($body));

        // Kept so a refusal explains itself. A case that cannot write proves
        // nothing about overposting, and "answered 422" without the reason is
        // an afternoon of guessing at field names.
        $this->lastBody = substr((string) $response->getContent(), 0, 900);

        return $response->getStatusCode();
    }

    private function refusal(int $status): string
    {
        return sprintf(
            "answered %d; the case proves nothing unless the write happened.\n\n%s\n",
            $status,
            $this->lastBody,
        );
    }

    /**
     * @param  array<string, mixed>  $where
     * @return array<string, mixed>
     */
    private function row(string $table, array $where): array
    {
        $row = DB::table($table)->where($where)->first();

        $this->assertNotNull($row, sprintf(
            'No row in %s matching %s. The write did not happen, so the case proves nothing.',
            $table,
            json_encode($where),
        ));

        return (array) $row;
    }

    /**
     * No column of this row holds a value the caller sent.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertNoSentinelLanded(string $table, array $row): void
    {
        $landed = [];

        foreach ($row as $column => $value) {
            $matched = match (true) {
                $value === null => false,
                is_bool($value) => false,
                (string) $value === self::SENTINEL_TEXT => 'the text sentinel',
                (string) $value === (string) self::SENTINEL_INT => 'the integer sentinel',
                (string) $value === $this->otherAccount->id => 'the other account\'s id',
                str_contains((string) $value, '1999-01-01') => 'the date sentinel',
                str_contains((string) $value, self::SENTINEL_TEXT) => 'the text sentinel',
                default => false,
            };

            if ($matched !== false) {
                $landed[] = "{$table}.{$column} holds {$matched}: ".json_encode($value);
            }
        }

        $this->assertSame([], $landed, sprintf(
            "A field the platform never offered was written:\n\n  %s\n",
            implode("\n  ", $landed),
        ));
    }

    private function accountOwnedByTheUser(): Customer
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $customer->members()->create([
            'user_id' => $this->user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return $customer;
    }

    /**
     * Register the graph's domain with the fake registrar.
     *
     * The fake keeps its own portfolio, which is the point of it: a row in
     * `domains` is the platform's record, and `assertHeld()` asks the registrar
     * whether the name is really theirs. A fixture that writes only the row
     * gets a refusal from the provider, exactly as it would if the platform's
     * record and the registrar's had drifted apart — honest behaviour, and a
     * reminder that the DB row is not the authority for a name.
     */
    private function makeTheRegistrarHoldTheDomain(): void
    {
        $name = (string) DB::table('domains')->where('id', $this->graph['domain'])->value('name');

        app(DomainRegistrarFactory::class)
            ->make(FakeDomainRegistrarProvider::NAME)
            ->register(new RegistrationRequest(
                name: $name,
                termYears: 1,
                contacts: [DomainContactRole::Registrant->value => new ContactDetails(
                    name: 'Existing Registrant',
                    email: 'existing@example.test',
                    phone: '+96500000000',
                    addressLineOne: '1 Test Street',
                    city: 'Kuwait City',
                    country: 'KW',
                )],
            ));
    }

    private string $cleanZoneName = '';

    /**
     * The same request with none of the poison, so the poisoned result has
     * something truthful to be compared against.
     */
    private function aCleanZone(): string
    {
        if ($this->cleanZoneName !== '') {
            return $this->cleanZoneName;
        }

        $this->cleanZoneName = 'clean-'.Str::lower(Str::random(6)).'.test';
        $this->write('POST', 'dns/zones', ['name' => $this->cleanZoneName]);

        return $this->cleanZoneName;
    }

    /** A record this zone will actually accept, created through the API. */
    private function aRecordInsideTheZone(): string
    {
        $name = 'edit-'.Str::lower(Str::random(6)).'.'.$this->graph['zone_name'];

        $status = $this->write('POST', "dns/zones/{$this->graph['zone']}/records", [
            'type' => 'A',
            'name' => $name,
            'content' => '203.0.113.50',
        ]);

        $this->assertLessThan(300, $status, 'Could not create a record to edit '.$this->refusal($status));

        return (string) $this->row('dns_records', ['name' => $name])['id'];
    }

    /**
     * A zone in the caller's *other* account: a real id they may legitimately
     * reach, which is what makes `dns_zone_id` in a body worth refusing.
     */
    private function foreignZone(): string
    {
        return (string) DB::table('dns_zones')->insertGetId([
            'id' => (string) Str::ulid(),
            'customer_id' => $this->otherAccount->id,
            'name' => 'other-'.Str::lower(Str::random(6)).'.test',
            'state' => 'pending',
            'provider' => 'fake',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');
    }
}
