<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the portal tells a customer a role can do, against what the server does.
 *
 * W5.9 §7 asks for two things: that the customer-visible permission
 * descriptions still match server truth, and that no role gains access merely
 * because navigation hides or shows a control. This tests both as one claim,
 * and the direction of the test is what makes it worth writing.
 *
 * The expectations are not written here. They are read from
 * `GET /api/v1/team/roles`, which is the same list the portal renders in its
 * role matrix: each role, each capability, and `granted` true or false. The
 * test then signs in as a member holding that role and calls a real endpoint
 * that demands that capability's permission. A `granted: false` that does not
 * produce a refusal is the portal lying to a customer about what their
 * colleague can do; a `granted: true` that is refused is the portal offering
 * something the server will not honour.
 *
 * So this is not a second copy of the permission table. If the table changes,
 * the published matrix changes with it and this test follows — while still
 * failing if the server's behaviour does not.
 *
 * ## Why the endpoint map is asserted for completeness
 *
 * Nine capabilities are published. Each one below names an endpoint that
 * really demands its permission, and the test refuses to run unless every
 * published capability is covered — otherwise a capability added to the API
 * later would be described to customers and never exercised.
 *
 * ## What 403 means here, and why it is not 404
 *
 * `AuthorisesWithinAccount` runs before any lookup, so the refusal is about
 * the caller's role rather than about the row. That is the opposite of the
 * tenancy matrix next door, where 404 is required precisely so a stranger
 * learns nothing: here the caller is a genuine member of the account, they
 * know the resource exists, and the honest answer is that their role does not
 * allow it.
 */
final class TheFinalAuthorisationParityTest extends TestCase
{
    use BuildsACustomerObjectGraph;
    use RefreshDatabase;

    private Customer $customer;

