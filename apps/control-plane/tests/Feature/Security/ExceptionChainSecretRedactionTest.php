<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\BmcConnection;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\IpmiDedicatedProvider;
use Lynomia\Modules\Shared\Infrastructure\Logging\RedactSecretsProcessor;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Shared\Infrastructure\Logging\StructuredLogger;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Regression: a throwable in the log context is redacted like everything else.
 *
 * An adapter can keep a credential out of its own message and context and
 * still hand on the transport exception as `previous`. ipmitool is the sharp
 * case — argv is its only non-interactive credential mechanism, so the process
 * exception's message is the password — but the hole is generic: before this,
 * SecretRedactor's `default => $value` arm passed any object straight through,
 * so `['exception' => $e]` (the shape Laravel's own reporter uses) reached
 * Monolog's JsonFormatter intact and it serialised the whole `previous` chain.
 *
 * The invariant is that redaction is a processor, not a call-site convention.
 * These tests hold it for the one object that carries the most secrets.
 */
final class ExceptionChainSecretRedactionTest extends TestCase
{
    private const string ENDPOINT_ID = '01JBMCIPMIENDPOINT0000000A';

    private const string PASSWORD = 'bmc-root-pass-7Kq3';

    private string $shimDirectory = '';

    private string $logPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * A stand-in for ipmitool that outlives the adapter's deadline, so the
         * timeout is a real one raised by Symfony from a real argv rather than
         * a message this test wrote for itself.
         */
        $this->shimDirectory = sys_get_temp_dir().'/lynomia-ipmi-shim-'.bin2hex(random_bytes(6));
        mkdir($this->shimDirectory);
        file_put_contents($this->shimDirectory.'/ipmitool', "#!/bin/sh\nsleep 30\n");
        chmod($this->shimDirectory.'/ipmitool', 0o755);

        $path = $this->shimDirectory.':'.getenv('PATH');
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        $this->logPath = storage_path('logs/exception-chain-'.bin2hex(random_bytes(6)).'.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->shimDirectory.'/ipmitool');
        @rmdir($this->shimDirectory);
        @unlink($this->logPath);

        parent::tearDown();
    }

    #[Test]
    public function the_structured_channel_does_not_write_the_bmc_password_from_the_previous_chain(): void
    {
        $exception = $this->timedOutPowerOff();

        // The password really is in the chain the adapter hands on — this is
        // the input the processor has to survive, not a hypothetical one.
        $this->assertStringContainsString(
            self::PASSWORD,
            (string) $exception->getPrevious()?->getMessage(),
        );

        // The application's real channel, RedactSecretsProcessor included.
        $logger = (new StructuredLogger)(['path' => $this->logPath, 'level' => 'debug']);

        // Exactly what Laravel's own exception reporter does with an unhandled
        // exception: the message, plus the object itself under 'exception'.
        $logger->error($exception->getMessage(), ['exception' => $exception]);

        $written = (string) file_get_contents($this->logPath);

        $this->assertStringNotContainsString(self::PASSWORD, $written);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $written);

        // Still useful to an operator: class, file and the chain remain.
        $this->assertStringContainsString('DedicatedProviderException', $written);
        $this->assertStringContainsString('previous', $written);
    }

    #[Test]
    public function the_processor_scrubs_any_throwable_in_the_context(): void
    {
        $record = (new RedactSecretsProcessor)(new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'lynomia',
            level: Level::Error,
            message: 'provider call failed',
            context: ['exception' => new RuntimeException(
                'GET https://pve-01:8006/api2/json/nodes with Authorization: PVEAPIToken=lynomia@pve!cp=1f2e3d4c-5b6a',
                0,
                new RuntimeException('sshpass -p hunter2 ssh root@web-01'),
            )],
        ));

        $encoded = json_encode($record->context, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('1f2e3d4c-5b6a', $encoded);
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertStringContainsString(SecretRedactor::PLACEHOLDER, $encoded);
    }

    private function timedOutPowerOff(): DedicatedProviderException
    {
        try {
            $this->provider()->powerOff($this->endpoint());
        } catch (DedicatedProviderException $e) {
            $this->assertTrue($e->isIndeterminate());

            return $e;
        }

        $this->fail('The shimmed ipmitool did not time out, so nothing was demonstrated.');
    }

    private function endpoint(): BmcEndpoint
    {
        $endpoint = new BmcEndpoint;

        $endpoint->forceFill([
            'protocol' => BmcProtocol::Ipmi,
            'address' => '192.0.2.10',
            'port' => 623,
            'username' => 'ADMIN',
            'verify_tls' => true,
        ]);

        $endpoint->id = self::ENDPOINT_ID;

        return $endpoint;
    }

    private function provider(): IpmiDedicatedProvider
    {
        return new IpmiDedicatedProvider(
            new BmcConnection(
                endpointId: self::ENDPOINT_ID,
                protocol: BmcProtocol::Ipmi,
                address: '192.0.2.10',
                port: 623,
                username: 'ADMIN',
                password: self::PASSWORD,
                verifyTls: true,
                timeoutSeconds: 1,
            ),
            new SecretRedactor,
        );
    }
}
