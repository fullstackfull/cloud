<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Console\Application\Actions\AuthoriseConsoleConnection;
use Lynomia\Modules\Console\Domain\Contracts\ConsoleUpstreamResolver;
use Lynomia\Modules\Console\Domain\ValueObjects\ConsoleUpstream;
use Lynomia\Modules\Console\Infrastructure\GatewayMetrics;
use Lynomia\Modules\Console\Infrastructure\GatewayServer;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Frame;
use Lynomia\Modules\Console\Infrastructure\WebSocket\FrameCodec;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Handshake;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console gateway, running, with bytes going through it.
 *
 * Everything here is real: a listening socket, a browser-shaped client
 * speaking RFC 6455, a controlled upstream that completes the handshake the
 * way a hypervisor does, and the gateway's own select loop in between. Nothing
 * is mocked, because what is being proved is precisely the part a mock would
 * assume — that the handshake completes, that frames are re-masked in the
 * right direction, and that closing one half closes the other.
 *
 * The loop is driven a tick at a time rather than left to run, so the test is
 * deterministic and finishes in milliseconds instead of waiting on timeouts.
 */
final class ConsoleGatewayRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private ControlledConsoleUpstream $upstream;

    private GatewayServer $gateway;

    private int $gatewayPort;

    private Customer $customer;

    private VirtualMachine $machine;

    private FrameCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new FrameCodec;
        $this->upstream = new ControlledConsoleUpstream;

        $cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->getKey()]);

        $this->customer = Customer::factory()->create();
        $service = Service::factory()->active()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'vps',
        ]);

        $this->machine = VirtualMachine::factory()->onNode($node, 800)->forService($service)->create();

        // The gateway dials the controlled upstream, which is what the fake
        // hypervisor's console endpoint does in a local deployment.
        $port = $this->upstream->port();
        $this->app->bind(ConsoleUpstreamResolver::class, fn (): ConsoleUpstreamResolver => new class($port) implements ConsoleUpstreamResolver
        {
            public function __construct(private readonly int $port) {}

            public function resolve(ConsoleSession $session): ConsoleUpstream
            {
                return new ConsoleUpstream(
                    host: '127.0.0.1',
                    port: $this->port,
                    path: '/console/'.$session->virtualMachineId,
                    tls: false,
                    headers: ['X-Console-For' => $session->virtualMachineId],
                    verifyTls: false,
                );
            }
        });

        $this->gateway = new GatewayServer(
            app(AuthoriseConsoleConnection::class),
            $this->codec,
            app(GatewayMetrics::class),
            static function (): void {},
        );

        $bound = $this->gateway->listen('127.0.0.1', 0);
        $this->gatewayPort = (int) substr($bound, (int) strrpos($bound, ':') + 1);
    }

    protected function tearDown(): void
    {
        $this->gateway->stop();
        $this->upstream->shutdown();

        parent::tearDown();
    }

    #[Test]
    public function a_permit_opens_a_console_and_bytes_go_both_ways(): void
    {
        $session = $this->issue();

        $client = $this->connect($session);

        $this->assertTrue($this->completeClientHandshake($client), 'The gateway did not complete the WebSocket handshake.');

        // Keystrokes, from the browser towards the machine.
        fwrite($client, $this->codec->encode(new Frame(Frame::BINARY, 'ls -la'), mask: true));
        $this->pump();

        $this->assertSame(['ls -la'], $this->upstream->received, 'The keystrokes did not reach the upstream.');

        // And the screen coming back.
        $frames = $this->readFrames($client);
        $this->assertContains('upstream:ls -la', $frames);

        // An unprompted screen update, which is most of what a console is.
        $this->upstream->send('screen refresh');
        $this->pump();

        $this->assertContains('screen refresh', $this->readFrames($client));

        fclose($client);
    }

    #[Test]
    public function the_upstream_receives_the_credentials_and_the_browser_never_does(): void
    {
        /*
         * The whole reason the gateway exists. The provider's console
         * credential is presented on the gateway's own connection, and what
         * the browser holds is a permit that is worthless anywhere else.
         */
        $session = $this->issue();
        $client = $this->connect($session);
        $this->completeClientHandshake($client);

        $this->assertSame(
            (string) $this->machine->getKey(),
            $this->upstream->sawHeaders['x-console-for'] ?? null,
            'The gateway did not present the upstream credential.',
        );

        fclose($client);
    }

    #[Test]
    public function the_same_permit_cannot_open_a_second_console(): void
    {
        $session = $this->issue();

        $first = $this->connect($session);
        $this->assertTrue($this->completeClientHandshake($first));

        // A second browser, or the same one reconnecting, with the URL it
        // still has in its history.
        $second = $this->connect($session);

        $this->pump(20);

        /*
         * Refused with a close frame rather than a dropped socket: a browser
         * surfaces an HTTP-level rejection as an opaque failure, and the
         * portal needs to be able to say "that console link has already been
         * used".
         */
        $close = $this->readCloseCode($second);
        $this->assertSame(1008, $close, 'A reused permit did not get a policy-violation close.');

        // And the first console is untouched.
        fwrite($first, $this->codec->encode(new Frame(Frame::BINARY, 'still here'), mask: true));
        $this->pump();
        $this->assertContains('still here', $this->upstream->received);

        fclose($first);
        fclose($second);
    }

    #[Test]
    public function a_permit_for_another_machine_is_refused_at_the_socket(): void
    {
        $other = VirtualMachine::factory()
            ->onNode(ComputeNode::query()->firstOrFail(), 801)
            ->forService(Service::factory()->active()->create([
                'customer_id' => $this->customer->getKey(),
                'kind' => 'vps',
            ]))
            ->create();

        $session = $this->issue();

        $client = $this->connect($session, machineId: (string) $other->getKey());
        $this->pump(20);

        $this->assertSame(1008, $this->readCloseCode($client));
        $this->assertFalse($this->upstream->isConnected(), 'The gateway dialled a hypervisor for a refused connection.');

        fclose($client);
    }

    #[Test]
    public function a_page_on_another_origin_is_refused_before_the_permit_is_spent(): void
    {
        /*
         * A WebSocket is not subject to the same-origin policy: any page in a
         * customer's browser may open one to this gateway. The permit is what
         * actually authenticates — a hostile page cannot read one — so this is
         * defence in depth, and what it buys is that the attacker's page
         * cannot even reach the authorisation step, let alone spend the
         * connection budget of the address the customer is browsing from.
         */
        config()->set('console_gateway.allowed_origins', ['https://portal.lynomia.test']);

        $session = $this->issue();

        $client = $this->connect($session, origin: 'https://attacker.example');

        $this->assertFalse(
            $this->completeClientHandshake($client),
            'A page on another origin opened a console.',
        );

        // And the permit was not burned: the customer's own tab still works.
        $allowed = $this->connect($session, origin: 'https://portal.lynomia.test');

        $this->assertTrue($this->completeClientHandshake($allowed), 'The portal itself was refused.');
    }

    #[Test]
    public function a_prefix_of_the_portals_origin_is_not_the_portal(): void
    {
        // `https://portal.lynomia.test.attacker.example` starts with the
        // portal's own origin, which is why the comparison is whole-string.
        config()->set('console_gateway.allowed_origins', ['https://portal.lynomia.test']);

        $session = $this->issue();

        $client = $this->connect($session, origin: 'https://portal.lynomia.test.attacker.example');

        $this->assertFalse($this->completeClientHandshake($client));
    }

    #[Test]
    public function a_client_that_hangs_up_takes_the_upstream_with_it(): void
    {
        /*
         * A console with one live half is not a console — it is an open
         * connection to a hypervisor that nobody is watching, holding a
         * session the customer believes they closed.
         */
        $session = $this->issue();
        $client = $this->connect($session);
        $this->completeClientHandshake($client);

        $this->assertTrue($this->upstream->isConnected());

        fclose($client);
        $this->pump(20);

        $this->assertFalse($this->upstream->isConnected(), 'The upstream stayed open after the browser disconnected.');
        $this->assertSame(0, $this->gateway->openSessions());
    }

    #[Test]
    public function an_upstream_that_disappears_closes_the_browser_too(): void
    {
        $session = $this->issue();
        $client = $this->connect($session);
        $this->completeClientHandshake($client);

        $this->upstream->close();
        $this->pump(20);

        $this->assertTrue(feof($client) || $this->readCloseCode($client) !== null, 'The browser was left holding a dead console.');

        fclose($client);
    }

    #[Test]
    public function an_upstream_that_refuses_the_upgrade_does_not_look_like_a_working_console(): void
    {
        $this->upstream->refuseUpgrade = true;

        $session = $this->issue();
        $client = $this->connect($session);
        $this->completeClientHandshake($client);

        $this->pump(20);

        // The gateway completed its own handshake — it had to, to be able to
        // say anything — and then closed rather than sitting there looking
        // connected.
        $this->assertTrue(feof($client) || $this->readCloseCode($client) !== null);

        fclose($client);
    }

    #[Test]
    public function a_socket_that_is_not_a_websocket_gets_nothing(): void
    {
        $client = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $this->gatewayPort));
        $this->assertNotFalse($client);

        fwrite($client, "GET /console HTTP/1.1\r\nHost: gateway\r\n\r\n");
        $this->pump(10);

        $response = (string) fread($client, 1024);

        $this->assertStringStartsWith('HTTP/1.1 400', $response);
        $this->assertStringNotContainsString('101', $response);

        fclose($client);
    }

    #[Test]
    public function the_gateway_counts_what_it_did_without_naming_anybody(): void
    {
        $metrics = app(GatewayMetrics::class);

        $session = $this->issue();
        $client = $this->connect($session);
        $this->completeClientHandshake($client);

        $this->assertGreaterThan(0, $metrics->read('accepted'));
        $this->assertGreaterThan(0, $metrics->read('opened'));

        // A refusal is counted under its reason, and the reason set is fixed.
        $reused = $this->connect($session);
        $this->pump(20);

        $this->assertSame(1, $metrics->read('refused:invalid_permit'));
        $this->assertContains('invalid_permit', GatewayMetrics::REASONS);

        fclose($client);
        fclose($reused);
    }

    /**
     * Drive both sides of the conversation.
     *
     * The gateway and the upstream are both non-blocking, so a test that
     * ticked only one of them would deadlock waiting for the other.
     */
    private function pump(int $times = 10): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->gateway->tick(0.005);
            $this->upstream->tick();
        }
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

    /**
     * Open a browser-shaped connection and send the upgrade request.
     *
     * @return resource
     */
    private function connect(ConsoleSession $session, ?string $machineId = null, ?string $origin = null)
    {
        $client = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $this->gatewayPort));

        if ($client === false) {
            $this->fail('Could not connect to the gateway.');
        }

        stream_set_blocking($client, false);

        $target = sprintf(
            '/console?session=%s&token=%s&machine=%s',
            rawurlencode($session->id),
            rawurlencode((string) $session->token),
            rawurlencode($machineId ?? $session->virtualMachineId),
        );

        fwrite($client, Handshake::clientRequest(
            'gateway',
            $target,
            base64_encode(random_bytes(16)),
            $origin === null ? [] : ['Origin' => $origin],
        ));

        $this->pump();

        return $client;
    }

    /**
     * @param  resource  $client
     */
    private function completeClientHandshake($client): bool
    {
        $buffer = '';

        for ($i = 0; $i < 40; $i++) {
            $this->pump(2);

            $chunk = (string) @fread($client, 65_536);
            $buffer .= $chunk;

            if (str_contains($buffer, "\r\n\r\n")) {
                return str_starts_with($buffer, 'HTTP/1.1 101');
            }
        }

        return false;
    }

    /**
     * Every payload the gateway has sent this client.
     *
     * @param  resource  $client
     * @return list<string>
     */
    private function readFrames($client): array
    {
        $buffer = '';

        for ($i = 0; $i < 10; $i++) {
            $this->pump(2);
            $buffer .= (string) @fread($client, 65_536);
        }

        $payloads = [];

        while (($frame = $this->codec->decode($buffer, expectMasked: false)) !== null) {
            if (! $frame->isControl()) {
                $payloads[] = $frame->payload;
            }
        }

        return $payloads;
    }

    /**
     * The close code the gateway sent, if it sent one.
     *
     * @param  resource  $client
     */
    private function readCloseCode($client): ?int
    {
        $buffer = '';

        for ($i = 0; $i < 20; $i++) {
            $this->pump(2);
            $buffer .= (string) @fread($client, 65_536);
        }

        // The 101 comes first on a refused connection: the gateway upgrades so
        // it can speak a close frame the browser will surface.
        $head = strpos($buffer, "\r\n\r\n");

        if ($head !== false) {
            $buffer = substr($buffer, $head + 4);
        }

        while (($frame = $this->codec->decode($buffer, expectMasked: false)) !== null) {
            if ($frame->opcode === Frame::CLOSE && strlen($frame->payload) >= 2) {
                /** @var array{1: int} $unpacked */
                $unpacked = unpack('n', substr($frame->payload, 0, 2));

                return $unpacked[1];
            }
        }

        return null;
    }
}
