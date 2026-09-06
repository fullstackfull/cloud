<?php

declare(strict_types=1);

namespace Tests\Unit\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
use Lynomia\Modules\Dedicated\Domain\Exceptions\InstallProfileNotRenderableException;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Turning a stored profile into the answer file an installer reads.
 *
 * The output of this class decides how a customer's disks are partitioned, so
 * it substitutes placeholders and does nothing else — and refuses outright
 * rather than emitting a template with holes in it.
 */
final class InstallProfileRendererTest extends TestCase
{
    #[Test]
    public function it_substitutes_placeholders_from_defaults_and_arguments(): void
    {
        $rendered = $this->render(
            template: "hostname: {{ hostname }}\ntimezone: {{ timezone }}",
            defaults: ['timezone' => 'Asia/Kuwait'],
            variables: ['hostname' => 'ded-01'],
        );

        $this->assertSame("hostname: ded-01\ntimezone: Asia/Kuwait", $rendered['template']);
    }

    #[Test]
    public function a_supplied_value_wins_over_the_profiles_default(): void
    {
        $rendered = $this->render(
            template: 'timezone: {{ timezone }}',
            defaults: ['timezone' => 'Asia/Kuwait'],
            variables: ['timezone' => 'UTC'],
        );

        $this->assertSame('timezone: UTC', $rendered['template']);
    }

    #[Test]
    public function an_unfilled_placeholder_is_refused_rather_than_written_out_literally(): void
    {
        try {
            $this->render(template: 'hostname: {{ hostname }}', variables: []);

            $this->fail('A template with a hole in it was rendered.');
        } catch (InstallProfileNotRenderableException $e) {
            /*
             * An answer file containing a literal "{{ hostname }}" does not
             * fail where the mistake was made: the installer runs, partitions
             * the disks, and produces a machine that is wrong in a way only
             * discoverable after whatever was on those disks is gone.
             */
            $this->assertStringContainsString('hostname', $e->getMessage());
        }
    }

    #[Test]
    public function a_null_value_counts_as_missing(): void
    {
        // "null" written into a preseed is a literal four-character answer, not
        // an absent one.
        $this->expectException(InstallProfileNotRenderableException::class);

        $this->render(template: 'gateway: {{ gateway }}', variables: ['gateway' => null]);
    }

    #[Test]
    public function every_missing_key_is_named_at_once(): void
    {
        try {
            $this->render(template: '{{ a }} {{ b }} {{ c }}', variables: ['b' => 'set']);

            $this->fail('A template with holes in it was rendered.');
        } catch (InstallProfileNotRenderableException $e) {
            // Reporting one at a time would mean three round trips through a
            // failed provisioning job to discover three typos.
            $this->assertStringContainsString('a, c', $e->getMessage());
        }
    }

    #[Test]
    public function booleans_are_written_as_the_words_installers_understand(): void
    {
        $rendered = $this->render(
            template: 'allow_reimage: {{ allow_reimage }}',
            variables: ['allow_reimage' => true],
        );

        // PHP would cast these to "1" and "", and a kickstart reads a non-empty
        // string as truthy either way — so "false" would silently mean true.
        $this->assertSame('allow_reimage: true', $rendered['template']);

        $this->assertSame(
            'allow_reimage: false',
            $this->render(template: 'allow_reimage: {{ allow_reimage }}', variables: ['allow_reimage' => false])['template'],
        );
    }

    #[Test]
    public function a_withdrawn_profile_is_refused_at_render_time_not_only_at_selection(): void
    {
        $profile = $this->profile('version: 1');
        $profile->is_active = false;

        // A profile withdrawn because it partitions wrongly has to stop being
        // used by the jobs that were queued before somebody noticed.
        $this->expectException(InstallProfileNotRenderableException::class);

        app(InstallProfileRenderer::class)->render($profile);
    }

    #[Test]
    public function the_rendered_output_carries_what_the_boot_server_needs(): void
    {
        $profile = $this->profile('version: 1');
        $profile->installer = InstallerKind::Kickstart;

        $rendered = app(InstallProfileRenderer::class)->render($profile);

        // The filename and the kernel argument are part of the contract with
        // the boot server, not cosmetic detail: the kernel command line points
        // at the file by name.
        $this->assertSame('ks.cfg', $rendered['filename']);
        $this->assertSame('inst.ks=', $rendered['kernel_parameter']);
        $this->assertSame('kickstart', $rendered['installer']);
    }

    /**
     * @param  array<string, scalar|null>  $defaults
     * @param  array<string, scalar|null>  $variables
     * @return array<string, mixed>
     */
    private function render(string $template, array $defaults = [], array $variables = []): array
    {
        return app(InstallProfileRenderer::class)->render($this->profile($template, $defaults), $variables);
    }

    /**
     * An unsaved model: rendering is a pure function of the row and needs no
     * database.
     *
     * @param  array<string, scalar|null>  $defaults
     */
    private function profile(string $template, array $defaults = []): OsInstallProfile
    {
        $profile = new OsInstallProfile;

        $profile->forceFill([
            'slug' => 'test-profile',
            'name' => ['en' => 'Test'],
            'os_family' => 'ubuntu',
            'os_version' => '24.04',
            'installer' => InstallerKind::Autoinstall,
            'template' => $template,
            'defaults' => $defaults,
            'is_active' => true,
        ]);

        return $profile;
    }
}
