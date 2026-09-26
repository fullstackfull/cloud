<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Fixtures;

use Lynomia\Modules\Identity\Application\DTOs\IssuedInvitation;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMailer as Postman;

/**
 * The parent of SpellingsOfTheInvitationMailer, for the two actions a route
 * to the child inherits.
 *
 * The scan collects mailer properties from the class that declares the
 * method and from the routed class, and each half has a method here that goes
 * red without it:
 *
 * - PHP lets a parent's method read a protected property its child declares,
 *   so the child's `$courier`, which this class does not declare, is found
 *   only through the routed class;
 * - the routed class does not see a parent's private property, so this
 *   class's `$ownSender` is found only through the declaring class.
 *
 * Neither method's text contains the mailer class's name, and neither
 * property is named like an import — the scan resolves every bare name
 * against the file's imports, so a property called `$postman` here would be
 * found as the class `Postman` — so a property is the only way the scan can
 * find either. Nothing calls them.
 */
abstract class ParentOfTheSpellings
{
    private ?Postman $ownSender = null;

    public function anInheritedActionReadingAPropertyTheChildDeclares(IssuedInvitation $issued): void
    {
        $this->courier->send($issued);
    }

    public function anInheritedActionReadingAPropertyOnlyTheParentSees(IssuedInvitation $issued): void
    {
        $this->ownSender?->send($issued);
    }
}
