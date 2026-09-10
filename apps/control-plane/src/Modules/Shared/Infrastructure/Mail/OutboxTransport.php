<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Infrastructure\Mail;

use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * A mail transport that delivers into a file, so an automated test can open
 * the mail a customer would have received.
 *
 * It exists for one journey the platform could not otherwise prove: register,
 * receive the verification mail, follow the link in it, and arrive verified.
 * The alternatives are all worse. `UPDATE users SET email_verified_at = now()`
 * proves that a column can be written, not that the link works, and it is
 * exactly the shortcut that lets a broken signed URL ship. Reading the `log`
 * mailer's output means parsing a log file for a URL, which passes while the
 * mail body is unreadable. So the mail is written whole — recipient, subject,
 * both bodies — as one JSON object per line, and the browser suite follows the
 * real signed link out of it.
 *
 * Two properties make it safe to have in the repository at all:
 *
 *  - it refuses to be constructed in production, loudly, so a misconfigured
 *    deployment cannot silently divert customer mail into a file instead of
 *    sending it; and
 *  - it is reachable only when MAIL_MAILER names it, which no production
 *    environment file does.
 *
 * There is deliberately no endpoint that reads the outbox. It is a file on the
 * test runner's disk; the harness reads it directly, and nothing in the
 * application does.
 */
final class OutboxTransport extends AbstractTransport
{
    public function __construct(private readonly string $path)
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'The outbox mail transport is a test facility and must never be selected in production. '
                .'Set MAIL_MAILER to a real mailer.'
            );
        }

        if (trim($this->path) === '') {
            throw new RuntimeException('The outbox mail transport needs MAIL_OUTBOX_PATH to say where to write.');
        }

        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Email) {
            return;
        }

        $record = [
            'to' => array_map(
                static fn ($address): string => $address->getAddress(),
                $original->getTo(),
            ),
            'subject' => (string) $original->getSubject(),
            'text' => self::bodyOf($original->getTextBody()),
            'html' => self::bodyOf($original->getHtmlBody()),
            'sent_at' => now()->toIso8601String(),
        ];

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        // Appended under an exclusive lock: the queue worker and the web
        // process both send mail, and a half-written line would fail the
        // harness in a way that looks like a missing mail.
        file_put_contents(
            $this->path,
            json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }

    public function __toString(): string
    {
        return 'outbox://'.$this->path;
    }

    private static function bodyOf(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return '';
    }
}
