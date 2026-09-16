<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every customer-reachable object, addressed by a stranger.
 *
 * W5.9 §2 asks for one final adversarial matrix rather than the isolation
 * assertions scattered through the module suites, and §25 asks for it
 * executable rather than as a table in a report. This is that matrix: two real
 * accounts, a full object graph under the first, and the second account's
 * owner calling every addressable endpoint with the first account's
 * identifiers.
 *
 * ## Why the attacker is an Owner
 *
 * `AuthorisesWithinAccount` runs before any lookup, so a caller who lacks the
 * capability is refused with 403 whether or not the id names a real row. That
 * is the right order — it means a permission failure cannot be used to probe
 * for existence — but it also means a *low-permission* attacker would be
 * stopped by the permission check and this matrix would prove nothing about
 * tenancy. So the attacker owns their own account outright. Every capability
 * check passes, and the only thing left standing between them and another
 * tenant's row is the tenancy scope itself.
 *
 * Role-based refusal is a different question and has its own matrix, in
 * TheFinalAuthorisationParityTest.
 *
 * ## Why 404 and not 403
 *
 * A stranger must not learn that an id names something real. Every lookup
 * starts from the acting customer — `CustomerVirtualMachines::of($acting)
 * ->whereKey($id)->firstOrFail()` and its siblings — so a foreign id simply
 * is not in the result set and the answer is indistinguishable from an id that
 * never existed. 403 would answer a question the caller did not get to ask.
 *
 * ## Why a 422 is recorded as a failure of the matrix
 *
 * A FormRequest runs before the controller, so a malformed body is refused
 * before anything is looked up. That refusal is correct, but it tells us
 * nothing about tenancy: the case did not reach the scope. So every write
 * below sends a body the platform would otherwise accept, and a 422 here means
 * the case is untested rather than safe. It fails.
 *
 * ## Why the own-tenant walk is in the same file
 *
 * A matrix asserting "not 2xx" everywhere would pass just as happily against
 * a portal where every one of these routes was broken for everybody. The
 * second test walks the same addresses as their real owner and requires a
 * success, so the matrix cannot be satisfied by a portal that answers nothing.
 */
final class TheFinalTenantIsolationMatrixTest extends TestCase
{
    use BuildsACustomerObjectGraph;
    use RefreshDatabase;

