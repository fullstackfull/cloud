<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;

/**
 * The zone file as text and the mode. The browser reads an uploaded file
 * into the text field itself; the server takes text and nothing else, so
 * there is no upload handling, no temporary file, and one bound.
 */
final class PlanZoneImportRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:262144'],
            'mode' => ['sometimes', 'string', Rule::enum(ZoneImportMode::class)],
        ];
    }

    public function mode(): ZoneImportMode
    {
        return ZoneImportMode::from((string) $this->input('mode', ZoneImportMode::Merge->value));
    }

    public function text(): string
    {
        return (string) $this->input('text');
    }
}
