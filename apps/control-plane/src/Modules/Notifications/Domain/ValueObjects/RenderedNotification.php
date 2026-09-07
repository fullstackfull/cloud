<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Domain\ValueObjects;

/**
 * A notification in one language, ready to be shown or sent.
 *
 * Title and body are each rendered from a single translation string with named
 * placeholders. Nothing is assembled from fragments: "Your " . $kind . " is
 * ready" produces a sentence no translator can fix, because Arabic puts the
 * words in a different order and inflects around them. One string per message
 * per language is the only arrangement that survives translation.
 *
 * @immutable
 */
final readonly class RenderedNotification
{
    public function __construct(
        public string $title,
        public string $body,
        public string $locale,
    ) {}
}
