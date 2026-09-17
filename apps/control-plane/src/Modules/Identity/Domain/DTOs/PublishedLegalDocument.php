<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\DTOs;

use Lynomia\Modules\Identity\Domain\Enums\LegalDocumentType;

/**
 * A legal document that is actually published: it has somewhere to be read
 * and a revision to be accepted.
 *
 * This type exists so that "published" cannot be half-true anywhere
 * downstream. A URL without a version is a page whose acceptance nobody can
 * pin to a revision; a version without a URL is a revision nobody can read.
 * Either is refused at construction time — by {@see LegalDocuments}, which is
 * the only thing that builds these — so a caller holding one of these knows
 * both halves are present and valid without checking again.
 *
 * @immutable
 */
final readonly class PublishedLegalDocument
{
    public function __construct(
        public LegalDocumentType $type,
        public string $url,
        public string $version,
    ) {}
}
