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
     * The values both install handlers pass for every build — the machine's
     * name and its IPv4 address, prefix length and gateway — before anything
     * a job's own `install_variables` adds. ProvisionDedicatedHandler and
     * ReinstallDedicatedHandler each write these keys (the gateway only when
     * the subnet has one); a placeholder
     * outside them has to be covered by the profile's own defaults, or the
     * profile cannot be rendered by an order-driven build, which names no
     * extras. RecordOsInstallProfile refuses such a profile when it is
     * written. A key being passed is not the same as it having a value:
     * both install handlers omit the gateway for a subnet registered without
     * one, so a profile default can supply it. The address, prefix
     * length and hostname are the platform's alone, and a profile may not
     * default them (RecordOsInstallProfile).
     *
     * @var list<string>
     */
    public const array PLATFORM_VARIABLES = ['hostname', 'ipv4_address', 'ipv4_prefix_length', 'ipv4_gateway'];

    /**
     * The platform's alone: what a machine is called and which address IPAM
     * gave it. A profile default for one of these would be installed onto a
     * machine whenever the platform had no value to pass — an address IPAM
     * never allocated — so RecordOsInstallProfile refuses such a default.
     * The gateway is not here: it belongs to the subnet, and a subnet may be
     * registered without one, which a profile default may then supply.
     *
     * @var list<string>
     */
    public const array PLATFORM_OWNED = ['hostname', 'ipv4_address', 'ipv4_prefix_length'];

    /**
     * Every placeholder a template names, once each, in order of appearance.
     *
     * @return list<string>
     */
    public function placeholdersIn(string $template): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param  array<string, scalar|null>  $variables  The caller's values, which win over the profile's
     *                                                 defaults. A key passed as null is refused as
     *                                                 missing, not filled from a default; omit the key
     *                                                 to let a default apply.
     * @return array<string, mixed> The rendered configuration, ready to be handed to the boot server.
     *
     * @throws InstallProfileNotRenderableException
     */
    public function render(OsInstallProfile $profile, array $variables = []): array
    {
        if (! $profile->is_active) {
            throw InstallProfileNotRenderableException::inactiveProfile($profile->slug);
        }

        $this->assertSubstitutable($profile, $variables);

        /** @var array<string, scalar|null> $defaults */
        $defaults = $profile->defaults ?? [];

        /*
         * A caller's key wins, including when its value is null: a null the
         * caller passes means "this has no value", and the placeholder is
         * refused as missing below rather than filled from a default. That is
         * what stops a rebuild of a machine with no address being installed
         * onto one a profile default names (ReinstallDedicatedHandler passes
         * the machine's own address, null when it has none). A caller that
         * wants a default to apply omits the key — as both install handlers
         * do for the gateway of a subnet registered without one.
         */
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
     * A caller's value fills in a placeholder; it does not get to add
     * directives around it.
     *
     * The substitution below is deliberately literal, and an answer file is
     * line-oriented: kickstart, preseed and autoinstall all end a directive at
     * a newline. So a caller value carrying CR or LF writes new instructions
     * into the file — `%post --interpreter=/bin/bash` is root on a physical
     * host, during an install, before the customer has ever logged in. There
     * is no one escaping that is correct for all three installers, so such a
     * value is refused and no file is emitted.
     *
     * The profile's own defaults are not checked: they are authored by an
     * operator alongside the template itself and are legitimately multi-line.
     *
     * @param  array<string, scalar|null>  $variables
     *
     * @throws InstallProfileNotRenderableException
     */
    private function assertSubstitutable(OsInstallProfile $profile, array $variables): void
    {
        foreach ($variables as $key => $value) {
            if (is_string($value) && preg_match('/[\r\n]/', $value) === 1) {
                throw InstallProfileNotRenderableException::unsafeVariable($profile->slug, (string) $key);
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return list<string>
     */
    private function unresolvedKeys(string $template, array $values): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);

        $missing = [];

        foreach ($matches[1] as $key) {
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
