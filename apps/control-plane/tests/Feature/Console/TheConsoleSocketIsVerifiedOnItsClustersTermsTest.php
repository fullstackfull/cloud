<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Console\Application\Actions\AuthoriseConsoleConnection;
use Lynomia\Modules\Console\Domain\Contracts\ConsoleUpstreamResolver;
use Lynomia\Modules\Console\Domain\ValueObjects\ConsoleUpstream;
use Lynomia\Modules\Console\Infrastructure\GatewayMetrics;
use Lynomia\Modules\Console\Infrastructure\GatewayServer;
use Lynomia\Modules\Console\Infrastructure\WebSocket\FrameCodec;
use Lynomia\Modules\Console\Infrastructure\WebSocket\Handshake;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Services\ConsoleSessionStore;
use Lynomia\Modules\Vps\Domain\ValueObjects\ConsoleSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console socket, verified on the terms of the cluster it reaches.
 *
 * The gateway's upstream socket carries the cluster's API token in its
 * Authorization header, and a root console for a machine on a node other
 * customers share. F-28: its certificate policy was read from the fleet-wide
 * `compute.proxmox.verify_tls` key while every other call to the same host
 * read the cluster's own row, so one `PROXMOX_VERIFY_TLS=false` handed that
 * token to whoever answered the console port of every cluster at once.
 *
 * Two halves, both over real sockets and a real TLS handshake:
 *
 *  - **Where the policy comes from.** The first two tests go through the
 *    platform's own resolver, compute factory and Proxmox adapter to the
 *    gateway's socket, with the fleet key set against the row each time, and
 *    ask the one question that matters: did the host that answered receive
 *    the token?
 *
 *  - **What the socket does with it.** The matrix pins both halves of the
 *    gateway's TLS context — `verify_peer` and `verify_peer_name` — in both
 *    directions. The authority it trusts is minted per run and handed to
 *    OpenSSL through `SSL_CERT_FILE`. That works *because* the gateway's
 *    context names no `cafile`: PHP then falls back to OpenSSL's default
 *    verify paths, and the variable is how those are chosen. Nothing is added
 *    to the machine's trust store and nothing in production changes.
 *
 * What the matrix can and cannot see, measured by mutating the gateway rather
 * than argued: switching off `verify_peer` fails the first row, switching off
 * `verify_peer_name` fails the third, and hard-coding either on fails the
 * second or fourth. The fifth row — a certificate for this host from the
 * trusted authority, verification on — is the control that makes the two
 * refusals mean something, and it also fails the moment the context names a
 * real `cafile` (a system bundle), because the per-run authority is in no real
 * bundle. The only `cafile` it cannot catch is one naming this run's own
 * authority at its random `tempnam()` path, which no source change can express.
 */
