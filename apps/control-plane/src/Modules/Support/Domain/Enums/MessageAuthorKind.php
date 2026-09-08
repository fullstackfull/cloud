<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Domain\Enums;

/**
 * Which side of the conversation a message came from.
 *
 * Stored beside the author's id rather than derived from it, for two reasons.
 * A user can be a customer on their own account and an operator on the
 * platform, so the same id means different things on different tickets. And an
 * author whose login is later deleted leaves a null id — without this column,
 * an operator's message would silently become nobody's, and a thread in which
 * the platform's own replies are indistinguishable from the customer's is a
 * thread nobody can read after an incident.
 */
enum MessageAuthorKind: string
{
    case Customer = 'customer';
    case Operator = 'operator';
    case System = 'system';
}
