<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Shared\Domain\Contracts\StateMachine;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Tests\Support\EnumCaseReferences;

/**
 * Every state a machine declares it can enter is one some production code can
 * put a row into — or it is named below with the reason nothing does.
 *
 * ===========================================================================
 * WHY THIS EXISTS
 * ===========================================================================
 *
 * The architecture suite already asked the reachability question of methods,
 * events, capabilities, metrics and translations. It never asked it of the two
 * things that carry state: enum cases and machine states. The result was a
 * translation gate ({@see EveryStateAScreenShowsIsTranslatedTest}) that walked
 * `$enum::cases()` and demanded an English and an Arabic string for every case
 * — including cases no transition can produce — and a suite that was green
 * while nothing established a customer could ever see them.
 *
 * This gate asks it of the states a machine can enter; its sibling
 * {@see EveryEnumCaseHasAProducerTest} asks it of every other enum case,
 * including the cases of a machine's enum that no transition targets. The
 * translation gate no longer demands a string for a state either of them
 * excuses as one nothing writes.
 *
 * A transition table declares that a move is **legal**. That is exactly not
 * evidence that anything performs it. The first time this gate ran, with an
 * empty allow-list, on a tree it had never been told about, it named
 * `DedicatedServerStatus::Retired` (F-47's subject) without being pointed at
 * it, `InvoiceStatus::Uncollectible` — a state `SettleInvoice::PAYABLE` lists
 * as one that can still take money — and nine of `OrderStatus`'s thirteen
 * cases, which is the audit's own headline reproduced by an instrument that
 * had never read it.
 *
 * ===========================================================================
 * WHAT IT READS
 * ===========================================================================
 *
 * **Subjects** are discovered, not listed: every concrete class in `src` that
 * implements {@see StateMachine}, through the closure {@see EnumCaseReferences}
 * describes — today ten names: the contract, the abstract base, and eight
 * machines. Only the files the closure names are loaded.
 *
 * **States** are each machine's own `transitions()` targets: a case that is a
 * legal destination of some edge.
 *
 * **Producers** are the sites {@see EnumCaseReferences} classifies as writing
 * a case. Its docblock is the list of what it reads and what it does not see,
 * and both are this gate's limits too. Two of its rules exist because this
 * gate could not be made red by the failure its name promises:
 *
 *  - an argument of a transition guard (`$states->assertCanTransition($from,
 *    Enum::X)`) is a read. It used to count as a write, so removing
 *    `RetireDedicatedServer`'s `forceFill(['status' => Retired])` — this
 *    gate's own worked example — left the guard beside it "producing"
 *    `Retired`, and the gate stayed green;
 *  - an element of a list literal a `foreach` walks is a read. It used to count
 *    as a write, so `KeepTheOrderInStepWithItsServices`'s precedence list kept
 *    `OrderStatus::ProvisioningFailed` "produced" after both of its real
 *    writers were changed to `ManualReview`, and 345 tests stayed green.
 *
 * A third was found by the verifier: `DedicatedServerStatus::Provisioning`
 * stayed "produced" by `'required_status' => Provisioning->value` inside a
 * refusal's `withContext([...])`, so removing `ProvisionDedicatedHandler`'s
 * transition left the gate green. Anything inside an exception's context is
 * now a read.
 *
 * `the_classifier_reads_*` below pin these on sources written for the
 * purpose, so none depends on the tree staying as it is.
 *
 * One concealment is left and cannot be closed here: a list of cases returned
 * as a set for a filter to read (`CustomerServiceState::underlyingStatuses()`)
 * still counts as producing them, so `ServiceStatus::Reactivating` and
 * `ServiceStatus::Failed` losing their one real writer each leaves this gate
 * green. {@see EnumCaseReferences}' "WHAT IT DOES NOT SEE" names the
 * behavioural test that goes red for each instead.
 */
