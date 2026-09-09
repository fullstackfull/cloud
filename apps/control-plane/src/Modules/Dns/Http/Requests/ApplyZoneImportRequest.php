<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;

/**
 * The same text and mode the preview was given, plus the fingerprint the
 * preview handed back. The fingerprint is what makes the apply the plan
 * the customer read, and not a recomputation they never saw.
 */
final class ApplyZoneImportRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:262144'],
            'mode' => ['sometimes', 'string', Rule::enum(ZoneImportMode::class)],
            'fingerprint' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
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

    /**
     * Named for what it is: the Request base class has its own fingerprint()
     * for something else entirely.
     */
    public function planFingerprint(): string
    {
        return (string) $this->input('fingerprint');
    }
}
