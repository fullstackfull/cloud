<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Services;

use Lynomia\Modules\Infrastructure\Domain\DTOs\ProfileDefinition;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;

/**
 * What an operator may hand a playbook, and the shape it must have.
 *
 * Keys: only what the profile's components declare in `accepts`, written as
 * `component.key`. Values: one line, printable ASCII, no quotes, no shell or
 * template syntax. The alternative — letting an admin field become an
 * argument to a playbook — turns the control panel into a remote shell with
 * a nicer font, which is the sentence on the migration this table came from.
 */
final readonly class OverrideRules
{
    public const string VALUE_SHAPE = '/^[A-Za-z0-9][A-Za-z0-9 ._:\/@+-]{0,119}$/';

    private const array FORBIDDEN_FRAGMENTS = ['{{', '}}', '{%', '$(', '`', '&&', '||', ';', '|', '>', '<', '\\'];

    public function __construct(
        private SoftwareCatalogue $catalogue,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, string> The same overrides, accepted, as strings.
     */
    public function validate(ProfileDefinition $profile, array $overrides): array
    {
        $declared = [];

        foreach ($this->catalogue->componentsOf($profile) as $component) {
            foreach ($component->accepts as $key) {
                $declared[$component->key.'.'.$key] = true;
            }
        }

        $offending = array_values(array_filter(array_keys($overrides), static fn (string|int $k): bool => ! isset($declared[(string) $k])));

        if ($offending !== []) {
            throw DeploymentRefused::overridesNotAccepted(array_map(strval(...), $offending));
        }

        $accepted = [];

        foreach ($overrides as $key => $value) {
            if (! is_scalar($value)) {
                throw DeploymentRefused::overrideValueRefused((string) $key);
            }

            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            if (preg_match(self::VALUE_SHAPE, $text) !== 1) {
                throw DeploymentRefused::overrideValueRefused((string) $key);
            }

            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains($text, $fragment)) {
                    throw DeploymentRefused::overrideValueRefused((string) $key);
                }
            }

            $accepted[(string) $key] = $text;
        }

        ksort($accepted);

        return $accepted;
    }
}
