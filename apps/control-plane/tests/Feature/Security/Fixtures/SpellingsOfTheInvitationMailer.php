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
 * forms TheInvitationLimiterIsAttachedWhereverTheMailIsSentTest says its
 * fixture holds — and two that must not count. Two more, inherited, are in
 * ParentOfTheSpellings.
 *
 * The scan decides which routes must carry the invitation limiter, so how far
 * it reaches is a claim, and routes/v1/team.php repeats it. The only way to
 * test that claim used to be adding a route to TeamController by hand and
 * watching whether the rule went red; this is that experiment kept in the
 * tree. The scan is run over each method here and must answer as the test
 * expects, so a narrowing that stops recognising one of these methods goes
 * red. A narrowing that touches only a spelling not here stays green: these
 * methods are not every way PHP can reach an object, only the forms the scan
 * reads and the layouts of them written down so far, most of them after an
 * attack found one the scan missed.
 *
 * The class is imported twice on purpose. `Postman` names it without
 * containing its name, the one spelling of "the class by name" a search for
 * the text cannot see. Four spellings the scan reads are not here, because in
 * a file that imports the class, as this one must, Pint rewrites them: a
 * qualified or fully qualified name, which it turns into the import (both
 * contain the class's own name besides); an alias in another letter case,
 * such as `postman::class`, which it turns into `Postman::class`; and
 * whitespace beside `::` with no comment in it, which it removes. Nothing
 * calls these methods.
 */
final class SpellingsOfTheInvitationMailer extends ParentOfTheSpellings
{
    private static ?Postman $spare = null;

    public function __construct(
        private readonly Postman $mailer,
        private readonly Closure $mailerless,
        private readonly Postman|Closure $eitherSender,
        private readonly Postman&Countable $countedSender,
        protected readonly Postman $courier,
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

    public function commentsBetweenTheParts(IssuedInvitation $issued): void
    {
        $this /* the controller */ -> /** its mailer */ mailer->send($issued);
    }

    public function lineBreaksBetweenTheParts(IssuedInvitation $issued): void
    {
        $this
            ->
            mailer
                ->send($issued);
    }

    public function lineCommentsAroundTheNullsafeOperator(IssuedInvitation $issued): void
    {
        $this // the controller
            ?-> // its mailer
            mailer->send($issued);
    }

    public function commentsAndALineBreakAroundTheStaticOperator(IssuedInvitation $issued): void
    {
        self /* the class */
            :: /* its spare */ $spare?->send($issued);
    }

    public function aNameThatIsAVariableAfterALineBreakAndAComment(IssuedInvitation $issued, string $which): void
    {
        $this
            -> /* whichever */ $which->send($issued);
    }

    public function aNameThatIsALiteralAfterAComment(IssuedInvitation $issued): void
    {
        $this-> /* the mailer */ {'mailer'}->send($issued);
    }

    public function aStaticNameThatIsAVariableAfterAComment(IssuedInvitation $issued, string $which): void
    {
        self:: /* whichever */ $$which?->send($issued);
    }

    public function aStaticNameThatIsAVariableAfterALineBreakAndLineComments(IssuedInvitation $issued, string $which): void
    {
        self // the class
            :: // whichever
            $$which?->send($issued);
    }

    public function aNameInBracesAfterTheNullsafeOperator(IssuedInvitation $issued, string $which): void
    {
        $this?->{$which}->send($issued);
    }

    public function aNameThatIsAVariableAfterTheNullsafeOperator(IssuedInvitation $issued, string $which): void
    {
        $this?->$which->send($issued);
    }

    public function asAnArgument(IssuedInvitation $issued): void
    {
        tap($this->mailer, static fn (object $sender) => $sender->send($issued));
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

    public function aStaticPropertyNamedInBraces(IssuedInvitation $issued, string $which): void
    {
        self::${$which}?->send($issued);
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
