<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Reference;

use InvalidArgumentException;

/**
 * One thing in the reference estate: what it is, and what it hangs off.
 *
 * The facts/refs split is the whole schema. `facts` are values about this
 * object; `refs` name other objects by logical id. Keeping them apart is what
 * lets {@see ReferenceTopologyValidator} walk the entire graph without knowing
 * anything about any particular kind, and it is what makes a dangling reference
 * a structural error rather than a string that happens not to match.
 *
 * The typed accessors exist because the loader writes these values into
 * columns, and a topology that said `'cpu_cores' => '32'` should fail here
 * rather than three layers down inside Eloquent.
 */
final readonly class ReferenceObject
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, string|null>  $refs
     */
    public function __construct(
        public ReferenceKind $kind,
        public string $id,
        public array $facts,
        public array $refs,
    ) {}

    public function has(string $fact): bool
    {
        return array_key_exists($fact, $this->facts) && $this->facts[$fact] !== null;
    }

    public function string(string $fact): string
    {
        $value = $this->facts[$fact] ?? null;

        if (! is_string($value)) {
            throw $this->wrong($fact, 'a string');
        }

        return $value;
    }

    public function stringOrNull(string $fact): ?string
    {
        return $this->has($fact) ? $this->string($fact) : null;
    }

    public function int(string $fact): int
    {
        $value = $this->facts[$fact] ?? null;

        if (! is_int($value)) {
            throw $this->wrong($fact, 'an integer');
        }

        return $value;
    }

    public function intOrNull(string $fact): ?int
    {
        return $this->has($fact) ? $this->int($fact) : null;
    }

    public function float(string $fact): float
    {
        $value = $this->facts[$fact] ?? null;

        if (! is_float($value) && ! is_int($value)) {
            throw $this->wrong($fact, 'a number');
        }

        return (float) $value;
    }

    public function bool(string $fact): bool
    {
        $value = $this->facts[$fact] ?? null;

        if (! is_bool($value)) {
            throw $this->wrong($fact, 'a boolean');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function strings(string $fact): array
    {
        $value = $this->facts[$fact] ?? null;

        if (! is_array($value)) {
            throw $this->wrong($fact, 'a list of strings');
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw $this->wrong($fact, 'a list of strings');
            }

            $out[] = $item;
        }

        return $out;
    }

    /** The logical id in a ref slot, or null when the slot is empty. */
    public function ref(string $slot): ?string
    {
        $value = $this->refs[$slot] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Every value this object carries, flattened, for the secret scanner.
     *
     * Keys are dotted so that a violation names the field an author has to go
     * and delete rather than merely the object it was in.
     *
     * @return array<string, scalar|null>
     */
    public function flattened(): array
    {
        $out = [];

        foreach ($this->facts as $key => $value) {
            if (is_array($value)) {
                foreach (array_values($value) as $index => $item) {
                    if (is_scalar($item) || $item === null) {
                        $out[$key.'.'.$index] = $item;
                    }
                }

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function wrong(string $fact, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Reference object %s: fact "%s" must be %s.',
            $this->id,
            $fact,
            $expected,
        ));
    }
}
