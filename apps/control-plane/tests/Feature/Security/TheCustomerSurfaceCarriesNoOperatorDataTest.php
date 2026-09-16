<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the customer API actually says, key by key.
 *
 * W5.9 §4 asks for a re-audit of every customer serialiser against a list of
 * things a customer must never be told: which hypervisor node their machine
 * sits on, which datastore holds their backup, the provider's task id, the
 * BMC's address, an operator's note, a raw gateway failure code, a retry
 * counter.
 *
 * Reading fifty-seven resource classes is the obvious way to do that and the
 * weaker one. A resource can be clean and still leak, because a resource
 * embeds other resources, a relationship arrives eager-loaded with more than
 * the parent asked for, and `meta` is assembled in the controller rather than
 * in any resource at all. So this walks the surface instead: it signs in, calls
 * every customer GET endpoint against a full object graph, and collects every
 * key at every depth of every response body. What it asserts against is what a
 * customer's browser would actually receive.
 *
 * ## The allow-list
 *
 * Eleven keys match the banned substrings legitimately, and each one carries
 * its reason below. An allow-list without reasons is a list of things somebody
 * once decided not to look at.
 *
 * It is also checked for rot: every allowed key must still be emitted
 * somewhere. An entry that stops appearing is an entry that has become a
 * licence for a future field nobody reviewed.
 */
final class TheCustomerSurfaceCarriesNoOperatorDataTest extends TestCase
{
    use BuildsACustomerObjectGraph;
    use RefreshDatabase;

    /**
     * Substrings that name operator or provider internals.
     *
     * Substrings rather than exact names, deliberately: `node_id`,
     * `compute_node`, `nodeName` and `node` are the same disclosure, and a list
     * of exact names is a list somebody works around by renaming.
     *
     * @var list<string>
     */
    private const INTERNAL = [
        'provider', 'driver', 'node', 'cluster', 'datastore', 'rack', 'bmc', 'ilo', 'ipmi',
        'credential', 'task_id', 'request_id', 'attempt', 'retry', 'poll', 'internal',
        'operator', 'audit', 'deployment', 'gateway', 'hypervisor', 'user_id', 'customer_id',
        'account_id', 'owner_id', 'secret', 'password', 'payload', 'note', 'stack', 'sql',
        'asset', 'serial', 'panel',
    ];

    /**
     * Keys that match a banned substring and are nonetheless a customer's
     * business, with the reason each one is.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'account_id' => 'The id of the customer\'s own hosting account, on that account\'s usage reading.',
        'customer_id' => 'The account\'s own id, on its own change request. A tenant knowing its own identifier is not a disclosure.',
        'decision_note' => 'The explanation an operator must write when approving or rejecting a country and currency change. Required, 3 to 500 characters, carried in the notification and rendered in the portal under a "Note" label: it is the answer to "why was this refused", and a refusal without one is worse than this match.',
        'gateway' => 'The default gateway of the subnet the address sits in. A customer configuring an interface needs it.',
        'hosting_account_id' => 'The id of the customer\'s own hosting account, on a WordPress site that lives in it.',
        'is_internal_note' => 'Shared with the operator resource, and structurally always false here: the customer resource reads only `customerVisibleMessages`, which filters the flag out. Proven below rather than asserted — see the fail-closed test.',
        'notes' => 'What the customer themselves typed into the order form. Their words, on their order.',
        'panel_type' => 'cPanel or DirectAdmin. Part of the product that was sold, which §4 names as the case to preserve.',
        'poll_after_ms' => 'How long the portal should wait before asking about this operation again. Wave 4 put it there so the client does not have to guess.',
        'retry_advice' => 'Whether a customer may safely try the thing again, in the customer\'s own terms. Not a retry counter.',
        'serial' => 'The chassis serial, which the customer reads off their own invoice and must type to confirm a reinstall.',
    ];

    #[Test]
    public function no_customer_response_carries_an_operator_or_provider_field(): void
    {
        [$customer, $user] = $this->anAccountWithOneOfEverything();
        $graph = $this->graph;

        $this->actingAs($user);

        $keys = [];
        $unreachable = [];

        foreach ($this->everyCustomerRead($graph) as $address) {
            $response = $this->getJson('/api/v1/'.$address, ['X-Lynomia-Customer' => $customer->id]);

            if ($response->getStatusCode() !== 200) {
                $unreachable[] = "{$address} answered {$response->getStatusCode()}";

                continue;
            }

            $this->collectKeys($response->json(), '', $address, $keys);
        }

        /*
         * An address that does not answer is an address whose keys were never
         * inspected, so a broken fixture would quietly shrink the audit rather
         * than fail it. Same reasoning as the own-tenant walk beside the
         * isolation matrix.
         */
        $this->assertSame([], $unreachable, sprintf(
            "%d customer addresses did not answer, so their fields were never audited:\n\n%s",
            count($unreachable),
            implode("\n", $unreachable),
        ));

        $this->assertGreaterThan(250, count($keys), 'The audited surface shrank. Addresses are not removed from this walk.');

        $leaks = [];

        foreach ($keys as $key => $addresses) {
            if (array_key_exists($key, self::ALLOWED)) {
                continue;
            }

            $lowered = strtolower($key);

            foreach (self::INTERNAL as $internal) {
                if (str_contains($lowered, $internal)) {
                    $where = array_slice($addresses, 0, 3, true);
                    $leaks[] = sprintf(
                        '%-30s matches "%s"  at %s',
                        $key,
                        $internal,
                        implode(', ', array_map(
                            static fn (string $path, string $address): string => "{$address} → {$path}",
                            array_values($where),
                            array_keys($where),
                        )),
                    );

                    break;
                }
            }
        }

