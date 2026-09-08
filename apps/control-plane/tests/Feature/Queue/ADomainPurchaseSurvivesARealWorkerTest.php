<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Domains\Application\Jobs\RegisterDomainAtRegistrar;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationState;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainOperation;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;

/**
 * A domain registration run by a worker in another process.
 *
 * ===========================================================================
 * WHY THIS IS NOT COVERED BY THE FEATURE TESTS
 * ===========================================================================
 *
 * Because those run the job inline, in the test's own transaction, in the
 * process that queued it. Three things that decide whether a customer gets
 * their name are invisible there:
 *
 *  - whether the job can find its rows at all, which needs them committed
 *    rather than merely written;
 *  - whether the payload survives serialisation onto Redis and back;
 *  - what a worker that dies mid-registration leaves behind.
 *
 * The last one is the reason this file exists. A registration is a purchase.
 * A worker killed between sending it and hearing back has left the platform in
 * exactly the state the Timeout Rule is about — and the rule is worth nothing
 * if the row a dead worker leaves can be picked up and run again by the next
 * one.
 */
final class ADomainPurchaseSurvivesARealWorkerTest extends WorkerHarness
{
    #[Test]
    public function a_worker_in_another_process_registers_the_name(): void
    {
        [$domain, $operation] = $this->committedOrderFor('worker-buys.test');

        RegisterDomainAtRegistrar::dispatch((string) $operation->getKey());

        $this->assertSame(1, $this->queued('default'));

        $this->work('default');

        $this->assertSame(
            DomainOperationState::Completed,
            $this->reread($operation)->state,
        );

        $held = $this->reread($domain);
        $this->assertSame(DomainState::Active, $held->state);
        $this->assertNotNull($held->expires_at);
    }

    #[Test]
    public function a_row_a_dead_worker_left_behind_is_never_bought_again(): void
    {
        [$domain, $operation] = $this->committedOrderFor('worker-died.test');

        /*
         * The state a worker that vanished mid-registration leaves: claimed,
         * `running`, and nobody knows whether the registry took it.
         *
         * Constructed rather than raced. The alternative is to kill a worker
         * at the right instant, which needs the fake to be slow on purpose and
         * still turns a precise assertion into a timing one — and the row this
         * produces is byte-for-byte the row a killed worker leaves, because
         * `running` is written before the provider call and nothing else runs
         * after the process dies.
         */
        $this->outsideTheTransaction(function () use ($operation): void {
            DomainOperation::on(self::CONNECTION)
                ->whereKey($operation->getKey())
                ->update(['state' => DomainOperationState::Running->value]);
        });

        $stranded = $this->reread($operation);

        /*
         * The assertion that matters. A second worker must not pick this up
         * and buy the name again — `mayBeStarted()` excludes `running` for
         * exactly this reason.
         */
        $this->assertFalse($stranded->state->mayBeStarted());
        $this->assertFalse($stranded->state->permitsAnotherAttempt());

        RegisterDomainAtRegistrar::dispatch((string) $operation->getKey());

        $this->work('default');

        // Untouched by a real worker in a real process: no second term, no
        // second charge, and the name still waiting for a person.
        $this->assertSame(DomainOperationState::Running, $this->reread($operation)->state);
        $this->assertSame(DomainState::RegistrationPending, $this->reread($domain)->state);
    }

    /**
     * A paid-for registration, committed so another process can see it.
     *
     * @return array{0: Domain, 1: DomainOperation}
     */
    private function committedOrderFor(string $name): array
    {
        return $this->outsideTheTransaction(function () use ($name): array {
            DomainTld::query()->firstOrCreate(
                ['tld' => 'test'],
                [
                    'enabled' => true,
                    'provider' => 'fake',
                    'allows_registration' => true,
                    'allows_transfer' => true,
                    'allows_renewal' => true,
                    'supports_premium' => true,
                    'minimum_term_years' => 1,
                    'maximum_term_years' => 10,
                    'currency' => 'KWD',
                    'registration_price_minor' => 3_500,
                    'renewal_price_minor' => 4_000,
                    'transfer_price_minor' => 3_500,
                    'registration_cost_minor' => 2_800,
                    'renewal_cost_minor' => 3_200,
                    'transfer_cost_minor' => 2_800,
                ],
            );

            $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

            $domain = Domain::factory()->create([
                'customer_id' => $customer->getKey(),
                'name' => $name,
                'tld' => 'test',
                'state' => DomainState::RegistrationPending,
                'provider' => 'fake',
                'term_years' => 1,
            ]);

            $operation = DomainOperation::factory()->create([
                'domain_id' => $domain->getKey(),
                'customer_id' => $customer->getKey(),
                'name' => $name,
                'kind' => DomainOperationKind::Register,
                'state' => DomainOperationState::Queued,
                'provider' => 'fake',
                'idempotency_key' => 'worker-'.$name,
            ]);

            return [$domain, $operation];
        });
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    private function reread(Model $model): Model
    {
        /** @var TModel $fresh */
        $fresh = $model::on(self::CONNECTION)->findOrFail($model->getKey());

        return $fresh;
    }
}
