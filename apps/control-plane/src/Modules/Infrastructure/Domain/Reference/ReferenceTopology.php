<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Reference;

/**
 * The reference estate, loaded, validated and addressable by logical id.
 *
 * ---------------------------------------------------------------------------
 * One definition, several consumers
 * ---------------------------------------------------------------------------
 *
 * There is exactly one place the reference estate is described:
 * resources/reference-topology/topology.php. The development seeder, the
 * simulation loader, the tests and the documentation all read it through this
 * class, which is the difference between a canonical source and five files that
 * happen to agree for now.
 *
 * ---------------------------------------------------------------------------
 * Validated on the way in, never on the way out
 * ---------------------------------------------------------------------------
 *
 * {@see load()} refuses an invalid topology rather than handing back a partly
 * usable one, so nothing downstream has to ask whether the graph resolves. A
 * caller that wants the list of problems instead of an exception asks
 * {@see ReferenceTopologyValidator} directly — that is what the validation test
 * does, so a broken topology produces every violation at once rather than the
 * first.
 *
 * ---------------------------------------------------------------------------
 * This object is not an inventory
 * ---------------------------------------------------------------------------
 *
 * {@see isDeployable()} and {@see isProduction()} exist so that the loader and
 * the guards can refuse it as data rather than trust a comment. Both must be
 * false and the validator will not accept a file where either is true.
 */
final readonly class ReferenceTopology
{
    public const string PATH = 'reference-topology/topology.php';

    /**
     * @param  array<string, ReferenceObject>  $objects  keyed by logical id
     * @param  array<string, mixed>  $marker
     */
    private function __construct(
        public string $name,
        public int $schemaVersion,
        public array $objects,
        private array $marker,
    ) {}

    /**
     * Read, validate and build the reference estate.
     *
     * @throws ReferenceTopologyInvalid
     */
    public static function load(?string $path = null, ?ReferenceTopologyValidator $validator = null): self
    {
        $file = $path ?? resource_path(self::PATH);

        if (! is_file($file)) {
            throw ReferenceTopologyInvalid::with([sprintf('There is no reference topology at %s.', $file)]);
        }

        /** @var mixed $raw */
        $raw = require $file;

        if (! is_array($raw)) {
            throw ReferenceTopologyInvalid::with(['The reference topology file must return an array.']);
        }

        return self::fromArray($raw, $validator ?? new ReferenceTopologyValidator);
    }

    /**
     * @param  array<mixed>  $raw
     *
     * @throws ReferenceTopologyInvalid
     */
    public static function fromArray(array $raw, ?ReferenceTopologyValidator $validator = null): self
    {
        $validator ??= new ReferenceTopologyValidator;

        $violations = $validator->violations($raw);

        if ($violations !== []) {
            throw ReferenceTopologyInvalid::with($violations);
        }

        $objects = [];

        /** @var array<string, array<string, array{facts?: array<string, mixed>, refs?: array<string, string|null>}>> $groups */
        $groups = $raw['objects'];

        foreach ($groups as $kindName => $group) {
            $kind = ReferenceKind::from($kindName);

            foreach ($group as $id => $body) {
                $objects[(string) $id] = new ReferenceObject(
                    kind: $kind,
                    id: (string) $id,
                    facts: $body['facts'] ?? [],
                    refs: $body['refs'] ?? [],
                );
            }
        }

        return new self(
            name: is_string($raw['name'] ?? null) ? $raw['name'] : 'Reference estate',
            schemaVersion: is_int($raw['schema_version'] ?? null) ? $raw['schema_version'] : 0,
            objects: $objects,
            marker: [
                'kind' => $raw['kind'] ?? null,
                'environment' => $raw['environment'] ?? null,
                'production' => $raw['production'] ?? null,
                'deployable' => $raw['deployable'] ?? null,
                'reachable' => $raw['reachable'] ?? null,
            ],
        );
    }

    /**
     * Every object of one kind, in file order.
     *
     * @return list<ReferenceObject>
     */
    public function of(ReferenceKind $kind): array
    {
        return array_values(array_filter(
            $this->objects,
            static fn (ReferenceObject $object): bool => $object->kind === $kind,
        ));
    }

    /** Exactly one object of a kind, for the kinds the estate has one of. */
    public function one(ReferenceKind $kind): ReferenceObject
    {
        $found = $this->of($kind);

        if (count($found) !== 1) {
            throw ReferenceTopologyInvalid::with([sprintf(
                'Expected exactly one %s in the reference topology, found %d.',
                $kind->value,
                count($found),
            )]);
        }

        return $found[0];
    }

    public function get(string $id): ReferenceObject
    {
        if (! isset($this->objects[$id])) {
            throw ReferenceTopologyInvalid::with([sprintf('No reference object is called %s.', $id)]);
        }

        return $this->objects[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->objects[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->objects);
    }

    public function isProduction(): bool
    {
        return $this->marker['production'] === true;
    }

    public function isDeployable(): bool
    {
        return $this->marker['deployable'] === true;
    }

    public function isReachable(): bool
    {
        return $this->marker['reachable'] === true;
    }

    /**
     * The words that go at the top of anything that shows this estate.
     *
     * A reference estate that is not labelled is a reference estate somebody
     * quotes, which is the failure this whole gap exists to prevent.
     */
    public function label(): string
    {
        return 'REFERENCE — NON-PRODUCTION';
    }
}