    /**
     * Object, action, and the address that reaches it.
     *
     * Keyed by what a reader would call the thing, because the failure output
     * is the deliverable: "backup / delete" is a row in a report, and
     * `DELETE /vps/{vm}/backups/{backup}` is the evidence for it.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
     */
    private function matrix(array $victim, array $attacker): array
    {
        $cases = [
            // ── Compute ──────────────────────────────────────────────────
            ['vps', 'read', 'GET', "vps/{$victim['vm']}", []],
            ['vps', 'read templates', 'GET', "vps/{$victim['vm']}/templates", []],
            ['vps', 'read console', 'GET', "vps/{$victim['vm']}/console", []],
            ['vps', 'act (power)', 'POST', "vps/{$victim['vm']}/power", ['action' => 'reboot']],
            ['vps', 'act (reinstall)', 'POST', "vps/{$victim['vm']}/reinstall", [
                'confirm_hostname' => $victim['vm_hostname'],
            ]],

            ['dedicated', 'read', 'GET', "dedicated/{$victim['server']}", []],
            /*
             * `cycle`, not `reboot`. A chassis is power-cycled through its BMC
             * and its verbs are on/off/cycle; a machine is rebooted by its
             * hypervisor. Two enums, deliberately — and sending the wrong verb
             * got a 422 that told this matrix nothing about tenancy.
             */
            ['dedicated', 'act (power)', 'POST', "dedicated/{$victim['server']}/power", ['action' => 'cycle']],
            ['dedicated', 'act (reinstall)', 'POST', "dedicated/{$victim['server']}/reinstall", [
                'confirm_serial' => $victim['server_serial'],
                'os_profile' => $victim['os_profile'],
            ]],

            // ── Backups, including the nested child of an owned parent ───
            ['backup', 'list', 'GET', "vps/{$victim['vm']}/backups", []],
            ['backup', 'create', 'POST', "vps/{$victim['vm']}/backups", []],
            ['backup', 'read', 'GET', "vps/{$victim['vm']}/backups/{$victim['backup']}", []],
            ['backup', 'delete', 'DELETE', "vps/{$victim['vm']}/backups/{$victim['backup']}", ['confirmation' => 'delete']],
            ['backup', 'act (keep)', 'POST', "vps/{$victim['vm']}/backups/{$victim['backup']}/keep", []],
            ['backup', 'act (restore)', 'POST', "vps/{$victim['vm']}/backups/{$victim['backup']}/restore", [
                'confirmation' => $victim['vm_hostname'],
            ]],
            ['backup', 'browse files', 'GET', "vps/{$victim['vm']}/backups/{$victim['backup']}/files", []],
            ['backup', 'list file restores', 'GET', "vps/{$victim['vm']}/backups/{$victim['backup']}/file-restores", []],

            // §3 — the parent is the attacker's own; only the child is foreign.
            ['backup', 'read foreign child of own parent', 'GET',
                "vps/{$attacker['vm']}/backups/{$victim['backup']}", []],
            ['backup', 'delete foreign child of own parent', 'DELETE',
                "vps/{$attacker['vm']}/backups/{$victim['backup']}", ['confirmation' => 'delete']],
            ['backup', 'restore foreign child of own parent', 'POST',
                "vps/{$attacker['vm']}/backups/{$victim['backup']}/restore",
                ['confirmation' => $attacker['vm_hostname']]],

            // ── Hosting and WordPress ───────────────────────────────────
            ['hosting', 'read', 'GET', "hosting/{$victim['hosting']}", []],
            ['hosting', 'read usage', 'GET', "hosting/{$victim['hosting']}/usage", []],
            ['hosting', 'act (sso)', 'POST', "hosting/{$victim['hosting']}/sso", []],

            ['wordpress', 'read', 'GET', "wordpress/sites/{$victim['site']}", []],
            ['wordpress', 'read operations', 'GET', "wordpress/sites/{$victim['site']}/operations", []],
            ['wordpress', 'read push impact', 'GET', "wordpress/sites/{$victim['site']}/push/impact", []],
            ['wordpress', 'act (staging)', 'POST', "wordpress/sites/{$victim['site']}/staging", []],
            ['wordpress', 'act (clone)', 'POST', "wordpress/sites/{$victim['site']}/clones", [
                'domain' => 'clone-'.bin2hex(random_bytes(4)).'.test',
            ]],
            ['wordpress', 'act (push)', 'POST', "wordpress/sites/{$victim['site']}/push", [
                'confirmation' => $victim['site_domain'],
            ]],

            // ── Domains ─────────────────────────────────────────────────
            ['domain', 'read', 'GET', "domains/{$victim['domain']}", []],
            ['domain', 'read contacts', 'GET', "domains/{$victim['domain']}/contacts", []],
            ['domain', 'update contacts', 'PUT', "domains/{$victim['domain']}/contacts", [
                'registrant' => $this->aRegistrant(),
            ]],
            ['domain', 'update nameservers', 'PUT', "domains/{$victim['domain']}/nameservers", [
                'nameservers' => ['ns1.example.test', 'ns2.example.test'],
            ]],
            ['domain', 'update auto-renew', 'PUT', "domains/{$victim['domain']}/auto-renew", ['auto_renew' => false]],
            ['domain', 'update transfer lock', 'PUT', "domains/{$victim['domain']}/transfer-lock", ['locked' => false]],
            ['domain', 'act (authorisation code)', 'POST', "domains/{$victim['domain']}/authorisation-code", []],
            ['domain', 'act (renew)', 'POST', "domains/{$victim['domain']}/renewals", ['quote_id' => (string) Str::ulid()]],
            ['domain', 'act (redeem)', 'POST', "domains/{$victim['domain']}/redemptions", ['quote_id' => (string) Str::ulid()]],

            // ── DNS, including the nested record ────────────────────────
            ['dns zone', 'read', 'GET', "dns/zones/{$victim['zone']}", []],
            ['dns zone', 'delete', 'DELETE', "dns/zones/{$victim['zone']}", ['confirm_zone_name' => $victim['zone_name']]],
            ['dns zone', 'export', 'GET', "dns/zones/{$victim['zone']}/export", []],
            ['dns zone', 'plan import', 'POST', "dns/zones/{$victim['zone']}/import/plan", [
                'text' => "@ IN A 203.0.113.10\n",
            ]],
            ['dns zone', 'apply import', 'POST', "dns/zones/{$victim['zone']}/import", [
                'text' => "@ IN A 203.0.113.10\n",
                // Shaped, not correct: the fingerprint names a plan this
                // caller never made. A wrong fingerprint must be refused
                // *after* the zone is found not to be theirs, so the address
                // still has to answer 404.
                'fingerprint' => str_repeat('a', 64),
            ]],
            ['dns record', 'list', 'GET', "dns/zones/{$victim['zone']}/records", []],
            ['dns record', 'create', 'POST', "dns/zones/{$victim['zone']}/records", [
                'type' => 'A', 'name' => 'probe', 'content' => '203.0.113.11',
            ]],
            ['dns record', 'update', 'PATCH', "dns/zones/{$victim['zone']}/records/{$victim['record']}", [
                'content' => '203.0.113.12',
            ]],
            ['dns record', 'delete', 'DELETE', "dns/zones/{$victim['zone']}/records/{$victim['record']}", []],

            // §3 — own zone, foreign record.
            ['dns record', 'update foreign child of own parent', 'PATCH',
                "dns/zones/{$attacker['zone']}/records/{$victim['record']}", ['content' => '203.0.113.13']],
            ['dns record', 'delete foreign child of own parent', 'DELETE',
                "dns/zones/{$attacker['zone']}/records/{$victim['record']}", []],

            // ── Money ───────────────────────────────────────────────────
            ['order', 'read', 'GET', "orders/{$victim['order']}", []],
            ['order', 'act (cancel)', 'POST', "orders/{$victim['order']}/cancel", []],

            ['invoice', 'read', 'GET', "invoices/{$victim['invoice']}", []],
            ['invoice', 'act (start payment)', 'POST', "invoices/{$victim['invoice']}/payments", [
                'method' => 'card',
            ]],
            ['invoice', 'read wallet credit', 'GET', "invoices/{$victim['invoice']}/wallet-credit", []],
            ['invoice', 'act (apply wallet credit)', 'POST', "invoices/{$victim['invoice']}/wallet-credit", []],

            ['payment', 'read', 'GET', "payments/{$victim['payment']}", []],

            ['subscription', 'read', 'GET', "subscriptions/{$victim['subscription']}", []],
            ['subscription', 'read plan options', 'GET', "subscriptions/{$victim['subscription']}/plan-options", []],
            ['subscription', 'act (cancel)', 'POST', "subscriptions/{$victim['subscription']}/cancel", []],
            ['subscription', 'act (change plan)', 'POST', "subscriptions/{$victim['subscription']}/plan", [
                'plan_id' => $victim['plan'],
                'price_id' => $victim['price'],
            ]],

            // ── Addresses ───────────────────────────────────────────────
            ['ip assignment', 'read', 'GET', "ips/{$victim['assignment']}", []],
            ['ip assignment', 'update rdns', 'PUT', "ips/{$victim['assignment']}/rdns", [
                'hostname' => 'taken.example.test',
            ]],

            // ── Work in progress ────────────────────────────────────────
            ['operation', 'read', 'GET', "operations/{$victim['operation']}", []],
            ['service', 'read', 'GET', "services/{$victim['service']}", []],
            ['service', 'read events', 'GET', "services/{$victim['service']}/events", []],

            // ── Support ─────────────────────────────────────────────────
            ['ticket', 'read', 'GET', "support/tickets/{$victim['ticket']}", []],
            ['ticket', 'act (reply)', 'POST', "support/tickets/{$victim['ticket']}/replies", [
                'body' => 'Adding myself to a conversation I am not part of.',
            ]],
            ['ticket', 'act (close)', 'POST', "support/tickets/{$victim['ticket']}/close", []],
            ['attachment', 'read', 'GET', "support/attachments/{$victim['attachment']}", []],

            // ── Notifications ───────────────────────────────────────────
            ['notification', 'act (mark read)', 'POST', "notifications/{$victim['notification']}/read", []],

            // ── Team, sessions, tokens ──────────────────────────────────
            ['team member', 'update role', 'PATCH', "team/members/{$victim['member']}", ['role' => 'member']],
            ['team member', 'delete', 'DELETE', "team/members/{$victim['member']}", []],
            ['invitation', 'delete', 'DELETE', "team/invitations/{$victim['invitation']}", []],
            ['invitation', 'act (resend)', 'POST', "team/invitations/{$victim['invitation']}/resend", []],
            ['session', 'delete', 'DELETE', "me/sessions/{$victim['session']}", []],
            ['api token', 'delete', 'DELETE', "me/api-tokens/{$victim['token']}", []],

            // ── Account change requests ─────────────────────────────────
            ['country change', 'act (reanalyse)', 'POST',
                "account/country-currency-changes/{$victim['change']}/reanalyse", []],
            ['country change', 'act (withdraw)', 'POST',
                "account/country-currency-changes/{$victim['change']}/withdraw", []],
        ];

        return $cases;
    }

