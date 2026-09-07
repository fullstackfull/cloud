<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Console\Application\Actions\AuthoriseConsoleConnection;
use Lynomia\Modules\Console\Infrastructure\GatewayMetrics;
use Lynomia\Modules\Console\Infrastructure\GatewayServer;
use Lynomia\Modules\Console\Infrastructure\WebSocket\FrameCodec;

/**
 * Run the Lynomia Console Gateway.
 *
 * Its own process and its own deployment unit. A console lives for as long as
 * somebody is looking at a screen, holds two sockets the whole time, and must
 * not occupy a worker the rest of the platform needs — so it does not belong
 * in the API's process, and this command is how it is started.
 *
 *     php artisan console-gateway:serve
 *
 * It needs exactly two things from the platform: the shared cache where
 * console permits live, and the database, to check that the machine and its
 * service are still what the permit said. It needs no queue, no scheduler and
 * no HTTP server, which is what makes it deployable on hosts that have neither.
 *
 * Stopping it is a signal. Every open console is closed with a normal closure
 * frame first, so customers see a disconnection rather than a hang — and
 * because the permits were already spent, nothing is left redeemable.
 */
final class ServeConsoleGateway extends Command
{
    protected $signature = 'console-gateway:serve
        {--host= : Override the configured bind address}
        {--port= : Override the configured port}';

    protected $description = 'Serve customer VNC/serial consoles by proxying redeemed permits to the hypervisor.';

    public function handle(
        AuthoriseConsoleConnection $authorise,
        FrameCodec $codec,
        GatewayMetrics $metrics,
    ): int {
        $host = $this->stringOption('host') ?? (string) config('console_gateway.host', '127.0.0.1');
        $port = $this->intOption('port') ?? (int) config('console_gateway.port', 8088);

        $server = new GatewayServer(
            $authorise,
            $codec,
            $metrics,
            /*
             * Logging goes through the platform's own channel rather than to
             * stdout, so a gateway running under a supervisor lands in the
             * same place as everything else. Nothing that passes through this
             * process's buffers is ever logged: the bytes are a customer's
             * keystrokes and their screen.
             */
            static function (string $level, string $message, array $context): void {
                Log::log($level, $message, $context);
            },
        );

        $bound = $server->listen($host, $port);

        $this->info(sprintf('Console gateway listening on %s.', $bound));

        /*
         * Signals are handled by asking the loop to stop rather than by
         * exiting inside the handler: a console closed halfway through a frame
         * write is a customer's terminal left in an undefined state.
         */
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, static function () use ($server): void {
                    $server->stop();
                });
            }
        }

        $server->run();

        $this->info('Console gateway stopped.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return is_numeric($value) ? (int) $value : null;
    }
}
