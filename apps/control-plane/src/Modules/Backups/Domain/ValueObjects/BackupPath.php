<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\ValueObjects;

use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;

/**
 * A path inside an archive, and nothing else.
 *
 * Every path a customer sends is read into this before anything looks at
 * it, and this is where the traversal family is refused as a whole: `..`
 * segments, `.` segments, empty segments, backslashes, NUL and control
 * characters, and anything that is not UTF-8. The path is absolute inside
 * the archive and is compared and stored in its normalised form, so two
 * spellings of one file cannot be two rows.
 *
 * What it says nothing about is where the archive is. A path here is never
 * joined onto a datastore, a mount point or a helper's working directory by
 * the platform; that join, where a provider needs one, happens inside the
 * provider and is that provider's job to get right.
 */
final readonly class BackupPath
{
    public const int MAX_LENGTH = 4_096;

    public const int MAX_SEGMENT_LENGTH = 255;

    public const int MAX_DEPTH = 64;

    /** @var list<string> */
    public array $segments;

    public string $value;

    /**
     * @param  list<string>  $segments
     */
    private function __construct(array $segments)
    {
        $this->segments = $segments;
        $this->value = '/'.implode('/', $segments);
    }

    /**
     * @throws BackupFileRefusedException
     */
    public static function of(string $raw): self
    {
        if (strlen($raw) > self::MAX_LENGTH) {
            throw BackupFileRefusedException::badPath('it is longer than a path can be');
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw BackupFileRefusedException::badPath('it is not valid UTF-8');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            throw BackupFileRefusedException::badPath('it carries control characters');
        }

        if (str_contains($raw, '\\')) {
            throw BackupFileRefusedException::badPath('backslashes are not path separators here');
        }

        if (! str_starts_with($raw, '/')) {
            throw BackupFileRefusedException::badPath('it must start at the root of the archive, with /');
        }

        $segments = [];

        foreach (explode('/', substr($raw, 1)) as $segment) {
            if ($segment === '') {
                if ($raw === '/') {
                    break;
                }

                throw BackupFileRefusedException::badPath('it has an empty segment');
            }

            if ($segment === '.' || $segment === '..') {
                throw BackupFileRefusedException::badPath('it names a directory relative to another');
            }

            if (strlen($segment) > self::MAX_SEGMENT_LENGTH) {
                throw BackupFileRefusedException::badPath('one segment is longer than a name can be');
            }

            $segments[] = $segment;
        }

        if (count($segments) > self::MAX_DEPTH) {
            throw BackupFileRefusedException::badPath('it is deeper than an archive path can be');
        }

        return new self($segments);
    }

    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    public function name(): string
    {
        return $this->segments === [] ? '/' : $this->segments[count($this->segments) - 1];
    }

    public function parent(): self
    {
        return new self(array_slice($this->segments, 0, -1));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
