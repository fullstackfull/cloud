<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Env;
use Lynomia\Modules\Shared\Infrastructure\Logging\StructuredLogger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: the channel you get when nobody configures one redacts.
 *
 * RedactSecretsProcessor is pushed by StructuredLogger, and StructuredLogger
 * builds exactly one of the channels in config/logging.php. `single`, `daily`,
 * `stderr` and the rest write whatever they are handed — a key literally named
 * `password` included. That is tolerable only if the protected channel is the
 * one a deployment gets by default.
 *
 * It used to not be: `env('LOG_STACK', 'single')` meant an environment file not
 * copied from .env.example — and the Ansible control_plane role templates none —
 * lost redaction silently, with no signal but credentials in the log. The
 * fallback now names the redacting channel, so the control fails closed.
 */
final class DefaultLogStackIsRedactedTest extends TestCase
{
    #[Test]
    public function the_stack_falls_back_to_a_channel_that_carries_the_redaction_processor(): void
    {
        $repository = Env::getRepository();
        $original = $repository->get('LOG_STACK');

        $repository->clear('LOG_STACK');

        try {
            /** @var array<string, mixed> $config */
            $config = require base_path('config/logging.php');
        } finally {
            if ($original !== null) {
                $repository->set('LOG_STACK', $original);
            }
        }

        /** @var list<string> $channels */
        $channels = $config['channels']['stack']['channels'];

        $this->assertNotEmpty($channels);

        foreach ($channels as $channel) {
            $this->assertSame(
                StructuredLogger::class,
                $config['channels'][$channel]['via'] ?? null,
                "The default stack falls back to '{$channel}', which has no redaction processor.",
            );
        }
    }
}
