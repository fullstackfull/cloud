<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

/**
 * Builds the structured JSON channel that Grafana Alloy ships to Loki.
 *
 * One JSON object per line, secrets scrubbed, correlation IDs carried in the
 * record's context by Laravel's Context facade. Promtail is deliberately not
 * part of this pipeline: it is end-of-life.
 */
final class StructuredLogger
{
    /**
     * @param  array{path?: string, level?: string, bubble?: bool}  $config
     */
    public function __invoke(array $config): Logger
    {
        $handler = new StreamHandler(
            $config['path'] ?? storage_path('logs/lynomia.json'),
            $config['level'] ?? 'debug',
            $config['bubble'] ?? true,
        );

        // Exceptions are serialised as structured data rather than a single
        // opaque string, so Loki can filter on class and file.
        $formatter = new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, appendNewline: true);
        $formatter->includeStacktraces();
        $handler->setFormatter($formatter);

        $logger = new Logger('lynomia');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new RedactSecretsProcessor);

        return $logger;
    }
}