        $this->assertSame([], $leaks, sprintf(
            "%d field(s) on the customer surface name operator or provider internals:\n\n%s\n\n".
            "Either the field should not be there, or it is a customer's own business and belongs in ".
            "ALLOWED with a written reason. Do not add it to ALLOWED to make this pass.\n",
            count($leaks),
            implode("\n", $leaks),
        ));

        // And the list cannot rot: everything permitted must still be real.
        $stale = array_values(array_diff(array_keys(self::ALLOWED), array_keys($keys)));

        $this->assertSame([], $stale, sprintf(
            "These keys are permitted but no longer emitted anywhere:\n\n  %s\n\n".
            'A permission for a field that no longer exists is a permission waiting to cover a '.
            "different field with the same name.\n",
            implode("\n  ", $stale),
        ));
    }

    /**
     * An operator's internal note, and a customer asking for the ticket.
     *
     * The design is fail-closed and worth proving rather than trusting:
     * `TicketResource` reads only the `customerVisibleMessages` relation, which
     * filters internal notes out, so a controller that eager-loaded the wrong
     * relation would render an empty message list rather than an operator's
     * remarks. `OperatorTicketResource` reads `messages` and sees everything.
     * That asymmetry is the whole protection.
     */
    #[Test]
    public function an_operators_internal_note_never_reaches_the_customer(): void
    {
        [$customer, $user] = $this->anAccountWithOneOfEverything();

        $secret = 'Internal: customer is on the watch list, node pve-07 is oversubscribed, do not tell them.';

        SupportMessage::factory()->create([
            'ticket_id' => $this->graph['ticket'],
            'is_internal_note' => true,
            'author_kind' => MessageAuthorKind::Operator,
            'body' => $secret,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/v1/support/tickets/{$this->graph['ticket']}", [
            'X-Lynomia-Customer' => $customer->id,
        ]);

        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString($secret, $body, 'An internal note reached the customer.');
        $this->assertStringNotContainsString('pve-07', $body, 'A node name reached the customer.');
        $this->assertStringNotContainsString('watch list', $body);

        // And the customer-visible message is still there, so the assertion
        // above is not passing because the list came back empty.
        $this->assertNotEmpty(
            $response->json('data.messages'),
            'The ticket returned no messages at all, which would make the assertions above vacuous.',
        );

        foreach ($response->json('data.messages') as $message) {
            $this->assertFalse(
                $message['is_internal_note'],
                'A message flagged as an internal note was rendered on the customer surface.',
            );
        }
    }

    /** @var array<string, string> */
    private array $graph = [];

    /**
     * @return array{0: Customer, 1: User}
     */
    private function anAccountWithOneOfEverything(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'Asia/Kuwait']);

        $customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $this->graph = $this->objectGraphFor($customer, $user);

        return [$customer, $user];
    }

    /**
     * Every customer GET endpoint that serves account data.
     *
     * @param  array<string, string>  $g
     * @return list<string>
     */
    private function everyCustomerRead(array $g): array
    {
        return [
            'me', 'me/overview', 'me/sessions', 'me/api-tokens',
            'activity', 'notifications', 'notifications/unread-count',
            'services', "services/{$g['service']}", "services/{$g['service']}/events",
            'vps', "vps/{$g['vm']}", "vps/{$g['vm']}/templates",
            "vps/{$g['vm']}/backups", "vps/{$g['vm']}/backups/{$g['backup']}",
            'dedicated', "dedicated/{$g['server']}",
            'hosting', "hosting/{$g['hosting']}", "hosting/{$g['hosting']}/usage",
            'wordpress/sites', "wordpress/sites/{$g['site']}", "wordpress/sites/{$g['site']}/operations",
            'domains', "domains/{$g['domain']}", "domains/{$g['domain']}/contacts",
            'dns/zones', "dns/zones/{$g['zone']}", "dns/zones/{$g['zone']}/records",
            'orders', "orders/{$g['order']}",
            'invoices', "invoices/{$g['invoice']}", "invoices/{$g['invoice']}/wallet-credit",
            'payments', "payments/{$g['payment']}",
            'subscriptions', "subscriptions/{$g['subscription']}",
            "subscriptions/{$g['subscription']}/plan-options",
            'wallet', 'wallet/transactions',
            'ips', "ips/{$g['assignment']}",
            "operations/{$g['operation']}",
            'support/tickets', "support/tickets/{$g['ticket']}",
            'team/members', 'team/invitations', 'team/roles',
            'catalog/products',
            'account/country-currency-changes',
        ];
    }

    /**
     * Every key at every depth, remembering where each was seen.
     *
     * Numeric keys are stepped through without becoming part of the path: what
     * matters is that `data.messages.is_internal_note` exists, not that it was
     * the second message.
     *
     * @param  array<string, array<string, string>>  $keys
     */
    private function collectKeys(mixed $node, string $path, string $address, array &$keys): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_int($key)) {
                $this->collectKeys($value, $path, $address, $keys);

                continue;
            }

            $here = $path === '' ? $key : $path.'.'.$key;
            $keys[$key][$address] = $here;

            $this->collectKeys($value, $here, $address, $keys);
        }
    }
}
