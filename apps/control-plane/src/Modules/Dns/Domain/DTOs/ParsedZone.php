<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\DTOs;

/**
 * What a zone file said, in three lists: the records the platform can hold,
 * the lines it refused with reasons, and the lines it deliberately does not
 * own. Nothing that was in the file is absent from all three.
 */
final readonly class ParsedZone
{
    /**
     * @param  list<ParsedRecord>  $records
     * @param  list<ParsedProblem>  $refused
     * @param  list<ParsedProblem>  $ignored
     */
    public function __construct(
        public string $origin,
        public array $records,
        public array $refused,
        public array $ignored,
    ) {}
}
