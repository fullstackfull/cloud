<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Infrastructure\Domain\DTOs\ServerRegistration;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Writing down that a machine exists.
 *
 * There is deliberately no safety_class field and no allow_reimage field.
 * Registration cannot produce a touchable machine, and the way to make that
 * true is for the request to have no way of saying so — a rule enforced by the
 * absence of a field is one nobody can forget to check.
 *
 * The addresses are validated as hostnames or IPs rather than as URLs, and
 * never fetched here. Where a provider endpoint is a URL it goes through the
 * SSRF guard on the provider side; a management address is a target for a
 * tester, not something this layer opens.
 */
final class RegisterServerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9._-]*$/i', Rule::unique('managed_servers', 'name')],
            'environment' => ['required', Rule::enum(DeploymentEnvironment::class)],

            'datacenter_id' => ['nullable', 'string', Rule::exists('datacenters', 'id')],
            'rack_id' => ['nullable', 'string', Rule::exists('racks', 'id')],
            'rack_unit' => ['nullable', 'integer', 'min:1', 'max:60'],
            'height_units' => ['nullable', 'integer', 'min:1', 'max:20'],

            'vendor' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial' => ['nullable', 'string', 'max:120'],
            'asset_tag' => ['nullable', 'string', 'max:80'],

            'management_address' => ['nullable', 'string', 'max:255'],
            'management_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'bmc_address' => ['nullable', 'string', 'max:255'],
            'bmc_port' => ['nullable', 'integer', 'min:1', 'max:65535'],

            'operating_system' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function toRegistration(): ServerRegistration
    {
        return new ServerRegistration(
            name: $this->string('name')->value(),
            environment: DeploymentEnvironment::from($this->string('environment')->value()),
            datacenterId: $this->input('datacenter_id'),
            rackId: $this->input('rack_id'),
            rackUnit: $this->has('rack_unit') ? $this->integer('rack_unit') : null,
            heightUnits: $this->has('height_units') ? $this->integer('height_units') : null,
            vendor: $this->input('vendor'),
            model: $this->input('model'),
            serial: $this->input('serial'),
            assetTag: $this->input('asset_tag'),
            managementAddress: $this->input('management_address'),
            managementPort: $this->has('management_port') ? $this->integer('management_port') : null,
            bmcAddress: $this->input('bmc_address'),
            bmcPort: $this->has('bmc_port') ? $this->integer('bmc_port') : null,
            operatingSystem: $this->input('operating_system'),
            notes: $this->input('notes'),
        );
    }
}