final class EveryStateAMachineCanEnterHasAProducerTest extends TestCase
{
    /**
     * States a machine can legally enter that no production code produces,
     * each with the reason and the owner of the decision.
     *
     * An entry is an admission, not a fix. It is removed when the state gains
     * a writer (the second test fails until it is), or when the owner decides
     * to delete the case (the second test fails then too, because the entry
     * outlives the state it excused). Either way the decision cannot be taken
     * silently.
     *
     * `DedicatedServerStatus::Retired` — the state this gate found on its own
     * the first time it ran, and F-47's subject — is not here: F-12's
     * `RetireDedicatedServer` writes it. That is the event this gate was armed
     * for, and the entry went when the writer arrived.
     *
     * Nor are the nine `OrderStatus` cases the audit's F-19 headline named
     * (payment_failed, queued_for_provisioning, provisioning,
     * provisioning_failed, manual_review, active, suspended, refunded,
     * terminated). They were excused here, each owned by F-19, until F-19
     * gave every one of them a production writer; the second test then
     * failed on all nine, as designed, and the entries went.
     *
     * Nor is `ProvisioningJobStatus::Cancelled`, the table's "give up". It was
     * excused here as a capability the platform described and did not have,
     * until the close of a job whose service has ended
     * (CloseAJobWhoseServiceEnded, X9-1) wrote it; this test then failed on
     * it, as designed, and the entry went.
     *
     * @var array<string, string>
     */
    private const array UNPRODUCED = [
        /*
         * Written off after dunning has given up, says the table, and
         * `uncollectible → paid` and `→ void` are both legal. Nothing gives up:
         * no action, job or command writes the state, so no invoice reaches it.
         * It still has a reader that matters — `SettleInvoice::PAYABLE` lists it
         * as a status that can take money — so a documented money branch is one
         * no invoice can enter. Building a write-off is new billing capability
         * outside this programme; whether to build it, delete the case or
         * declare it prepared belongs to the Billing module's owner.
         */
        InvoiceStatus::class.'::Uncollectible' => 'No write-off exists: nothing moves an open invoice to uncollectible, and SettleInvoice::PAYABLE reads it. Billing owns the decision.',
    ];

    #[Test]
    public function every_state_a_machine_can_enter_is_written_by_production_code(): void
    {
        $destinations = self::destinations();
        $producers = EnumCaseReferences::producersOf(array_keys($destinations));

        $this->assertGuards($destinations, $producers);

        $unwritten = [];

        foreach ($destinations as $state => $sources) {
            if (isset(self::UNPRODUCED[$state]) || $producers[$state] !== []) {
                continue;
            }

            $unwritten[] = sprintf('%s — a legal target from {%s}', $state, implode(', ', $sources));
        }

        $this->assertSame([], $unwritten, sprintf(
            "These states are legal transition targets that no production code in src/ or app/ writes:\n  %s\n\n".
            'A transition table says a move is legal; it is not evidence anything performs it. Either give the '.
            'state a writer, delete the case, or add it to UNPRODUCED with the reason and the owner of the decision.',
            implode("\n  ", $unwritten),
        ));
    }

    #[Test]
    public function no_excuse_outlives_the_state_it_excuses(): void
    {
        $destinations = self::destinations();
        $producers = EnumCaseReferences::producersOf(array_keys($destinations));

        $this->assertGuards($destinations, $producers);

        $stale = [];

        foreach (array_keys(self::UNPRODUCED) as $state) {
            if (! isset($destinations[$state])) {
                $stale[] = "{$state} — no longer a legal target of any machine (or no longer a case at all)";

                continue;
            }

            foreach ($producers[$state] as [$file, $line, $position]) {
                $stale[] = "{$state} — written at {$file}:{$line} ({$position})";
            }
        }

        $this->assertSame([], $stale, sprintf(
            "These UNPRODUCED entries excuse a state that no longer needs excusing:\n  %s\n\n".
            'Remove the entry. If the new writer is only a query that changed how it spells the scalar, it is a '.
            'read this classifier cannot tell from a write: revert the spelling alone, and a real writer survives that.',
            implode("\n  ", $stale),
        ));
    }

