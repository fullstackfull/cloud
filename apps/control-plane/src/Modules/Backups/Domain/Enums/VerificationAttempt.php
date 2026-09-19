<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Enums;

/**
 * What came of asking one provider to read one archive back.
 *
 * Three outcomes and not two, because the third one used to be counted as the
 * second. `NotAskable` is a provider that does not offer verification on
 * demand at all — Proxmox Backup Server verifies on its own schedule and has
 * no endpoint to start one — and that is a fact about the provider, not a
 * failure of this archive. Conflating the two put a refusal message on
 * healthy backups and a permanent failure count on a healthy platform.
 *
 * Not a persisted value: nothing stores this. It exists so the sweep can tell
 * its three cases apart in its own return value, which is the whole reason
 * the distinction survives past the line that makes it.
 */
enum VerificationAttempt
{
    /** The provider accepted, and the row is now waiting on a task. */
    case Started;

    /** The provider was asked and the answer was no, or there was nothing to ask. */
    case Refused;

    /** This provider cannot be asked to verify. The row was left untouched. */
    case NotAskable;
}
