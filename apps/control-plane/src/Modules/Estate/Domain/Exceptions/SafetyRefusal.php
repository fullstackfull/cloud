<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\Exceptions;

use Lynomia\Modules\Estate\Domain\Enums\EstateAction;
use Lynomia\Modules\Estate\Domain\Enums\SafetyClass;
use RuntimeException;

/**
 * The safety classification said no.
 *
 * An exception rather than a false, because a caller that ignores a boolean is
 * a caller that reimages a machine anyway. Every refusal carries what was
 * asked for, what the machine's class was, and what class would have permitted
 * it — so the message an operator sees tells them the next step rather than
 * only that they were stopped.
 */
final class SafetyRefusal extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $server,
        public readonly EstateAction $attempted,
        public readonly SafetyClass $classification,
        public readonly ?SafetyClass $wouldPermit = null,
    ) {
        parent::__construct($message);
    }

    public static function classDoesNotPermit(
        string $server,
        EstateAction $attempted,
        SafetyClass $classification,
    ): self {
        $required = match ($attempted) {
            EstateAction::Read => SafetyClass::DiscoveryOnly,
            EstateAction::Configure => SafetyClass::ConfigurationAllowed,
            EstateAction::Reimage => SafetyClass::ReimageAllowed,
        };

        return new self(
            sprintf(
                '%s is classified %s, which does not permit %s. %s would. '
                .'Raising a classification is a decision recorded against a person, not a step in a workflow.',
                $server,
                $classification->value,
                $attempted->value,
                $required->value,
            ),
            $server,
            $attempted,
            $classification,
            $required,
        );
    }

    /**
     * The class permits it and this machine has not been cleared for it.
     *
     * The distinction is the whole reason reimage takes two flags: the class is
     * an operator's standing decision about the machine, and allow_reimage is
     * their decision about this particular piece of scheduled work. A machine
     * that is permanently REIMAGE_ALLOWED with allow_reimage always true is
     * not guarded, it is a default with extra steps.
     */
    public static function notClearedForReimage(string $server): self
    {
        return new self(
            sprintf(
                '%s is classified reimage_allowed and does not carry allow_reimage. '
                .'A wipe needs both: the classification, and this machine being cleared for this piece of work. '
                .'An unused-looking disk is not evidence that a disk is spare.',
                $server,
            ),
            $server,
            EstateAction::Reimage,
            SafetyClass::ReimageAllowed,
        );
    }
}