    #[Test]
    public function no_object_of_one_account_answers_to_the_owner_of_another(): void
    {
        [$victimCustomer, $victimUser] = $this->anAccount('Victim');
        [$attackerCustomer, $attackerUser] = $this->anAccount('Attacker');

        $victim = $this->objectGraphFor($victimCustomer, $victimUser);
        $attacker = $this->objectGraphFor($attackerCustomer, $attackerUser);

        $this->actingAs($attackerUser);

        $failures = [];
        $checked = 0;

        foreach ($this->matrix($victim, $attacker) as [$object, $action, $method, $uri, $body]) {
            $response = $this->call($method, "/api/v1/{$uri}", [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                // Without this the body below is never parsed, every field
                // reads as missing, and the validator answers 422 before the
                // request reaches the tenancy scope. Which is exactly what the
                // 422 branch of `why()` is for — it caught this.
                'CONTENT_TYPE' => 'application/json',
                /*
                 * Sent on every case, not only where it is read. Six of these
                 * endpoints require an Idempotency-Key and refuse without one
                 * before any lookup happens — charge, renew, provision,
                 * reinstall, change plan. A key here is what lets those cases
                 * reach the tenancy scope at all; where it is not read it is
                 * ignored.
                 */
                'HTTP_IDEMPOTENCY_KEY' => 'w59-matrix-'.bin2hex(random_bytes(8)),
                'HTTP_X_LYNOMIA_CUSTOMER' => $attackerCustomer->id,
            ], $body === [] ? null : json_encode($body));

            $checked++;
            $actual = $response->getStatusCode();

            if ($actual !== 404) {
                $failures[] = sprintf(
                    '%-26s %-38s %-6s %-64s expected 404, got %d%s',
                    $object,
                    $action,
                    $method,
                    $uri,
                    $actual,
                    $this->why($actual, $response),
                );
            }
        }

        $this->assertGreaterThan(70, $checked, 'The matrix shrank. Cases are not removed from it.');

        $this->assertSame([], $failures, sprintf(
            "%d of %d cross-tenant cases did not answer 404.\n\n%s\n\n".
            'A 2xx is a cross-tenant breach. A 403 means the refusal discloses that the id is real. '.
            'A 422 means a validator refused the body before anything was looked up, so the case never '.
            'reached the tenancy scope and proves nothing — fix the body, not the assertion. A 500 is a '.
            "scoping query that broke on a foreign row.\n",
            count($failures),
            $checked,
            implode("\n", $failures),
        ));
    }

