<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;

/**
 * One power action, and an idempotency key.
 *
 * The allow-list is the four cases of {@see PowerAction} and nothing else. In
 * particular there is no `force` flag and no `reset`: a boolean that turns a
 * graceful request into a hard one is a boolean some client library will
 * default, and the whole reason `stop` and `shutdown` are separate words on
 * this API is so that the destructive one has to be asked for by name.
 *
 * There is no machine id in the body either. The machine is the one in the
 * path, resolved through the acting customer's own services; an id in the body
 * would be a second, unscoped way to name a target.
 */
final class PowerActionRequest extends FormRequest
{
    use ReadsIdempotencyKey;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Rule::enum rather than a free string. An unrecognised verb is a
             * 422 naming the four that exist, not a silently ignored request
             * that leaves a customer believing their machine is rebooting.
             */
            'action' => ['required', Rule::enum(PowerAction::class)],
        ] + $this->idempotencyKeyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessages() + [
            'action.required' => 'Name the power action: start, stop, reboot or shutdown.',
        ];
    }

    public function action(): PowerAction
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return PowerAction::from((string) $validated['action']);
    }
}
