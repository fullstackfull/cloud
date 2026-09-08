<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Infrastructure\Registries\ProvisioningHandlerRegistry;
use Lynomia\Modules\Vps\Domain\Enums\PowerAction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every kind of work the platform can queue must have something that can do it.
 *
 * This test exists because the absence of one was expensive. The registry held
 * exactly one handler for most of this project's life while five more sat
 * written and unregistered, and nothing anywhere went red: each handler's own
 * suite registers the handler before exercising it, so they proved the handler
 * worked without ever proving the application would reach it. In production a
 * customer pressing "reboot", ordering shared hosting, or buying a dedicated
 * server got a 202, a job row, and a queued job that died at the worker with
 * HandlerNotRegisteredException.
 *
 * The guard is total over the enum rather than derived by scanning for call
 * sites. Scanning cannot follow PowerAction::jobKind(), which is exactly how
 * the three power kinds are chosen — a scan-based version of this test passed
 * with the stop handler unregistered. Totality also puts the burden in the
 * right place: a new kind is unroutable until somebody either registers a
 * handler or writes down why there isn't one.
 */
final class HandlerCoverageTest extends TestCase
{
    /**
     * Kinds that deliberately have no handler, and why.
     *
     * A kind may only sit here while nothing creates it, or while the code
     * that creates it says plainly that the work will fail at the worker.
     * Everything here is reported as NOT_IMPLEMENTED, never as complete.
     *
     * @return array<string, string>
     */
    private static function documentedGaps(): array
    {
        return [
            // Empty, and it should stay that way. Every kind the engine can
            // queue now has something that can do it.
        ];
    }

    #[Test]
    public function every_job_kind_is_either_handled_or_a_documented_gap(): void
    {
        $registered = $this->registry()->kinds();
        $gaps = self::documentedGaps();

        $unroutable = [];

        foreach (ProvisioningJobKind::cases() as $kind) {
            if (in_array($kind->value, $registered, strict: true)) {
                continue;
            }

            if (! array_key_exists($kind->value, $gaps)) {
                $unroutable[] = $kind->value;
            }
        }

        $this->assertSame([], $unroutable, sprintf(
            'These job kinds have no handler and no stated reason, so work of this kind is accepted and then '
            .'dies at the worker: %s',
            implode(', ', $unroutable),
        ));
    }

    #[Test]
    public function a_kind_cannot_be_both_handled_and_excused(): void
    {
        // Otherwise a gap entry outlives the gap and the list stops meaning
        // anything — the next real gap hides among the stale excuses.
        $stale = array_intersect(array_keys(self::documentedGaps()), $this->registry()->kinds());

        $this->assertSame([], array_values($stale), 'These kinds are handled and still listed as gaps.');
    }

    #[Test]
    public function every_power_action_a_customer_can_request_resolves_to_a_handler(): void
    {
        /*
         * The path the outage actually took. POST /vps/{vm}/power validates an
         * action, PowerAction::jobKind() turns it into a kind, and the worker
         * asks the registry for it. Walking the enum walks every button the
         * portal offers.
         */
        foreach (PowerAction::cases() as $action) {
            $handler = $this->registry()->get($action->jobKind());

            $this->assertSame(
                $action->jobKind(),
                $handler->kind(),
                sprintf('The handler registered for %s does not claim that kind.', $action->value),
            );
        }
    }

    #[Test]
    public function every_registered_handler_can_actually_be_built(): void
    {
        /*
         * Registration is by class string so that booting the application does
         * not construct every provider client on every request. The cost of
         * that is that a handler with an unresolvable dependency registers
         * cleanly and fails at the worker instead — the same shape of silent
         * failure as not registering it at all, one layer further in. So each
         * one is resolved here once.
         */
        foreach ($this->registry()->kinds() as $kind) {
            $handler = $this->registry()->get(ProvisioningJobKind::from($kind));

            $this->assertSame(
                $kind,
                $handler->kind()->value,
                sprintf('The handler registered for %s reports a different kind.', $kind),
            );
        }
    }

    private function registry(): ProvisioningHandlerRegistry
    {
        /** @var ProvisioningHandlerRegistry $registry */
        $registry = $this->app->make(ProvisioningHandlerRegistry::class);

        return $registry;
    }
}