    /**
     * The translation gate's enum list is written by hand, so an enum is
     * covered only if somebody remembers to add it. A machine's enum is the
     * one kind of enum this file can name without a definition of "rendered"
     * to argue about: every one of them is a status column a screen shows. So
     * each must be an entry of that gate's `RENDERED` — the entry, read by
     * reflection, not the `use` line, which survives the entry's removal.
     */
    #[Test]
    public function every_enum_a_state_machine_governs_is_named_by_the_translation_gate(): void
    {
        $governed = EnumCaseReferences::governedEnums();

        $this->assertGreaterThanOrEqual(
            8,
            count($governed),
            'Fewer machine enums were found than exist today; discovery is broken, and a gate over nothing passes for the wrong reason.',
        );

        $constant = new ReflectionClassConstant(EveryStateAScreenShowsIsTranslatedTest::class, 'RENDERED');
        $rendered = $constant->getValue();

        $this->assertIsArray($rendered, 'RENDERED is not an array; this test would be reading nothing.');
        $this->assertNotSame([], $rendered, 'RENDERED is empty; this test would be reading nothing.');

        $named = [];

        foreach ($rendered as $enums) {
            foreach ((array) $enums as $enum) {
                $named[$enum] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($governed), array_keys($named)));
        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These enums are governed by a state machine and are not an entry in %s::RENDERED, so a case added to\n".
            "one can reach a screen with no string in either language:\n  %s",
            EveryStateAScreenShowsIsTranslatedTest::class,
            implode("\n  ", $missing),
        ));
    }

    /**
     * The guard rule reads a method call by its name alone. That is safe only
     * while every method so named is a machine's, which is what this holds.
     */
    #[Test]
    public function a_transition_guard_is_recognised_only_by_names_no_other_class_declares(): void
    {
        $this->assertSame(
            ['assertcantransition', 'cantransition'],
            EnumCaseReferences::guardNames(),
            'The transition guards are read from StateMachine by reflection; if the contract changed, re-establish that each is unable to write or return its argument before accepting the new list.',
        );

        $this->assertSame(
            [],
            EnumCaseReferences::guardNamesDeclaredOutsideTheMachines(),
            'A class outside the machines declares a method named like a transition guard, so a call to it would be read as a guard and its arguments as reads. Rename it, or make the classifier resolve the receiver.',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function positions(): iterable
    {
        yield 'a guard argument beside nothing' => [
            '$this->states->assertCanTransition($locked->status, Fixture::B); $locked->save();',
            'transition guard argument',
        ];
        yield 'a nullsafe guard argument' => ['$this->states?->canTransition($from, Fixture::B);', 'transition guard argument'];
        yield 'the write beside a guard' => ["\$locked->forceFill(['status' => Fixture::B])->save();", 'producer'];
        yield 'an element of a walked precedence list' => [
            'foreach ([Fixture::B, Fixture::A] as $first) { if (in_array($first, $live, true)) { return $first; } }',
            'walked list member',
        ];
        yield 'a keyed value of a walked list' => ["foreach (['x' => Fixture::B] as \$k => \$v) {}", 'producer'];
        yield 'a case in the body of a walk' => ['foreach ($rows as $row) { $row->status = Fixture::B; }', 'producer'];
        yield 'a list merged before it is walked' => ['foreach (array_merge([Fixture::B], $more) as $s) {}', 'producer'];
        yield 'a case returned' => ['return Fixture::B;', 'producer'];
        yield 'an argument of a method that is not a guard' => ['$this->states->transition($model, Fixture::B);', 'producer'];
        yield 'a guard-named function that is not a method' => ['canTransition($from, Fixture::B);', 'producer'];
        yield 'a scalar in a refusal\'s context' => ["return \$e->withContext(['required_status' => Fixture::B->value]);", 'exception context'];
        yield 'a case nested in a refusal\'s context' => ["return \$e->withContext(['allowed' => [Fixture::B]]);", 'exception context'];
        yield 'the same array given to anything else' => ["\$row->update(['status' => Fixture::B->value]);", 'producer'];
        yield 'a context built outside the call' => ["\$context = ['status' => Fixture::B]; return \$e->withContext(\$context);", 'producer'];
    }

    /**
     * The exception-context rule reads a method call by its name alone. That
     * is safe only while the one declaration of the name is DomainException's,
     * which stores the array on the exception and returns the exception.
     */
    #[Test]
    public function an_exception_context_is_recognised_only_by_a_name_one_class_declares(): void
    {
        $this->assertSame(
            ['src/Modules/Shared/Domain/Exceptions/DomainException.php:'.(new \ReflectionMethod(DomainException::class, 'withContext'))->getStartLine()],
            EnumCaseReferences::contextMethodDeclarations(),
            'Another production class declares withContext(), so a call to it would be read as an exception context and its arguments as reads. Rename it, or make the classifier resolve the receiver.',
        );
    }

    /**
     * The two classifier rules this gate's defects were about, and the lines
     * each must not cross, on sources written here. The tree cannot move
     * these: they do not depend on what production code happens to contain.
     */
    #[Test]
    #[DataProvider('positions')]
    public function the_classifier_reads_a_guard_argument_a_walked_list_and_an_exception_context_as_reads_and_nothing_else(string $body, string $expected): void
    {
        $source = "<?php\nnamespace App\\Probe;\nuse Tests\\Architecture\\Fixture;\nfinal class Probe\n{\n    public function run(): mixed\n    {\n        {$body}\n        return null;\n    }\n}\n";

        [$found] = EnumCaseReferences::classifySource($source, ['Tests\\Architecture\\Fixture' => ['B' => true]]);

        $this->assertCount(1, $found, 'The probe names Fixture::B once; the classifier must find it exactly once.');
        $this->assertSame($expected, $found[0][2]);
    }

    /**
     * A case whose name is a reserved word is still a case. `NoDerivedName::Unset`
     * and `WordPressSiteKind::Clone` were invisible because the lexer gives
     * `unset` and `clone` their keyword tokens after `::`, and the classifier
     * accepted only a plain identifier there.
     */
    #[Test]
    public function the_classifier_reads_a_case_named_by_a_reserved_word(): void
    {
        $source = "<?php\nnamespace App\\Probe;\nuse Tests\\Architecture\\Fixture;\nfunction probe(): mixed\n{\n    return [Fixture::Unset, Fixture::Clone, Fixture::List];\n}\n";

        [$found] = EnumCaseReferences::classifySource($source, ['Tests\\Architecture\\Fixture' => ['Unset' => true, 'Clone' => true, 'List' => true]]);

        $this->assertSame(
            ['Tests\\Architecture\\Fixture::Unset', 'Tests\\Architecture\\Fixture::Clone', 'Tests\\Architecture\\Fixture::List'],
            array_column($found, 0),
        );
    }

    /**
     * Vacuity guards, in each test that reads the classifier, because a test
     * run alone under `--filter` or in its own process must not pass over
     * nothing.
     *
     * @param  array<string, list<string>>  $destinations
     * @param  array<string, list<array{string, int, string}>>  $producers
     */
    private function assertGuards(array $destinations, array $producers): void
    {
        $this->assertGreaterThanOrEqual(8, count(EnumCaseReferences::machines()), 'Fewer state machines were discovered than exist today.');
        $this->assertNotSame([], $destinations, 'No machine declared a destination; discovery is broken.');

        $written = array_filter($producers, static fn (array $sites): bool => $sites !== []);
        $this->assertGreaterThan(
            count($destinations) / 2,
            count($written),
            'Fewer than half of all destinations have a producer; the classifier is almost certainly broken rather than the tree.',
        );

        $this->assertGreaterThan(0, EnumCaseReferences::readingSites(), 'The classifier recognised no reading position at all; it is not classifying.');
    }

    /**
     * Every state some machine can enter, with the states it is a target from.
     *
     * @return array<string, list<string>> "Enum::Case" → the source states it is a target from
     */
    public static function destinations(): array
    {
        $destinations = [];

        foreach (EnumCaseReferences::machines() as $machine) {
            foreach ($machine->transitions() as $from => $targets) {
                foreach ($targets as $target) {
                    $destinations[$target::class.'::'.$target->name][] = (string) $from;
                }
            }
        }

        ksort($destinations);

        return $destinations;
    }
}
