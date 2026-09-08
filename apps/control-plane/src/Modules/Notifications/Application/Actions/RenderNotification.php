<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Actions;

use Illuminate\Support\Facades\Lang;
use Lynomia\Modules\Notifications\Domain\ValueObjects\RenderedNotification;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * Turns a stored notification into a sentence, in the reader's language.
 *
 * ---------------------------------------------------------------------------
 * Rendered on read, never on write
 * ---------------------------------------------------------------------------
 *
 * The row holds facts — a hostname, an amount, an invoice number — and this
 * builds the prose from them each time. A customer who switches the portal to
 * Arabic sees their whole history in Arabic, including messages sent months
 * before they switched; a stored English sentence could not be translated
 * afterwards.
 *
 * ---------------------------------------------------------------------------
 * One string per message, with named placeholders
 * ---------------------------------------------------------------------------
 *
 * Never concatenation. "Your " . $kind . " is ready" is a sentence no
 * translator can repair: Arabic orders the words differently and inflects
 * around them, so the fragments have no correct translation in isolation. Each
 * message is one `:placeholder` string per language.
 *
 * A type with no translation falls back to its own key rather than to English,
 * on purpose — a missing Arabic string should look obviously missing to
 * whoever is testing, not quietly correct.
 */
final readonly class RenderNotification
{
    public function execute(Notification $notification, string $locale): RenderedNotification
    {
        $key = 'notifications.'.$notification->type->value;

        /** @var array<string, scalar|null> $replacements */
        $replacements = [];

        foreach ($notification->data ?? [] as $name => $value) {
            // Only scalars reach a translation string. A nested array would
            // stringify to "Array" in the middle of a sentence a customer
            // reads.
            if (is_scalar($value)) {
                $replacements[$name] = $value;
            }
        }

        return new RenderedNotification(
            title: $this->line($key.'.title', $replacements, $locale),
            body: $this->line($key.'.body', $replacements, $locale),
            locale: $locale,
        );
    }

    /**
     * @param  array<string, scalar|null>  $replacements
     */
    private function line(string $key, array $replacements, string $locale): string
    {
        /** @var array<string, string> $stringReplacements */
        $stringReplacements = array_map(self::oneLine(...), array_filter(
            $replacements,
            static fn (mixed $v): bool => $v !== null,
        ));

        $translated = Lang::get($key, $stringReplacements, $locale);

        return is_string($translated) ? $translated : $key;
    }

    /**
     * Flatten a value that a customer chose into a single line.
     *
     * The values interpolated here are the customer's own: a hostname, a
     * service label, an invoice number. One of them ends up in the subject
     * line of an email, and a newline in a subject is a header — a header a
     * customer chose is a Bcc a customer chose. The mail library would
     * probably encode it; "probably" is not the standard for something that
     * decides who receives a message.
     *
     * The same value is also the title in the in-app inbox, where a control
     * character is merely wrong rather than dangerous. Both are fixed here,
     * once, rather than at each place that reads a rendered line.
     */
    private static function oneLine(mixed $value): string
    {
        $flattened = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);

        // A failed replace returns null, and an unfiltered value must never be
        // what a failure falls back to.
        return trim($flattened ?? '');
    }
}
