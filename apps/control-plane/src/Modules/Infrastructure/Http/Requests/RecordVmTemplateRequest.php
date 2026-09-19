<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;

/**
 * What an operator has to say to record an installable image.
 *
 * The slug is held to the platform's logical-identifier shape rather than to
 * "anything without a space": it is durable, it appears in a plan's
 * `placement_constraints`, and an order weeks old resolves its template by it.
 *
 * `provider_reference` is how the hypervisor names the image — a Proxmox VMID,
 * for instance — so it is held to the provider-native rule and not normalised:
 * case is the provider's to decide. Nullable, because a catalogue entry may
 * exist before the image is staged; placement refuses such a row rather than
 * building from nothing.
 *
 * Both display names are required. A catalogue entry with only one language is
 * one a customer in the other reads in a language they did not choose, and the
 * portal is bilingual by contract rather than by preference.
 */
final class RecordVmTemplateRequest extends FormRequest
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
            'cluster_id' => ['required', 'string', Rule::exists('compute_clusters', 'id')],

            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],

            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'max:120'],
            'name.ar' => ['required', 'string', 'max:120'],

            'os_family' => ['required', Rule::enum(OsFamily::class)],
            'os_version' => ['required', 'string', 'max:32'],
            'architecture' => ['required', Rule::enum(CpuArchitecture::class)],

            /*
             * Printable ASCII without whitespace, which is the platform's
             * provider-native name rule. Refusing whitespace is not
             * fussiness: the reference is interpolated into a provider path,
             * and a value with a newline in it would be two lines in a log
             * and an ambiguous request.
             */
            'provider_reference' => ['nullable', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],

            'cloud_init' => ['required', 'boolean'],
            'guest_agent' => ['required', 'boolean'],
            'requires_licence' => ['required', 'boolean'],
            'licence_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'A template slug is lower-case words joined by single dashes, because an order recorded weeks ago resolves its image by it.',
            'provider_reference.regex' => 'A provider reference is printable characters with no spaces, exactly as the hypervisor spells it.',
        ];
    }
}
