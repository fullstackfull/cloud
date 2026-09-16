<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Reference;

use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Shared\Domain\Services\ReferenceValues;

/**
 * The one validator. Used by the tests, and through them by CI.
 *
 * ---------------------------------------------------------------------------
 * What it refuses, and why each refusal is here
 * ---------------------------------------------------------------------------
 *
 *   - A missing or dishonest marker. `production: true` in this file would make
 *     every downstream guard read the reference estate as the real one, so the
 *     marker is checked before anything else and the whole file is rejected.
 *
 *   - An unknown kind, a malformed id, a duplicate id. Ids are unique across
 *     kinds and not merely within one, because refs name an id and nothing
 *     else: two objects sharing an id would make a ref ambiguous.
 *
 *   - A ref that names nothing, names the wrong kind, or is not a slot this
 *     kind has. This is the graph integrity check, and it resolves every
 *     reference exactly once.
 *
 *   - An orphan. Every object must be reachable from the region by following
 *     refs. An object nobody points at and which points at nothing is a fact
 *     that will never be loaded and will slowly stop being true.
 *
 *   - A production value. In this file the polarity is inverted from everywhere
 *     else in the platform: an address that is NOT documentation is forbidden,
 *     because an address that is not obviously an example is an address
 *     somebody may eventually route. Same for hostnames.
 *
 *   - A secret. Any field whose name suggests a credential, and any value that
 *     looks like a key or a token, whatever it is called.
 *
 *   - A monitoring target naming a collector class this application does not
 *     have. Gap 3 found alert rules reading series nothing exported; an alert
 *     over an absent series is silent, which reads exactly like passing. The
 *     reference estate is not allowed to make the same claim.
 *
 * Returns a list of sentences rather than throwing, so one run reports every
 * problem. {@see ReferenceTopology::load()} is the throwing caller.
 */