    /**
     * The same addresses, as the account that owns them.
     *
     * Without this the matrix above is satisfied by a portal in which none of
     * these routes work for anybody, which is the failure mode of every
     * negative-only security test. Only the reads are walked: the writes have
     * side effects and their own tests, and a read that answers proves the
     * address is live and the scope admits its owner.
     */
    #[Test]
    public function the_same_addresses_answer_their_own_account(): void
    {
        [$customer, $user] = $this->anAccount('Owner');
        $own = $this->objectGraphFor($customer, $user);

        $this->actingAs($user);

        $reads = [
            "vps/{$own['vm']}",
            "vps/{$own['vm']}/backups",
            "vps/{$own['vm']}/backups/{$own['backup']}",
            "dedicated/{$own['server']}",
            "hosting/{$own['hosting']}",
            "wordpress/sites/{$own['site']}",
            "domains/{$own['domain']}",
            "domains/{$own['domain']}/contacts",
            "dns/zones/{$own['zone']}",
            "dns/zones/{$own['zone']}/records",
            "orders/{$own['order']}",
            "invoices/{$own['invoice']}",
            "invoices/{$own['invoice']}/wallet-credit",
            "payments/{$own['payment']}",
            "subscriptions/{$own['subscription']}",
            "ips/{$own['assignment']}",
            "operations/{$own['operation']}",
            "services/{$own['service']}",
            "support/tickets/{$own['ticket']}",
        ];

        $unreachable = [];

        foreach ($reads as $uri) {
            $response = $this->getJson("/api/v1/{$uri}", [
                'X-Lynomia-Customer' => $customer->id,
            ]);

            if ($response->getStatusCode() !== 200) {
                $unreachable[] = sprintf('%-64s got %d', $uri, $response->getStatusCode());
            }
        }

        $this->assertSame([], $unreachable, sprintf(
            "%d addresses did not answer their own account:\n\n%s\n\n".
            'Every one of these is also a cross-tenant case in the matrix above. An address that '.
            "answers nobody makes that matrix vacuous, which is why this test exists.\n",
            count($unreachable),
            implode("\n", $unreachable),
        ));
    }

    private function why(int $status, TestResponse $response): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => '  ← CROSS-TENANT BREACH',
            $status === 403 => '  ← discloses existence',
            $status === 422 => '  ← untested: validator refused the body first',
            $status >= 500 => '  ← '.substr((string) $response->getContent(), 0, 160),
            default => '',
        };
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function anAccount(string $name): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create([
            'name' => $name,
            'email_verified_at' => now(),
            'timezone' => 'Asia/Kuwait',
        ]);

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function aRegistrant(): array
    {
        return [
            'name' => 'Nadia Al-Sabah',
            'email' => 'nadia@example.test',
            'phone' => '+96522334455',
            'address_line_one' => '12 Gulf Road',
            'city' => 'Kuwait City',
            'country' => 'KW',
        ];
    }
}
