<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Services;

use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;

/**
 * Turns a stored install profile into the answer file an installer will read.
 *
 * Substitution is deliberately dumb: `{{ name }}` placeholders, values from
 * the profile's defaults overlaid with the caller's, and nothing else. There
 * is no expression language and no conditional, because the output of this
 * class decides how a customer's disks are partitioned and a templating
 * language is a place for logic nobody reviews.
 *
 * Two rules make it safe to run unattended:
 *
 *  - **an unresolved placeholder is fatal.** An autoinstall file containing a
 *    literal "{{ hostname }}" does not fail where the mistake was made: the
 *    installer runs, partitions the disks and produces a machine that is wrong
 *    in a way only discoverable after whatever was on those disks is gone;
 *
 *  - **an inactive profile is refused at render time**, not merely at
 *    selection time. A profile withdrawn because it partitions wrongly has to
 *    stop being used by the jobs that were queued before somebody noticed.
 */
final readonly class InstallProfileRenderer
{
    /** Matches "{{ key }}" with any amount of surrounding whitespace. */
    private const string PLACEHOLDER_PATTERN = '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/';

    /**
     * @param  array<string, scalar|null>  $variables  The caller's values, which win over the profile's
     *                                                 defaults.
     * @return array<string, mixed> The rendered configuration, ready to be handed to the boot server.
     *
     * @throws InstallProfileNotRenderableException
     */
    public function render(OsInstallProfile $profile, array $variables = []): array
    {
        if (! $profile->is_active) {
            throw InstallProfileNotRenderableException::inactiveProfile($profile->slug);
        }

        /** @var array<string, scalar|null> $defaults */
        $defaults = $profile->defaults ?? [];

        $values = [...$defaults, ...$variables];

        $missing = $this->unresolvedKeys($profile->template, $values);

        if ($missing !== []) {
            throw InstallProfileNotRenderableException::missingVariables($profile->slug, $missing);
        }

        return [
            'profile' => $profile->slug,
            'installer' => $profile->installer->value,
            'os_family' => $profile->os_family,
            'os_version' => $profile->os_version,
            'filename' => $profile->installer->configFilename(),
            'kernel_parameter' => $profile->installer->kernelParameter(),
            'template' => $this->substitute($profile->template, $values),
            /*
             * The values are carried alongside the rendered file so that an
             * operator can see what a machine was built with. They pass
             * through the shared redactor when the authorisation row is
             * written — an answer file legitimately carries a password hash,
             * and a careless caller may pass a plain one, and this table must
             * not become where the platform keeps customers' credentials.
             */
            'variables' => $values,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return list<string>
     */
    private function unresolvedKeys(string $template, array $values): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);

        $missing = [];

        foreach ($matches[1] ?? [] as $key) {
            // A key present but null is still missing: "null" written into a
            // preseed is a literal four-character answer, not an absent one.
            if (! array_key_exists($key, $values) || $values[$key] === null) {
                $missing[$key] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function substitute(string $template, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $matches) use ($values): string {
                $value = $values[$matches[1]] ?? '';

                // Booleans are written as the words installers understand
                // rather than as PHP's "1" and "", which a kickstart reads as
                // a truthy string either way.
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                return (string) $value;
            },
            $template,
        );
    }
}
