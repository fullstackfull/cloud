<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;

/**
 * The shape of an install profile. Whether a build could render it is the
 * action's question (RecordOsInstallProfile), asked with the renderer's own
 * pattern.
 */
final class RecordOsInstallProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Written once: a corrected recipe is a new profile, so an
            // existing slug is refused rather than rewritten.
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('os_install_profiles', 'slug')],

            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:120'],
            'name.ar' => ['required', 'string', 'max:120'],

            'os_family' => ['required', 'string', 'max:32'],
            'os_version' => ['required', 'string', 'max:32'],
            'installer' => ['required', Rule::enum(InstallerKind::class)],
            'template' => ['required', 'string', 'max:65535'],

            /*
             * A flat map of scalars. The renderer writes a value into a
             * placeholder with a string cast, and a nested value renders as
             * the word "Array" — which an installer reads as a hostname.
             */
            'defaults' => ['sometimes', 'nullable', 'array', 'max:64'],
            'defaults.*' => [
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_scalar($value) && $value !== null) {
                        $fail('Each default must be a single value.');
                    }
                },
            ],
        ];
    }
}
