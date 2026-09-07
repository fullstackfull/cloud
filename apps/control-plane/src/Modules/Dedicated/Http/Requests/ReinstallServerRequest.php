<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;
use Lynomia\Modules\Dedicated\Application\Actions\RequestDedicatedReinstall;

/**
 * The confirmation, an optional operating system, and an idempotency key.
 *
 * `confirm_serial` is a string that must equal the machine's own serial
 * number, and it is a string on purpose. The obvious alternative —
 * `"confirm": true` — is a field every generated client sets in its
 * constructor, every convenience wrapper defaults and every retry loop
 * resends, without a person ever seeing it.
 *
 * The comparison itself is not here: this class only guarantees the field was
 * sent and is a plausible serial. Whether it names *this* machine is decided
 * by {@see RequestDedicatedReinstall},
 * so an operator tool or a support script calling the action directly is held
 * to the same proof.
 *
 * There is no `preserve_data`, no `keep_disks` and no `snapshot_first`. A
 * reinstall erases every disk in the chassis; an option that sometimes did not
 * would make the dangerous operation the one whose behaviour depends on a flag.
 *
 * The operating system is named by its public slug and existence-checked
 * against the profiles that are actually active, rather than taken as an id. A
 * ULID in a request body is a way of asking for a build profile the platform
 * has withdrawn — or of discovering, from the difference between a 422 and a
 * 500, which ids exist.
 */
final class ReinstallServerRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm_serial' => ['required', 'string', 'max:255'],

            /*
             * Optional — omitted means "whatever this machine is meant to run"
             * and leaves the choice to the operator profile the build used.
             * Constrained to active profiles so a withdrawn image cannot be
             * installed by direct reference months after it stopped being
             * offered.
             */
            'os_profile' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::exists('os_install_profiles', 'slug')->where('is_active', true),
            ],
        ] + $this->idempotencyKeyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessages() + [
            'confirm_serial.required' => 'Reinstalling erases every disk in this machine. Send confirm_serial with the server\'s serial number to confirm.',
            'os_profile.exists' => 'That operating system is not available for installation.',
        ];
    }

    public function confirmation(): string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return (string) $validated['confirm_serial'];
    }

    public function osProfileSlug(): ?string
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $slug = $validated['os_profile'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
