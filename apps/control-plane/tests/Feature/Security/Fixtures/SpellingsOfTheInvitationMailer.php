<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Fixtures;

use Closure;
use Countable;
use Lynomia\Modules\Identity\Application\DTOs\IssuedInvitation;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMailer;
use Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMailer as Postman;

/**
 * A controller nobody routes, whose methods use the invitation mailer in the
 * ways TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest says its scan
 * recognises — and two that must not count.
 *
 * The scan decides which routes must carry the invitation limiter, so how far
 * it reaches is a claim, and routes/v1/team.php repeats it. The only way to
 * test that claim used to be adding a route to TeamController by hand and
 * watching whether the rule went red; this is that experiment kept in the
 * tree. The scan is run over each method here and must answer as the test
 * expects, so narrowing it goes red.
 *
 * The class is imported twice on purpose. `Postman` names it without
 * containing its name, the one spelling of "the class by name" a search for
 * the text cannot see. A qualified or fully qualified name is not here: in a
 * file that imports the class, as this one must, Pint rewrites either into
 * the import. The scan resolves both all the same, and both contain the
 * class's own name besides. Nothing calls these methods.
 */
final class SpellingsOfTheInvitationMailer
{
    private static ?Postman $spare = null;

    public function __construct(
        private readonly Postman $mailer,
        private readonly Closure $mailerless,
        private readonly Postman|Closure $eitherSender,
        private readonly Postman&Countable $countedSender,
    ) {}

    public function theOrdinarySpelling(IssuedInvitation $issued): void
    {
        $this->mailer->send($issued);
    }

    public function theCallOnTheNextLine(IssuedInvitation $issued): void
    {
        $this->mailer
            ->send($issued);
    }

    public function throughALocal(IssuedInvitation $issued): void
    {
        $mailer = $this->mailer;
        $mailer->send($issued);
    }

    public function nullsafeAfterTheProperty(IssuedInvitation $issued): void
    {
        $this->mailer?->send($issued);
    }

    public function nullsafeBeforeTheProperty(IssuedInvitation $issued): void
    {
        $this?->mailer->send($issued);
    }

    public function throughAnotherHandleOnTheController(IssuedInvitation $issued): void
    {
        $controller = $this;
        $controller->mailer->send($issued);
    }

    public function insideAClosure(IssuedInvitation $issued): void
    {
        $send = fn (IssuedInvitation $offer) => $this->mailer->send($offer);
        $send($issued);
    }

    public function aStaticProperty(IssuedInvitation $issued): void
    {
        self::$spare?->send($issued);
    }

    public function aUnionTypedProperty(IssuedInvitation $issued): void
    {
        $sender = $this->eitherSender;
        $sender instanceof Closure ? $sender($issued) : $sender->send($issued);
    }

    public function anIntersectionTypedProperty(IssuedInvitation $issued): void
    {
        $this->countedSender->send($issued);
    }

    public function aPropertyNamedByALiteral(IssuedInvitation $issued): void
    {
        $this->{'mailer'}->send($issued);
    }

    public function aPropertyNamedByAVariable(IssuedInvitation $issued, string $which): void
    {
        $this->$which->send($issued);
    }

    public function aStaticPropertyNamedByAVariable(IssuedInvitation $issued, string $which): void
    {
        self::$$which?->send($issued);
    }

    public function theClassByAnAlias(IssuedInvitation $issued): void
    {
        app(Postman::class)->send($issued);
    }

    public function theClassByItsImportedName(IssuedInvitation $issued): void
    {
        app(InvitationMailer::class)->send($issued);
    }

    public function theClassInAString(IssuedInvitation $issued): void
    {
        app('Lynomia\Modules\Identity\Infrastructure\Mail\InvitationMailer')->send($issued);
    }

    public function theClassInAStringInAnotherCase(IssuedInvitation $issued): void
    {
        app('lynomia\modules\identity\infrastructure\mail\invitationmailer')->send($issued);
    }

    public function aParameter(IssuedInvitation $issued, Postman $mailer): void
    {
        $mailer->send($issued);
    }

    public function aNullableParameter(IssuedInvitation $issued, ?Postman $mailer): void
    {
        $mailer?->send($issued);
    }

    public function aUnionParameter(IssuedInvitation $issued, Postman|Closure $mailer): void
    {
        $mailer instanceof Closure ? $mailer($issued) : $mailer->send($issued);
    }

    public function anIntersectionParameter(IssuedInvitation $issued, Postman&Countable $mailer): void
    {
        $mailer->send($issued);
    }

    public function anotherPropertyWhoseNameStartsTheSame(IssuedInvitation $issued): void
    {
        ($this->mailerless)($issued);
    }

    public function nothingToDoWithMail(): int
    {
        return 1 + 1;
    }
}
