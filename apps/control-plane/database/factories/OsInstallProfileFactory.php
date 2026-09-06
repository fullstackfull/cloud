<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;

/**
 * @extends Factory<OsInstallProfile>
 */
class OsInstallProfileFactory extends Factory
{
    protected $model = OsInstallProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'ubuntu-2404-'.Str::lower(Str::random(6)),
            'name' => ['en' => 'Ubuntu 24.04 LTS', 'ar' => 'أوبنتو 24.04'],
            'os_family' => 'ubuntu',
            'os_version' => '24.04',
            'installer' => InstallerKind::Autoinstall,
            /*
             * A template with placeholders the renderer has to fill, so that a
             * test which forgets to pass a value sees the renderer refuse
             * rather than a machine being built with a literal "{{ hostname }}"
             * as its name.
             */
            'template' => implode("\n", [
                '#cloud-config',
                'autoinstall:',
                '  version: 1',
                '  identity:',
                '    hostname: {{ hostname }}',
                '  network:',
                '    ethernets:',
                '      primary:',
                '        addresses: [{{ ipv4_address }}/{{ ipv4_prefix_length }}]',
                '        gateway4: {{ ipv4_gateway }}',
            ]),
            'defaults' => ['timezone' => 'Asia/Kuwait'],
            'is_active' => true,
        ];
    }

    public function installer(InstallerKind $installer): static
    {
        return $this->state(fn (): array => ['installer' => $installer]);
    }

    /**
     * A template with no placeholders, for tests about something other than
     * rendering.
     */
    public function withoutPlaceholders(): static
    {
        return $this->state(fn (): array => ['template' => "#cloud-config\nautoinstall:\n  version: 1"]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
