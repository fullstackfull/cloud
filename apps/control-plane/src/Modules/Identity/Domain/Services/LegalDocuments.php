<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Services;

use Lynomia\Modules\Identity\Domain\DTOs\PublishedLegalDocument;
use Lynomia\Modules\Identity\Domain\Enums\LegalDocumentType;

/**
 * The one answer to "are the documents a customer accepts actually published?"
 *
 * ---------------------------------------------------------------------------
 * What was wrong before this existed
 * ---------------------------------------------------------------------------
 *
 * The registration form asked for acceptance, the API required the checkbox,
 * and `config/legal.php` said the acceptance "is recorded against the
 * account". None of the last part was true. `accepts_terms` appeared in
 * exactly one place in the whole repository — a validation rule — and was
 * discarded the moment it passed. Nothing was stored, so nothing could be
 * produced later; and because the URLs were optional, an account could be
 * created on the strength of a customer ticking a box next to two document
 * names that linked nowhere, because the documents did not exist.
 *
 * Two things had to be true instead, and both are enforced from here:
 *
 *  - **Fail closed.** If the documents are not published, registration is
 *    refused rather than completed against nothing. An unwritten policy is
 *    not a lenient policy;
 *  - **Bind to a revision.** An acceptance says which text was accepted, so
 *    a version is required alongside a URL. "The customer accepted the terms"
 *    is not a fact anyone can act on if the terms have been rewritten twice
 *    since.
 *
 * ---------------------------------------------------------------------------
 * Why publication still lives in configuration
 * ---------------------------------------------------------------------------
 *
 * Because the documents themselves are a legal deliverable that people write
 * and review, and this repository must not contain a draft of one. Publishing
 * them is setting four values; no business code changes, and none of it is a
 * deployment of new logic. What this class refuses to do is treat an unset
 * value as permission.
 */
final readonly class LegalDocuments
{
    /**
     * Versions are compared, stored and shown. A sentence, a newline or a
     * control character in one would end up in a database column that is
     * supposed to be a stable identifier, so the shape is narrow on purpose:
     * something like `2026-04-01`, `1.4` or `v3`.
     */
    private const string VERSION_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/';

    /**
     * Every document that is fully published, in the order the enum declares.
     *
     * @return list<PublishedLegalDocument>
     */
    public function published(): array
    {
        $published = [];

        foreach (LegalDocumentType::cases() as $type) {
            $url = $this->url($type);
            $version = $this->version($type);

            if ($url === null || $version === null) {
                continue;
            }

            $published[] = new PublishedLegalDocument($type, $url, $version);
        }

        return $published;
    }

    /**
     * May a registration be accepted at all?
     *
     * Every document, not most of them: a customer cannot half-accept the
     * terms on which they hold an account.
     */
    public function registrationPermitted(): bool
    {
        return count($this->published()) === count(LegalDocumentType::cases());
    }

    /**
     * Which documents are not published, for the operator-facing reason on a
     * refusal. Never shown to the customer, and never as a config key — the
     * document type is what a person needs; where the value is missing from
     * is a fact about this deployment.
     *
     * @return list<string>
     */
    public function unpublished(): array
    {
        $publishedTypes = array_map(
            static fn (PublishedLegalDocument $document): LegalDocumentType => $document->type,
            $this->published(),
        );

        $missing = [];

        foreach (LegalDocumentType::cases() as $type) {
            if (! in_array($type, $publishedTypes, strict: true)) {
                $missing[] = $type->value;
            }
        }

        return $missing;
    }

    /**
     * A published address, or null when there is not one.
     *
     * The value comes from configuration and ends up in an anchor in a
     * visitor's browser, so a scheme other than http or https is refused
     * rather than explained: `javascript:` in a link the registration page
     * renders would be a stored cross-site scripting vector delivered by the
     * platform's own configuration.
     */
    public function url(LegalDocumentType $type): ?string
    {
        $configured = config($type->urlKey());

        if (! is_string($configured)) {
            return null;
        }

        $url = trim($configured);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], strict: true) ? $url : null;
    }

    /**
     * The published revision, or null when there is not one.
     */
    public function version(LegalDocumentType $type): ?string
    {
        $configured = config($type->versionKey());

        if (! is_string($configured)) {
            return null;
        }

        $version = trim($configured);

        return preg_match(self::VERSION_PATTERN, $version) === 1 ? $version : null;
    }
}
