<?php

declare(strict_types=1);

namespace Lynomia\Modules\Audit\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;

/**
 * An act and its record, or neither.
 *
 * ---------------------------------------------------------------------------
 * Why this exists alongside RecordAuditEntry
 * ---------------------------------------------------------------------------
 *
 * {@see RecordAuditEntry} is deliberately outside its caller's transaction,
 * and for anything that reaches a provider that is the only honest option: a
 * refund has moved money and a terminated hosting account is gone, so rolling
 * the platform's row back would make the record less true, not more. There the
 * rule is that a failed audit write is loud — it becomes a 500 rather than a
 * shrug — and an operator knows to go and look.
 *
 * The administrative acts that are purely database writes have no such excuse.
 * Suspending an account, adopting an orphan, requeuing a job and settling a
 * rebuild all happen entirely inside Postgres, and for those "it happened and
 * nothing recorded it" is a hole with no compensating benefit. This runs both
 * in one transaction, so a trail that cannot be written stops the act.
 *
 * The description is produced from the result rather than passed in, because
 * what is worth recording is usually a fact the act established.
 */
final readonly class RecordActAtomically
{
    public function __construct(
        private RecordAuditEntry $record,
    ) {}

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $act
     * @param  callable(TResult): ?AuditedAct  $describe
     * @return TResult
     */
    public function execute(callable $act, callable $describe): mixed
    {
        return DB::transaction(function () use ($act, $describe) {
            $result = $act();

            $entry = $describe($result);

            if (! $entry instanceof AuditedAct) {
                return $result;
            }

            $this->record->execute(
                action: $entry->action,
                subject: $entry->subject,
                customerId: $entry->customerId,
                context: $entry->context,
            );

            return $result;
        });
    }
}
