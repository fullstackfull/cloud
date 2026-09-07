<?php

declare(strict_types=1);

namespace Lynomia\Modules\Audit\Application\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;

/**
 * Writes one line of the permanent record.
 *
 * ---------------------------------------------------------------------------
 * Called explicitly, never by a model observer
 * ---------------------------------------------------------------------------
 *
 * Auditing by hooking every save would produce a row for every write the
 * application makes and none of them would say why. The valuable part of an
 * audit entry is the intent — "an operator voided this invoice because the
 * customer was double-billed" — and intent exists at the call site and nowhere
 * else. So each of the dozen or so acts worth recording names itself.
 *
 * ---------------------------------------------------------------------------
 * Outside the caller's transaction
 * ---------------------------------------------------------------------------
 *
 * Deliberately not wrapped in, or joined to, the transaction of the thing
 * being audited, and callers record after their work commits. The failure
 * modes are asymmetric: an audit row for an act that rolled back is a
 * confusing line an investigator can reconcile against the subject, while an
 * act that succeeded with no audit row is the exact hole the table exists to
 * close. Neither is good; only one is silent.
 *
 * The flip side is that this must never throw into a caller that has already
 * done the work.
 */
final readonly class RecordAuditEntry
{
    /**
     * @param  array<string, mixed>  $context  Redacted on write by the model's cast.
     */
    public function execute(
        AuditAction $action,
        ?Model $subject = null,
        ?string $customerId = null,
        array $context = [],
    ): AuditEntry {
        $actor = Auth::user();

        return AuditEntry::query()->create([
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_type' => $actor === null ? 'system' : 'user',
            // Denormalised on purpose: renaming or deleting an operator must
            // not change what the trail says happened.
            'actor_label' => $this->labelFor($actor),
            'action' => $action,
            // getMorphClass, not ::class: if the platform ever registers a
            // morph map, the trail starts recording the stable alias instead
            // of a class name that a refactor can change.
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'customer_id' => $customerId,
            'context' => $context === [] ? null : $context,
            'ip_address' => $this->request()?->ip(),
            // Truncated to the column rather than rejected: a long or absent
            // user agent is not a reason to lose the record of the act.
            'user_agent' => $this->userAgent(),
        ]);
    }

    private function labelFor(mixed $actor): ?string
    {
        if (! $actor instanceof Model) {
            return null;
        }

        $name = $actor->getAttribute('name');
        $email = $actor->getAttribute('email');

        if (! is_string($name) || ! is_string($email)) {
            return is_string($email) ? $email : null;
        }

        return sprintf('%s <%s>', $name, $email);
    }

    private function userAgent(): ?string
    {
        $agent = $this->request()?->userAgent();

        return is_string($agent) ? mb_substr($agent, 0, 512) : null;
    }

    private function request(): ?Request
    {
        /*
         * Absent in a queue worker or a scheduled command, which is where a
         * good deal of what this records actually happens. The container
         * always binds 'request' in an HTTP process and resolves a hollow one
         * in a console process, so the console check is what distinguishes
         * them — a synthesised request has no client IP.
         */
        if (app()->runningInConsole()) {
            return null;
        }

        return app()->bound('request') ? app(Request::class) : null;
    }
}
