<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Lynomia\Http\Concerns\ReadsIdempotencyKey;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;

/**
 * One power action, named from a closed list.
 *
 * The allow-list is the three cases of {@see DedicatedPowerAction} and nothing
 * else. There is no `force` flag, no `hard` boolean and no raw command field:
 * the value is validated into an enum here and dispatched by `match` in the
 * action, so nothing a caller sends is ever assembled into a request to the
 * management controller. The IPMI adapter runs a process, and a string that
 * reached it would be a customer choosing what the platform executes on its
 * own management network.
 *
 * There is no server id in the body. The machine is the one in the path,
 * resolved through the acting customer's own relation; an id in the body would
 * be a second, unscoped way to name a target.
 *
 * ---------------------------------------------------------------------------
 * The Idempotency-Key, and why it is here now
 * ---------------------------------------------------------------------------
 *
 * This endpoint used to refuse the header, and the reasoning was sound at the
 * time: an idempotency key is a promise that a repeat is free, the platform
 * had nowhere to keep that promise for a request sent straight to a
 * controller, and a required header that was then ignored would be worse than
 * none because a client would retry believing it was protected.
 *
 * The argument was about a missing table, not about the header. The vocabulary
 * covered most of it — `on` and `off` are levels rather than edges, and
 * `cycle` reads the chassis first — but not the case that costs something: two
 * `cycle` requests against a running machine send two resets, and the second
 * interrupts the boot the first one started. The final power state is
 * identical either way, which is what made it invisible.
 *
 * `dedicated_power_operations` is that table. The key is claimed there before
 * the controller is called, so the promise is one the platform can keep, and
 * it is required here for the same reason it is required on the reinstall
 * route: an operation that cannot be safely repeated must be something the
 * caller can identify.
 *
 * The promise holds for a request that died mid-call too. Its claim is
 * answered as indeterminate once its lease lapses, so a caller repeating the
 * key gets an answer rather than a refusal for ever — and never a second
 * instruction to the chassis.
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
             * 422 naming the three that exist, not a silently ignored request
             * that leaves a customer believing their server is rebooting.
             */
            'action' => ['required', Rule::enum(DedicatedPowerAction::class)],
        ] + $this->idempotencyKeyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->idempotencyKeyMessages() + [
            'action.required' => __('validation.requests.dedicated.power_action_required'),
        ];
    }

    public function action(): DedicatedPowerAction
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return DedicatedPowerAction::from((string) $validated['action']);
    }
}