final readonly class ReferenceTopologyValidator
{
    /**
     * Field names that are a credential rather than a fact about one.
     *
     * @var list<string>
     */
    private const array SECRET_NAMES = [
        'password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'api_secret',
        'private_key', 'ssh_key', 'authorization', 'auth_code', 'credential',
        'credentials', 'credential_reference', 'credentials_reference', 'username',
        'user', 'passphrase', 'bearer', 'session', 'cookie', 'signature',
    ];

    /**
     * Value shapes that are a key or a token regardless of the field's name.
     *
     * @var list<string>
     */
    private const array SECRET_SHAPES = [
        '-----BEGIN', 'ssh-rsa ', 'ssh-ed25519 ', 'sk_live_', 'sk_test_', 'Bearer ',
    ];

    /** Collector class names this application actually registers. */
    private const string COLLECTOR_NAMESPACE = 'Lynomia\\Modules\\Monitoring\\Application\\Collectors\\';

    public function __construct(
        private ReferenceValues $values = new ReferenceValues,
        private ProviderCatalogue $catalogue = new ProviderCatalogue,
    ) {}

    /**
     * @param  array<mixed>  $raw
     * @return list<string>
     */
    public function violations(array $raw): array
    {
        $problems = $this->marker($raw);

        if (! isset($raw['objects']) || ! is_array($raw['objects'])) {
            $problems[] = 'The topology has no `objects` map.';

            return $problems;
        }

        [$objects, $shapeProblems] = $this->parse($raw['objects']);
        $problems = [...$problems, ...$shapeProblems];

        if ($objects === []) {
            $problems[] = 'The topology describes no objects at all.';

            return $problems;
        }

        return [
            ...$problems,
            ...$this->facts($objects),
            ...$this->graph($objects),
            ...$this->orphans($objects),
            ...$this->addresses($objects),
            ...$this->secrets($objects),
            ...$this->monitoring($objects),
            ...$this->providers($objects),
        ];
    }

    /**
     * @param  array<mixed>  $raw
     * @return list<string>
     */
    private function marker(array $raw): array
    {
        $problems = [];

        if (($raw['kind'] ?? null) !== ReferenceValues::MARKER_KIND) {
            $problems[] = sprintf('`kind` must be "%s", so that a machine can tell what this file is.', ReferenceValues::MARKER_KIND);
        }

        if (($raw['environment'] ?? null) !== 'reference') {
            $problems[] = '`environment` must be "reference".';
        }

        if (! is_int($raw['schema_version'] ?? null)) {
            $problems[] = '`schema_version` must be an integer.';
        }

        foreach (['production', 'deployable', 'reachable'] as $flag) {
            if (! array_key_exists($flag, $raw) || ! is_bool($raw[$flag])) {
                $problems[] = sprintf('`%s` must be present and boolean.', $flag);

                continue;
            }

            if ($raw[$flag] === true) {
                $problems[] = sprintf(
                    '`%s` is true. The reference topology is a model of an estate and may never claim to be one: nothing here exists, nothing here is reachable, and nothing here may be deployed.',
                    $flag,
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $groups
     * @return array{array<string, ReferenceObject>, list<string>}
     */
    private function parse(array $groups): array
    {
        $objects = [];
        $problems = [];

        foreach ($groups as $kindName => $group) {
            if (! is_string($kindName) || ReferenceKind::tryFrom($kindName) === null) {
                $problems[] = sprintf(
                    '"%s" is not a kind this platform models. Add a case to ReferenceKind only when the domain already has the concept.',
                    is_string($kindName) ? $kindName : gettype($kindName),
                );

                continue;
            }

            if (! is_array($group)) {
                $problems[] = sprintf('The `%s` group must be a map of logical id to object.', $kindName);

                continue;
            }

            $kind = ReferenceKind::from($kindName);

            foreach ($group as $id => $body) {
                $id = (string) $id;

                if (! $this->values->isCanonicalReferenceIdentifier($id)) {
                    $problems[] = sprintf(
                        '"%s" is not a reference logical id. Every id is `ref-` followed by lower-case words separated by single hyphens, so that one grep finds all of them.',
                        $id,
                    );
                }

                if (isset($objects[$id])) {
                    $problems[] = sprintf('"%s" is declared twice. Ids are unique across every kind, because a ref names an id and nothing else.', $id);

                    continue;
                }

                if (! is_array($body)) {
                    $problems[] = sprintf('"%s" must be a map with `facts` and `refs`.', $id);

                    continue;
                }

                $facts = $body['facts'] ?? [];
                $refs = $body['refs'] ?? [];

                if (! is_array($facts) || ! is_array($refs)) {
                    $problems[] = sprintf('"%s" must have array `facts` and array `refs`.', $id);

                    continue;
                }

                /*
                 * Normalised here, so that ReferenceObject's declared types are
                 * a fact rather than a promise. Everything downstream — the
                 * graph walk, the orphan search, the loader — reads `refs` as
                 * a map of string to string-or-null, and the only honest place
                 * to establish that is where the raw array is turned into an
                 * object. Re-checking the types later would be checking a
                 * promise already made, which is what it was before.
                 */
                $shaped = true;
                $cleanFacts = [];
                $cleanRefs = [];

                foreach ($facts as $key => $value) {
                    if (! is_string($key)) {
                        $problems[] = sprintf('"%s" has a fact whose name is not a string.', $id);
                        $shaped = false;

                        continue;
                    }

                    $cleanFacts[$key] = $value;
                }

                foreach ($refs as $slot => $target) {
                    if (! is_string($slot)) {
                        $problems[] = sprintf('"%s" has a reference slot whose name is not a string.', $id);
                        $shaped = false;

                        continue;
                    }

                    if ($target !== null && ! is_string($target)) {
                        $problems[] = sprintf('%s.%s names a %s rather than a logical id.', $id, $slot, gettype($target));
                        $shaped = false;

                        continue;
                    }

                    $cleanRefs[$slot] = $target;
                }

                if (! $shaped) {
                    continue;
                }

                $objects[$id] = new ReferenceObject($kind, $id, $cleanFacts, $cleanRefs);
            }
        }

        return [$objects, $problems];
    }

    /**
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function facts(array $objects): array
    {
        $problems = [];

        foreach ($objects as $object) {
            foreach ($object->kind->requiredFacts() as $fact) {
                if (! array_key_exists($fact, $object->facts)) {
                    $problems[] = sprintf('%s is a %s and has no `%s`.', $object->id, $object->kind->value, $fact);
                }
            }
        }

        return $problems;
    }

    /**
     * Every reference resolves, exactly once, to an object of the right kind.
     *
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function graph(array $objects): array
    {
        $problems = [];

        foreach ($objects as $object) {
            $slots = $object->kind->refSlots();

            foreach ($object->refs as $slot => $target) {
                if (! isset($slots[$slot])) {
                    $problems[] = sprintf(
                        '%s points at something through a slot called "%s", which a %s does not have.',
                        $object->id,
                        $slot,
                        $object->kind->value,
                    );

                    continue;
                }

                if ($target === null) {
                    continue;
                }

                if (! isset($objects[$target])) {
                    $problems[] = sprintf(
                        '%s.%s names "%s", and there is no such object. A reference that resolves to nothing is the one thing a topology may never contain.',
                        $object->id,
                        $slot,
                        $target,
                    );

                    continue;
                }

                $expected = $slots[$slot]['kind'];

                if ($expected !== null && $objects[$target]->kind !== $expected) {
                    $problems[] = sprintf(
                        '%s.%s names %s, which is a %s and not a %s.',
                        $object->id,
                        $slot,
                        $target,
                        $objects[$target]->kind->value,
                        $expected->value,
                    );
                }
            }

            foreach ($slots as $slot => $rule) {
                if ($rule['required'] && $object->ref($slot) === null) {
                    $problems[] = sprintf('%s is a %s and must name its %s.', $object->id, $object->kind->value, $slot);
                }
            }
        }

        return $problems;
    }

    /**
     * Nothing floats.
     *
     * Reachability is computed from every region by following refs in both
     * directions — an object is connected if it points at a connected object or
     * a connected object points at it. A machine hanging off a datacenter is
     * connected downward; a monitoring target hanging off a cluster is
     * connected upward. Either is fine; neither is optional.
     *
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function orphans(array $objects): array
    {
        $edges = [];

        foreach ($objects as $object) {
            foreach ($object->refs as $target) {
                if ($target !== null && isset($objects[$target])) {
                    $edges[$object->id][] = $target;
                    $edges[$target][] = $object->id;
                }
            }
        }

        $roots = array_keys(array_filter(
            $objects,
            static fn (ReferenceObject $object): bool => $object->kind === ReferenceKind::Region,
        ));

        if ($roots === []) {
            return ['The topology has no region, so nothing in it has a site.'];
        }

        $seen = [];
        $queue = $roots;

        while ($queue !== []) {
            $id = array_pop($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($edges[$id] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $queue[] = $next;
                }
            }
        }

        $problems = [];

        foreach (array_keys($objects) as $id) {
            if (! isset($seen[$id])) {
                $problems[] = sprintf(
                    '%s is not connected to any region. An object nothing reaches is a fact that will never be loaded and will quietly stop being true.',
                    $id,
                );
            }
        }

        return $problems;
    }

    /**
     * Addresses here may only be documentation addresses.
     *
     * The inverse of the production guard, and the same classifier. A reference
     * file that carried a routable address would be a reference file somebody
     * could accidentally point an installation at, and it would be in git
     * forever.
     *
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function addresses(array $objects): array
    {
        $problems = [];
        $addressFields = ['address', 'management_address', 'bmc_address', 'gateway', 'cidr', 'hostname', 'endpoint', 'api_endpoint'];

        foreach ($objects as $object) {
            if ($object->kind->carriesAddresses() && ($object->facts['reference_only'] ?? null) !== true) {
                $problems[] = sprintf(
                    '%s carries an address and does not state `reference_only: true`. The flag is what says out loud that the address exists to be parsed and never to be reached.',
                    $object->id,
                );
            }

            foreach ($addressFields as $field) {
                $value = $object->facts[$field] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                // A fake:// marker is not an address at all: no socket is ever
                // opened for one, and the endpoint policy already requires the
                // shape.
                if (str_starts_with($value, 'fake://')) {
                    continue;
                }

                $host = str_contains($value, '/') && ! str_contains($value, '://')
                    ? explode('/', $value)[0]  // a CIDR
                    : $value;

                if ($this->values->isDocumentationAddress($host) || $this->values->isDocumentationHostname($host)) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s.%s is "%s", which is neither a documentation address nor a name under a reserved domain. The reference topology may only carry values that belong to nobody.',
                    $object->id,
                    $field,
                    $value,
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function secrets(array $objects): array
    {
        $problems = [];

        foreach ($objects as $object) {
            foreach ($object->flattened() as $field => $value) {
                $name = strtolower(explode('.', $field)[0]);

                if (in_array($name, self::SECRET_NAMES, strict: true)) {
                    $problems[] = sprintf(
                        '%s has a field called "%s". The reference topology carries structure, never a credential or half of one — a credential lives in the credential centre and is attached by an operator.',
                        $object->id,
                        $field,
                    );

                    continue;
                }

                if (! is_string($value)) {
                    continue;
                }

                foreach (self::SECRET_SHAPES as $shape) {
                    if (str_starts_with($value, $shape)) {
                        $problems[] = sprintf(
                            '%s.%s holds a value shaped like a key or a token, whatever the field is called.',
                            $object->id,
                            $field,
                        );

                        break;
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Provider rows may only ever name a controlled driver.
     *
     * This is the check that makes §16 structural rather than a convention: a
     * reference estate whose provider row said `proxmox` would be a reference
     * estate something could dial with a real credential, and it would look
     * exactly like the estate that could not. The driver has to be in the
     * catalogue, has to be one the catalogue itself names as controlled, and
     * has to be catalogued for the category the row claims.
     *
     * Dependencies are checked against the same vocabulary. `requires` must be
     * a real provider category, and a dependency claiming to be satisfied by a
     * provider row must name one of that category — which is what stops "this
     * estate can rehearse backups" from being said by accident. Which
     * categories a PRODUCT needs remains ProductRequirements' answer and is not
     * restated here; these objects say only which of them this estate can
     * stand in for.
     *
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function providers(array $objects): array
    {
        $problems = [];
        $controlled = $this->catalogue->controlledDrivers();

        foreach ($objects as $object) {
            if ($object->kind === ReferenceKind::Provider) {
                $driver = is_string($object->facts['driver'] ?? null) ? $object->facts['driver'] : '';
                $category = is_string($object->facts['category'] ?? null) ? $object->facts['category'] : '';
                $entry = $this->catalogue->find($driver);

                if ($entry === null) {
                    $problems[] = sprintf('%s names the driver "%s", which is not in the provider catalogue.', $object->id, $driver);

                    continue;
                }

                if (! in_array($driver, $controlled, strict: true)) {
                    $problems[] = sprintf(
                        '%s names "%s", which is a real driver. The reference estate may only ever name a controlled driver (%s), because a reference row pointed at a real adapter is a row something can dial.',
                        $object->id,
                        $driver,
                        implode(', ', $controlled),
                    );
                }

                if ($entry->category->value !== $category) {
                    $problems[] = sprintf(
                        '%s claims category "%s" and the catalogue has "%s" catalogued as %s.',
                        $object->id,
                        $category,
                        $driver,
                        $entry->category->value,
                    );
                }

                if (($object->facts['environment'] ?? null) === 'production') {
                    $problems[] = sprintf('%s is declared for production. No reference row may be.', $object->id);
                }

                continue;
            }

            if ($object->kind !== ReferenceKind::Dependency) {
                continue;
            }

            $requires = is_string($object->facts['requires'] ?? null) ? $object->facts['requires'] : '';
            $wanted = ProviderCategory::tryFrom($requires);

            if ($wanted === null) {
                $problems[] = sprintf('%s requires "%s", which is not a provider category this platform has.', $object->id, $requires);

                continue;
            }

            $satisfier = $object->ref('satisfied_by');

            if ($satisfier === null || ! isset($objects[$satisfier])) {
                continue;
            }

            if (($objects[$satisfier]->facts['category'] ?? null) !== $wanted->value) {
                $problems[] = sprintf(
                    '%s requires %s and says %s satisfies it, but that provider is not a %s provider.',
                    $object->id,
                    $wanted->value,
                    $satisfier,
                    $wanted->value,
                );
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, ReferenceObject>  $objects
     * @return list<string>
     */
    private function monitoring(array $objects): array
    {
        $problems = [];

        foreach ($objects as $object) {
            if ($object->kind !== ReferenceKind::MonitoringTarget) {
                continue;
            }

            $collectors = $object->facts['collectors'] ?? null;

            if (! is_array($collectors) || $collectors === []) {
                $problems[] = sprintf('%s names no collector, so it does not answer which collectors apply.', $object->id);

                continue;
            }

            foreach ($collectors as $collector) {
                if (! is_string($collector)) {
                    $problems[] = sprintf('%s.collectors holds a value that is not a class name.', $object->id);

                    continue;
                }

                $class = self::COLLECTOR_NAMESPACE.$collector;

                if (! class_exists($class) || ! is_a($class, MetricsCollector::class, allow_string: true)) {
                    $problems[] = sprintf(
                        '%s expects %s to export its series, and this application has no such collector. A monitoring relationship that names nothing is exactly the silence Gap 3 found.',
                        $object->id,
                        $collector,
                    );
                }
            }
        }

        return $problems;
    }
}
