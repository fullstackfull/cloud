<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Console\Application\Actions\AuthoriseConsoleConnection;
use Lynomia\Modules\Console\Domain\Contracts\ConsoleUpstreamResolver;
use Lynomia\Modules\Console\Domain\Exceptions\ConsoleConnectionRefusedException;
use Lynomia\Modules\Console\Domain\Exceptions\ConsoleUpstreamUnavailableException;
use Lynomia\Modules\Console\Domain\ValueObjects\AuthorisedConsoleConnection;
use Lynomia\Modules\Console\Domain\ValueObjects\ConsoleUpstream;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Everything a console connection has to prove, and what happens when it
 * cannot.
 *
 * The permit is the only credential the gateway sees: it is reachable by
 * anyone who can open a socket to it, and it has no session, no cookie and no
 * caller identity beyond what the permit says. So these tests are the
 * platform's whole answer to "can somebody else open a console on my server".
 *
 * Every refusal must look identical from outside. The tests assert the reason
 * against the audit trail, which is the only place the difference is allowed
 * to exist.
 */
final class AuthoriseConsoleConnectionTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Service $service;

    private VirtualMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->getKey()]);

        $this->customer = Customer::factory()->create();
        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'vps',
        ]);

        $this->machine = VirtualMachine::factory()
            ->onNode($node, 700)
            ->forService($this->service)
            ->create();

        // A resolver that answers, so the tests below are about authorisation
        // rather than about reaching a hypervisor.
        $this->app->bind(ConsoleUpstreamResolver::class, ReachableUpstream::class);
    }

    #[Test]
    public function a_valid_permit_opens_a_console_and_is_recorded(): void
    {
        $session = $this->issue();

        $authorised = $this->authorise($session->id, (string) $session->token);

        $this->assertSame((string) $this->machine->getKey(), $authorised->virtualMachineId);
        $this->assertSame('upstream.internal', $authorised->upstream->host);

        $entry = AuditEntry::query()->where('action', AuditAction::ConsolePermitRedeemed->value)->sole();
        $this->assertSame((string) $this->customer->getKey(), $entry->customer_id);

        // The trail records where it dialled and never what it dialled with.
        $this->assertSame('upstream.internal', $entry->context['upstream_host'] ?? null);
        $this->assertArrayNotHasKey('headers', $entry->context);
        $this->assertStringNotContainsString('ticket', json_encode($entry->context, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_permit_works_exactly_once(): void
    {
        /*
         * The property the whole design rests on. A permit travels in a URL —
         * a browser's WebSocket API cannot set headers — so it will end up in
         * a history, a proxy log, or a support chat. Single use is what makes
         * that survivable.
         */
        $session = $this->issue();

        $this->authorise($session->id, (string) $session->token);

        $this->assertRefused('invalid_permit', fn () => $this->authorise($session->id, (string) $session->token));
    }

    #[Test]
    public function an_expired_permit_is_refused(): void
    {
        $session = $this->issue();

        $this->travel(ConsoleSessionStore::TTL_SECONDS + 1)->seconds();

        $this->assertRefused('invalid_permit', fn () => $this->authorise($session->id, (string) $session->token));
    }

    #[Test]
    public function a_wrong_token_is_refused_and_does_not_burn_the_permit(): void
    {
        /*
         * The session id travels in the URL and the token does not, so anyone
         * who sees a URL knows an id. If a wrong token consumed the permit,
         * they could destroy a customer's console access as fast as it is
         * issued without ever guessing the secret.
         */
        $session = $this->issue();

        try {
            $this->authorise($session->id, 'not-the-token');
            $this->fail('A wrong token was accepted.');
        } catch (ConsoleConnectionRefusedException $e) {
            $this->assertSame('invalid_permit', $e->reason);
        }

        // Still good for the customer who owns it.
        $authorised = $this->authorise($session->id, (string) $session->token);
        $this->assertSame((string) $this->machine->getKey(), $authorised->virtualMachineId);
    }

    #[Test]
    public function a_permit_for_one_machine_does_not_open_another(): void
    {
        /*
         * The customer owns both machines, so nothing about ownership stops
         * this — only the binding does. A gateway that trusted the machine id
         * in the URL would hand a console on the wrong server to somebody who
         * legitimately holds a permit.
         */
        $other = VirtualMachine::factory()
            ->onNode(ComputeNode::query()->firstOrFail(), 701)
            ->forService($this->service)
            ->create();

        $session = $this->issue();

        $this->assertRefused('machine_mismatch', fn () => $this->authorise(
            $session->id,
            (string) $session->token,
            claimedMachineId: (string) $other->getKey(),
        ));
    }

    #[Test]
    public function a_permit_stops_working_the_moment_the_service_is_suspended(): void
    {
        /*
         * Permits live for a minute and a suspension can land inside it. A
         * console is root access on a machine the platform has just decided to
         * cut off, so the answer is no — the same refusal POST /power gives,
         * arriving one layer later.
         */
        $session = $this->issue();

        $this->service->update(['status' => ServiceStatus::Suspended]);

        $this->assertRefused('service_not_active', fn () => $this->authorise($session->id, (string) $session->token));
    }

    #[Test]
    public function a_permit_for_a_machine_that_has_gone_is_refused(): void
    {
        $session = $this->issue();

        $this->machine->delete();

        $this->assertRefused('machine_gone', fn () => $this->authorise($session->id, (string) $session->token));
    }

    #[Test]
    public function a_hypervisor_that_will_not_say_where_the_console_is_ends_the_connection(): void
    {
        $this->app->bind(ConsoleUpstreamResolver::class, UnavailableUpstream::class);

        $session = $this->issue();

        $this->assertRefused('upstream_unavailable', fn () => $this->authorise($session->id, (string) $session->token));
    }

    #[Test]
    public function a_peer_that_hammers_the_gateway_is_stopped(): void
    {
        /*
         * The token is 32 random bytes, so the limit is not what makes
         * guessing hopeless — it is what makes the attempt cheap to stop and
         * visible in the trail rather than a line in a load graph.
         */
        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                $this->authorise('unknown-'.$attempt, 'nope');
            } catch (ConsoleConnectionRefusedException) {
                // Every one of these is refused; the limit is what changes.
            }
        }

        $this->assertRefused('rate_limited', fn () => $this->authorise('unknown-final', 'nope'));
    }

    #[Test]
    public function every_refusal_says_the_same_thing_to_the_caller(): void
    {
        /*
         * The gateway is reachable by anyone who can open a socket. A refusal
         * that explained itself would let them tell a live session id from a
         * dead one, or confirm that a machine id exists.
         */
        $messages = [];

        foreach ([['unknown', 'token'], [$this->issue()->id, 'wrong-token']] as [$id, $token]) {
            try {
                $this->authorise($id, $token);
            } catch (ConsoleConnectionRefusedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1]);
        $this->assertStringNotContainsString('expired', strtolower($messages[0]));
        $this->assertStringNotContainsString('unknown', strtolower($messages[0]));
    }

    #[Test]
    public function a_refusal_is_recorded_with_what_the_caller_claimed(): void
    {
        // A pattern of wrong machine ids from one peer is an enumeration
        // attempt, and it is only visible if the claim is written down.
        try {
            $this->authorise('unknown', 'token', claimedMachineId: 'somebody-elses-machine');
        } catch (ConsoleConnectionRefusedException) {
            // Expected.
        }

        $entry = AuditEntry::query()->where('action', AuditAction::ConsolePermitRefused->value)->sole();

        $this->assertSame('invalid_permit', $entry->context['reason'] ?? null);
        $this->assertSame('somebody-elses-machine', $entry->context['claimed_virtual_machine_id'] ?? null);
        $this->assertSame('198.51.100.7', $entry->context['peer'] ?? null);
    }

    private function issue(): ConsoleSession
    {
        return app(ConsoleSessionStore::class)->issue(
            virtualMachineId: (string) $this->machine->getKey(),
            customerId: (string) $this->customer->getKey(),
            userId: null,
            id: (string) Str::ulid(),
            token: rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
        );
    }

    private function authorise(
        string $sessionId,
        string $token,
        ?string $claimedMachineId = null,
        string $peer = '198.51.100.7',
    ): AuthorisedConsoleConnection {
        return app(AuthoriseConsoleConnection::class)->execute(
            sessionId: $sessionId,
            token: $token,
            claimedMachineId: $claimedMachineId ?? (string) $this->machine->getKey(),
            peer: $peer,
        );
    }

    /**
     * Run something that must be refused, and check why.
     *
     * The reason is asserted twice on purpose: once on the exception, which is
     * what the gateway acts on, and once on the audit trail, which is the only
     * place the difference between refusals is allowed to be visible. The
     * message itself is deliberately identical for all of them — see the test
     * above.
     */
    private function assertRefused(string $reason, callable $attempt): void
    {
        try {
            $attempt();

            $this->fail(sprintf('Expected a refusal for "%s" and the connection was authorised.', $reason));
        } catch (ConsoleConnectionRefusedException $e) {
            $this->assertSame($reason, $e->reason);
        }

        $entry = AuditEntry::query()
            ->where('action', AuditAction::ConsolePermitRefused->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'A refusal was not recorded.');
        $this->assertSame($reason, $entry->context['reason'] ?? null);
    }
}

/**
 * An upstream that is always there, so the tests are about authorisation.
 */
final class ReachableUpstream implements ConsoleUpstreamResolver
{
    public function resolve(ConsoleSession $session): ConsoleUpstream
    {
        return new ConsoleUpstream(
            host: 'upstream.internal',
            port: 8006,
            path: '/console',
            tls: true,
            // A credential the trail must never carry.
            headers: ['Authorization' => 'PVEAPIToken=secret-ticket'],
        );
    }
}

/**
 * A hypervisor that will not say where the console is.
 */
final class UnavailableUpstream implements ConsoleUpstreamResolver
{
    public function resolve(ConsoleSession $session): ConsoleUpstream
    {
        throw ConsoleUpstreamUnavailableException::because('the cluster did not answer');
    }
}
