<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Infrastructure\Domain\Enums\GpuPassthroughMode;

final class RegisterGpuDeviceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'vendor' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'model' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\/-]*$/'],
            'vram_mib' => ['required', 'integer', 'min:256', 'max:1048576'],
            // Domain:bus:device.function, as the host reports it.
            'pci_address' => ['required', 'string', 'regex:/^[0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-7]$/'],
            'passthrough_mode' => ['required', Rule::enum(GpuPassthroughMode::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
