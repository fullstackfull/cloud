<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Domain\Enums;

/**
 * Who did the thing an activity row records.
 *
 * Typed rather than a free string, because the interesting cases are the ones
 * where the honest answer is not a name. The audit's example — "a team of three
 * cannot see who rebooted what" — is answered by `CustomerUser`; the two
 * others exist so that the platform never has to invent one.
 *
 * `Unknown` is not a failure of the feed. It is the truthful answer for every
 * row written before the source table recorded a requester, and it is
 * displayed as such. The alternative — attributing an old reboot to whoever
 * happens to be signed in now, or to the account's first user — would be a
 * guess presented as history, which is worse than an admission.
 *
 * There is no `Operator` case. When a person at Lynomia acts on an account the
 * customer is told through the notification and the support thread, where the
 * context is explained; putting operator identity into a raw feed would leak
 * staff names and the shape of internal work with nothing around it to make
 * sense of. Operator-initiated work therefore reads as `System`.
 */
enum ActorType: string
{
    /** A person signed in to this account. Their display name is published. */
    case CustomerUser = 'customer_user';

    /**
     * The platform itself: the build that follows a paid order, the suspension
     * that follows an unpaid one, the renewal the scheduler runs, the
     * reconciler correcting drift.
     */
    case System = 'system';

    /**
     * The source does not record who asked, and the platform will not guess.
     */
    case Unknown = 'unknown';
}
