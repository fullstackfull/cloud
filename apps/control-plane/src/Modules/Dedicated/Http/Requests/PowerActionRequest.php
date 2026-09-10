<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
 * Why there is no Idempotency-Key here, when the reinstall endpoint requires
 * one
 * ---------------------------------------------------------------------------
 *
 * An idempotency key is a promise that a repeat is free. The platform can keep
 * that promise for a reinstall, because the engine's job table has a unique
 * index to keep it in — and it cannot keep it for a power request, which is
 * sent straight to a controller with nothing between the two to remember it.
 * A required header that the platform then ignored would be worse than no
 * header: a client would retry believing it was protected.
 *
 * What makes repeating these safe is the vocabulary instead. `on` and `off`
 * are levels rather than edges — repeating either converges on the state the
 * caller asked for — and `cycle` reads the chassis first, so a repeat against
 * a machine that has since gone down powers it on rather than resetting it a
 * second time. The one case a key would genuinely help with, two resets of a
 * running machine, is also the case where the customer pressing the button
 * twice usually meant it.
 */
final class PowerActionRequest extends FormRequest
{
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
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
