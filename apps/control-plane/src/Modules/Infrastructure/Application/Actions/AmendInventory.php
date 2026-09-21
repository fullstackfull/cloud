<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\InventoryChangeRefused;

/**
 * Correcting a row in the estate, with the two things that make it safe.
 *
 * ---------------------------------------------------------------------------
 * One action rather than eight
 * ---------------------------------------------------------------------------
 *
 * Creation differs per object — a cluster validates an endpoint, a subnet
 * parses a block, a pool fixes a scope for life — so each has its own action.
 * Amendment genuinely does not: it is "set these columns, refuse if somebody
 * got there first, refuse if something still lives here, write down both
 * sides". Eight copies of that would be eight places for the guard to be
 * forgotten in, and the one it was forgotten in would be the one that
 * orphaned a customer's addresses.
 *
 * ---------------------------------------------------------------------------
 * The version is a precondition, not a column
 * ---------------------------------------------------------------------------
 *
 * It is a fingerprint of the editable fields as they were when the client read
 * them, which is a better answer here than a timestamp: these tables store
 * whole seconds, so two edits in the same second are indistinguishable by
 * `updated_at`, and a lost update is precisely the case where the two writes
 * are close together. Sending it is optional and behaves like If-Match — a
 * screen that has just loaded the row sends it and is protected; a script
 * setting one field deliberately does not and is not.
 *
 * ---------------------------------------------------------------------------
 * Nothing is deleted
 * ---------------------------------------------------------------------------
 *
 * The dependency guard runs when a change would take something out of service,
 * and what it protects is not the row — it is the customer service pointing at
 * it. The caller decides what "out of service" means for its own object,
 * because that is the one part of an amendment that is not uniform.
 */
final readonly class AmendInventory
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  the attributes to set, already validated and cast
     * @param  list<string>  $versioned  the object's whole editable set, which is what the
     *                                   precondition is computed over — not the keys of this
     *                                   particular change, or renaming a cluster would be checked
     *                                   against a fingerprint of its name alone and two operators
     *                                   editing different fields would never collide
     * @param  callable(): int|null  $dependants  how many live things would be stranded, asked only
     *                                            when the change takes the object out of service
     *
     * @throws InventoryChangeRefused
     */
    public function execute(
        Model $subject,
        array $changes,
        AuditAction $action,
        User $operator,
        array $versioned,
        ?string $expectedVersion = null,
        ?callable $dependants = null,
        string $dependantLabel = 'things',
    ): Model {
        if ($expectedVersion !== null && $expectedVersion !== self::versionOf($subject, $versioned)) {
            throw InventoryChangeRefused::becauseSomebodyElseChangedItFirst();
        }

        if ($dependants !== null) {
            $stranded = $dependants();

            if ($stranded > 0) {
                throw InventoryChangeRefused::becauseSomethingStillDependsOnIt($dependantLabel, $stranded);
            }
        }

        return $this->record->execute(
            act: function () use ($subject, $changes): Model {
                $before = [];

                foreach (array_keys($changes) as $attribute) {
                    $before[$attribute] = self::readable($subject->getAttribute($attribute));
                }

                $subject->forceFill($changes)->save();

                $subject->setAttribute('inventory_change_before', $before);

                return $subject;
            },
            describe: static function (Model $changed) use ($action, $changes, $operator): AuditedAct {
                $after = [];

                foreach (array_keys($changes) as $attribute) {
                    $after[$attribute] = self::readable($changed->getAttribute($attribute));
                }

                return new AuditedAct(
                    action: $action,
                    subject: $changed,
                    context: [
                        'before' => $changed->getAttribute('inventory_change_before'),
                        'after' => $after,
                        'operator' => (string) $operator->getKey(),
                    ],
                );
            },
        );
    }

    /**
     * The fingerprint a client sends back to say which version it edited.
     *
     * Derived from the values rather than from a clock, and from the editable
     * fields only: a reconciler writing `last_synced_at` while an operator has
     * the form open has not changed anything the operator is editing, and
     * making them re-open it would train people to ignore the warning.
     *
     * @param  list<string>  $attributes
     */
    public static function versionOf(Model $subject, array $attributes): string
    {
        sort($attributes);

        $state = [];

        foreach ($attributes as $attribute) {
            $state[$attribute] = self::readable($subject->getAttribute($attribute));
        }

        return substr(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * Enums and dates become the values a trail can be read and compared with;
     * everything else is already one.
     */
    private static function readable(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value;
    }
}