final class TheConsoleSocketIsVerifiedOnItsClustersTermsTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN_ID = 'lynomia@pve!control-plane';

    private const string TOKEN_SECRET = 'c2a9e0f4-7d1b-4e36-8a55-3f0b9d2e6c71';

    private const string MISSING = '__unset__';

    private ThrowawayCertificates $certificates;

    private ?TlsConsoleUpstream $upstream = null;

    private ?GatewayServer $gateway = null;

    private int $gatewayPort = 0;

    /**
     * The browser's end, held for the whole test: a socket dropped here would
     * be a customer hanging up, and the gateway would take the upstream down
     * with it.
     *
     * @var resource|null
     */
    private $browser = null;

    private Customer $customer;

    private Service $service;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $logged = [];

    private string $certFileBefore = self::MISSING;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certificates = new ThrowawayCertificates;

        $before = getenv('SSL_CERT_FILE');
        $this->certFileBefore = $before === false ? self::MISSING : $before;

        $this->customer = Customer::factory()->create();
        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'vps',
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->browser)) {
            fclose($this->browser);
        }

        $this->gateway?->stop();
        $this->upstream?->shutdown();
        $this->certificates->cleanUp();

        putenv($this->certFileBefore === self::MISSING ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE='.$this->certFileBefore);

        parent::tearDown();
    }

    #[Test]
    public function a_production_clusters_console_does_not_hand_its_token_to_an_impostor_whatever_the_fleet_default_says(): void
    {
        // Somebody working around a lab certificate, the way F-28 happens.
        config()->set('compute.proxmox.verify_tls', false);

        // Whoever answers the console port presents a certificate for the
        // right address that nobody vouches for.
        $this->upstream = new TlsConsoleUpstream($this->certificates->selfSignedFor('IP:127.0.0.1'));

        $machine = $this->machineOnAProxmoxClusterWhoseRowSays(verifyTls: true);

        $this->openAConsoleFor($machine);

        $report = $this->upstream->nextConnection();

        $this->assertNotNull($report, 'The gateway never dialled the console port, so this test proved nothing.');
        $this->assertNull(
            $report['authorization'],
            'The console socket handed the cluster\'s API token to a host whose certificate nobody vouches for, on a cluster whose own row demands verification.',
        );
        $this->assertTheGatewayRefusedTheUpstream();
    }

    #[Test]
    public function a_lab_clusters_console_opens_on_the_certificate_its_row_waived_whatever_the_fleet_default_says(): void
    {
        config()->set('compute.proxmox.verify_tls', true);

        // A lab cluster out of the box: its own self-signed certificate.
        $this->upstream = new TlsConsoleUpstream($this->certificates->selfSignedFor('IP:127.0.0.1'));

        $machine = $this->machineOnAProxmoxClusterWhoseRowSays(verifyTls: false);

        $this->openAConsoleFor($machine);

        $report = $this->upstream->nextConnection();

        $this->assertNotNull($report, 'The gateway never dialled the console port.');
        $this->assertSame(
            sprintf('PVEAPIToken=%s=%s', self::TOKEN_ID, self::TOKEN_SECRET),
            $report['authorization'],
            'The console of a cluster whose row waives verification was refused on the fleet default, while every API call to the same host was not.',
        );
        $this->assertTheGatewayOpenedTheConsole();
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function certificatesTheGatewayMightBeShown(): array
    {
        return [
            'a certificate nobody vouches for, verification on' => ['self-signed', true, false],
            'a certificate nobody vouches for, verification waived' => ['self-signed', false, true],
            'a trusted certificate for another name, verification on' => ['another-name', true, false],
            'a trusted certificate for another name, verification waived' => ['another-name', false, true],
            'a trusted certificate for this host, verification on' => ['this-host', true, true],
        ];
    }

    #[Test]
    #[DataProvider('certificatesTheGatewayMightBeShown')]
    public function the_console_socket_verifies_the_chain_and_the_name_exactly_when_its_upstream_asks(
        string $certificate,
        bool $verifyTls,
        bool $shouldConnect,
    ): void {
        putenv('SSL_CERT_FILE='.$this->certificates->authorityFile());

        $this->upstream = new TlsConsoleUpstream(match ($certificate) {
            'self-signed' => $this->certificates->selfSignedFor('IP:127.0.0.1'),
            'another-name' => $this->certificates->issuedFor('DNS:somebody-else.test'),
            'this-host' => $this->certificates->issuedFor('IP:127.0.0.1'),
        });

        $port = $this->upstream->port();

        $this->app->bind(ConsoleUpstreamResolver::class, fn (): ConsoleUpstreamResolver => new class($port, $verifyTls) implements ConsoleUpstreamResolver
        {
            public function __construct(private readonly int $port, private readonly bool $verifyTls) {}

            public function resolve(ConsoleSession $session): ConsoleUpstream
            {
                return new ConsoleUpstream(
                    host: '127.0.0.1',
                    port: $this->port,
                    path: '/console/'.$session->virtualMachineId,
                    tls: true,
                    headers: ['Authorization' => 'PVEAPIToken=matrix@pve!console=not-a-real-secret'],
                    verifyTls: $this->verifyTls,
                );
            }
        });

        $cluster = ComputeCluster::factory()->create();
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->getKey()]);
        $machine = VirtualMachine::factory()->onNode($node, 900)->forService($this->service)->create();

        $this->openAConsoleFor($machine);

        $report = $this->upstream->nextConnection();

        $this->assertNotNull($report, 'The gateway never dialled the console port.');

        if ($shouldConnect) {
            $this->assertSame(
                'PVEAPIToken=matrix@pve!console=not-a-real-secret',
                $report['authorization'],
                sprintf('The gateway refused an upstream it should have accepted (handshake completed: %s).', var_export($report['handshake'], true)),
            );
            $this->assertTheGatewayOpenedTheConsole();

            return;
        }

        $this->assertNull(
            $report['authorization'],
            'The gateway sent the upstream credential over a socket whose certificate it was asked to verify and should have refused.',
        );
        $this->assertTheGatewayRefusedTheUpstream();
    }

    private function machineOnAProxmoxClusterWhoseRowSays(bool $verifyTls): VirtualMachine
    {
        config()->set('compute.credentials.console-f28', [
            'token_id' => self::TOKEN_ID,
            'token_secret' => self::TOKEN_SECRET,
        ]);

        // The console port is the API port, as it is on Proxmox: the
        // websocket is served by the same daemon on the same address.
        $cluster = ComputeCluster::factory()
            ->proxmox('console-f28', sprintf('https://127.0.0.1:%d', $this->upstream?->port() ?? 0))
            ->create(['verify_tls' => $verifyTls]);

        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->getKey()]);

        Http::fake([
            '*/vncproxy' => Http::response(['data' => [
                'ticket' => 'PVEVNC:65F0A1B2::ticket-body',
                'port' => '5900',
                'user' => self::TOKEN_ID,
            ]]),
        ]);

        return VirtualMachine::factory()->onNode($node, 800)->forService($this->service)->create();
    }

    /**
     * Start the gateway, issue a permit for the machine, and present it the
     * way a browser does — which is what makes the gateway dial.
     */
    private function openAConsoleFor(VirtualMachine $machine): void
    {
        $this->gateway = new GatewayServer(
            app(AuthoriseConsoleConnection::class),
            new FrameCodec,
            app(GatewayMetrics::class),
            function (string $level, string $message, array $context = []): void {
                $this->logged[] = [$level, $message, $context];
            },
        );

        $bound = $this->gateway->listen('127.0.0.1', 0);
        $this->gatewayPort = (int) substr($bound, (int) strrpos($bound, ':') + 1);

        $session = app(ConsoleSessionStore::class)->issue(
            virtualMachineId: (string) $machine->getKey(),
            customerId: (string) $this->customer->getKey(),
            userId: null,
            id: (string) Str::ulid(),
            token: rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
        );

        $client = stream_socket_client(sprintf('tcp://127.0.0.1:%d', $this->gatewayPort));

        if ($client === false) {
            $this->fail('Could not connect to the gateway.');
        }

        $this->browser = $client;
        stream_set_blocking($client, false);

        fwrite($client, Handshake::clientRequest(
            'gateway',
            sprintf(
                '/console?session=%s&token=%s&machine=%s',
                rawurlencode($session->id),
                rawurlencode((string) $session->token),
                rawurlencode($session->virtualMachineId),
            ),
            base64_encode(random_bytes(16)),
        ));

        // The dial happens inside the tick that reads the permit.
        for ($i = 0; $i < 10; $i++) {
            $this->gateway->tick(0.01);
        }
    }

    private function assertTheGatewayRefusedTheUpstream(): void
    {
        $this->assertSame(1, app(GatewayMetrics::class)->read('upstream_failed'), 'The gateway did not count the upstream as failed.');
        $this->assertSame(0, $this->gateway?->openSessions(), 'The gateway is holding an upstream it should have refused.');
        $this->assertContains(
            'A console upstream refused the connection.',
            array_column($this->logged, 1),
            'The gateway said nothing to its operator about the refused upstream.',
        );
    }

    private function assertTheGatewayOpenedTheConsole(): void
    {
        // The upstream's 101 arrives on a later tick than the dial.
        for ($i = 0; $i < 50 && app(GatewayMetrics::class)->read('opened') === 0; $i++) {
            $this->gateway?->tick(0.01);
        }

        $this->assertSame(0, app(GatewayMetrics::class)->read('upstream_failed'), 'The gateway counted an upstream it accepted as failed.');
        $this->assertSame(1, app(GatewayMetrics::class)->read('opened'), 'The console never opened.');
        $this->assertSame(1, $this->gateway?->openSessions());
    }
}