    /** @var array<string, string> */
    private array $graph;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $owner = User::factory()->create(['email_verified_at' => now(), 'timezone' => 'Asia/Kuwait']);
        $this->customer->members()->create([
            'user_id' => $owner->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $this->graph = $this->objectGraphFor($this->customer, $owner);
    }

    /**
     * One live endpoint per published capability.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function endpointFor(string $capability): array
    {
        return match ($capability) {
            'view_services' => ['GET', 'vps', []],
            'manage_services' => ['POST', "vps/{$this->graph['vm']}/power", ['action' => 'reboot']],
            /*
             * The confirmation is the machine's hostname, compared with
             * hash_equals. Not the archive's id: a ULID is a string nobody can
             * verify by reading it, which is the opposite of what a
             * confirmation is for.
             */
            'end_services' => ['DELETE', "vps/{$this->graph['vm']}/backups/{$this->graph['backup']}", [
                'confirmation' => $this->graph['vm_hostname'],
            ]],
            'view_billing' => ['GET', 'invoices', []],
            'pay_invoices' => ['POST', "invoices/{$this->graph['invoice']}/wallet-credit", []],
            'manage_members' => ['POST', 'team/invitations', [
                'email' => 'parity-'.Str::lower(Str::random(6)).'@example.test',
                'role' => 'member',
            ]],
            'manage_account' => ['GET', 'account/country-currency-changes', []],
            /*
             * With the password, because minting a token asks for it — the
             * right design for a credential, and the reason this case first
             * answered 422 and told the grid nothing.
             */
            'manage_api_tokens' => ['POST', 'me/api-tokens', [
                'name' => 'parity-'.Str::lower(Str::random(6)),
                'current_password' => 'password',
            ]],
            'ask_support' => ['GET', 'support/tickets', []],
            default => throw new \LogicException(
                "No endpoint is exercised for the published capability '{$capability}'. "
                .'Add one: a capability the portal describes and nothing tests is a promise nobody checks.'
            ),
        };
    }

    #[Test]
    public function the_published_role_matrix_is_what_the_endpoints_actually_enforce(): void
    {
        $matrix = $this->publishedMatrix();

        $this->assertNotEmpty($matrix, 'The roles endpoint published nothing.');

        $disagreements = [];
        $checked = 0;
        $refusals = 0;

        foreach ($matrix as $role => $capabilities) {
            // Owner is the account's own role and cannot be granted as a
            // membership, so the matrix is exercised through a member holding
            // each assignable role plus the owner already in place.
            $user = $this->aMemberHolding($role);

            foreach ($capabilities as $capability => $granted) {
                [$method, $uri, $body] = $this->endpointFor($capability);

                $status = $this->actingAs($user)
                    ->call($method, "/api/v1/{$uri}", [], [], [], [
                        'HTTP_ACCEPT' => 'application/json',
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_IDEMPOTENCY_KEY' => 'w59-parity-'.bin2hex(random_bytes(8)),
                        'HTTP_X_LYNOMIA_CUSTOMER' => $this->customer->id,
                    ], $body === [] ? null : json_encode($body))
                    ->getStatusCode();

                $checked++;

                /*
                 * A 422 is neither an allowance nor a refusal.
                 *
                 * The FormRequest validates before the controller calls
                 * `authoriseWithinAccount`, so a body the validator rejects
                 * never reaches the role check — and reading that as "allowed"
                 * is how a grid passes while proving nothing. It happened
                 * twice on the first run, on the one endpoint that asks for a
                 * password.
                 */
                if ($status === 422) {
                    $disagreements[] = sprintf(
                        '%-14s %-18s %-6s %-52s answered 422  ← untested: the validator refused the body '
                        .'before the role was consulted',
                        $role,
                        $capability,
                        $method,
                        $uri,
                    );

                    continue;
                }

                $wasRefused = $status === 403;
                $refusals += $wasRefused ? 1 : 0;

                if ($granted === $wasRefused) {
                    $disagreements[] = sprintf(
                        '%-14s %-18s published granted=%-5s  %-6s %-52s answered %d  ← %s',
                        $role,
                        $capability,
                        $granted ? 'true' : 'false',
                        $method,
                        $uri,
                        $status,
                        $granted
                            ? 'the portal offers this and the server refuses it'
                            : 'the portal says this role cannot, and the server allowed it',
                    );
                }
            }
        }

        $this->assertSame([], $disagreements, sprintf(
            "%d of %d role and capability pairs disagree with the matrix the portal publishes:\n\n%s\n",
            count($disagreements),
            $checked,
            implode("\n", $disagreements),
        ));

        $this->assertSame(45, $checked, 'Five roles times nine capabilities. A different number means the matrix changed shape.');

        /*
         * And a spread, so the pass cannot come from a portal that refuses
         * everything or from one that refuses nothing. Owner holds all nine
         * and Member holds two, so somewhere between a fifth and a half of the
         * grid must be refusals — it is 20 of 45 today.
         */
        $this->assertGreaterThan(
            $checked / 5,
            $refusals,
            'Almost nothing was refused, so this grid would pass against a portal with no role checks at all.',
        );
        $this->assertLessThan(
            $checked / 2,
            $refusals,
            'Almost everything was refused, so this grid would pass against a portal where no role can do anything.',
        );
    }

    /**
     * Every published capability has an endpoint behind it.
     *
     * Separate from the grid because the grid would simply throw, and a
     * LogicException in the middle of a 45-case loop is a worse report than a
     * named list.
     */
    #[Test]
    public function every_capability_the_api_publishes_is_exercised_by_this_test(): void
    {
        $uncovered = [];

        foreach ($this->publishedMatrix() as $capabilities) {
            foreach (array_keys($capabilities) as $capability) {
                try {
                    $this->endpointFor($capability);
                } catch (\LogicException) {
                    $uncovered[$capability] = true;
                }
            }
        }

        $this->assertSame([], array_keys($uncovered), sprintf(
            "The API describes these capabilities to customers and nothing here exercises them:\n\n  %s\n",
            implode("\n  ", array_keys($uncovered)),
        ));
    }

    /**
     * The matrix as the portal receives it.
     *
     * @return array<string, array<string, bool>>
     */
    private function publishedMatrix(): array
    {
        $owner = $this->customer->members()
            ->where('role', CustomerRole::Owner->value)
            ->firstOrFail()
            ->user()
            ->firstOrFail();

        $response = $this->actingAs($owner)->getJson('/api/v1/team/roles', [
            'X-Lynomia-Customer' => $this->customer->id,
        ]);

        $response->assertOk();

        $matrix = [];

        foreach ($response->json('data') as $role) {
            foreach ($role['capabilities'] as $capability) {
                $matrix[$role['id']][$capability['id']] = (bool) $capability['granted'];
            }
        }

        return $matrix;
    }

    /**
     * A user holding one role inside this account.
     *
     * Owner is already in place from setUp — an account has exactly one and it
     * moves by transfer rather than by grant — so that role reuses them.
     */
    private function aMemberHolding(string $role): User
    {
        if ($role === CustomerRole::Owner->value) {
            return $this->customer->members()
                ->where('role', CustomerRole::Owner->value)
                ->firstOrFail()
                ->user()
                ->firstOrFail();
        }

        $user = User::factory()->create([
            'name' => Str::headline($role),
            'email_verified_at' => now(),
            'timezone' => 'Asia/Kuwait',
        ]);

        $this->customer->members()->create([
            'user_id' => $user->id,
            'role' => CustomerRole::from($role),
            'accepted_at' => now(),
        ]);

        return $user;
    }
}
