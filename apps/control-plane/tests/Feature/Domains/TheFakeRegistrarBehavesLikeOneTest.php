<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Domains\Domain\DTOs\ContactDetails;
use Lynomia\Modules\Domains\Domain\DTOs\RegistrationRequest;
use Lynomia\Modules\Domains\Domain\Enums\DomainAvailability;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRegistrarException;
use Lynomia\Modules\Domains\Domain\Exceptions\FakeRegistrarInProductionException;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The double everything else in this module is proven against.
 *
 * Worth its own tests because a fake that lies is worse than no fake: every
 * downstream proof would be proving the platform copes with behaviour no
 * registrar has. The two cases that matter are the two that cost money — a
 * refusal and a timeout — and the difference between them is the whole
 * argument of this module.
 */
final class TheFakeRegistrarBehavesLikeOneTest extends TestCase
{
    private function registrar(): FakeDomainRegistrarProvider
    {
        return new FakeDomainRegistrarProvider;
    }

    #[Test]
    public function it_refuses_to_exist_in_production(): void
    {
        /*
         * The narrow guard, behind whatever configuration inspection the boot
         * does. It catches a TLD row whose provider column says `fake`, which
         * no amount of reading config would.
         */
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(FakeRegistrarInProductionException::class);

        new FakeDomainRegistrarProvider;
    }

    #[Test]
    public function a_timeout_registers_the_name_and_says_nothing(): void
    {
        $registrar = $this->registrar();

        try {
            $registrar->register(new RegistrationRequest(
                name: 'silent-timeout.test',
                termYears: 1,
                contacts: [],
            ));
            $this->fail('a timed-out registration should not return normally');
        } catch (DomainRegistrarException $e) {
            $this->assertTrue(
                $e->isIndeterminate(),
                'a timeout must be indeterminate, never a refusal',
            );
        }

        /*
         * And this is why. The registrar really did take the name. A platform
         * that treated the timeout as a failure would refund a domain the
         * customer owns; one that retried would buy a second year. Only a
         * look settles it, and the look finds it.
         */
        $this->assertContains('silent-timeout.test', $registrar->heldNames());
    }

    #[Test]
    public function a_refusal_is_final_and_leaves_nothing_behind(): void
    {
        $registrar = $this->registrar();

        try {
            $registrar->register(new RegistrationRequest(
                name: 'already-taken.test',
                termYears: 1,
                contacts: [],
            ));
            $this->fail('a taken name should not register');
        } catch (DomainRegistrarException $e) {
            $this->assertFalse($e->isIndeterminate());
        }

        $this->assertNotContains('already-taken.test', $registrar->heldNames());
    }

    #[Test]
    public function registering_a_name_it_already_holds_returns_the_same_term(): void
    {
        $registrar = $this->registrar();

        $first = $registrar->register(new RegistrationRequest('idem.test', 1, []));
        $second = $registrar->register(new RegistrationRequest('idem.test', 1, []));

        /*
         * What a registrar honouring an idempotency key does, and what the
         * platform's redelivery guard has to work against: the second call is
         * not a second year.
         */
        $this->assertEquals($first->expiresAt, $second->expiresAt);
        $this->assertCount(1, $registrar->heldNames());
    }

    #[Test]
    public function a_renewal_extends_from_the_expiry_rather_than_from_today(): void
    {
        $registrar = $this->registrar();

        $registered = $registrar->register(new RegistrationRequest('renews.test', 1, []));

        $this->travel(100)->days();

        $renewed = $registrar->renew('renews.test', 1);

        /*
         * Renewing early must not shorten the term. A registry that renewed
         * from today would silently eat the days the customer had left, and
         * they would have paid for them twice.
         */
        $this->assertEquals($registered->expiresAt->addYear(), $renewed->expiresAt);
    }

    #[Test]
    public function availability_distinguishes_five_answers(): void
    {
        $answers = [];

        foreach ($this->registrar()->checkAvailability([
            'free.test', 'gone-taken.test', 'posh-premium.test', 'silence-unknown.test',
        ]) as $answer) {
            $answers[$answer->name] = $answer;
        }

        $this->assertSame(DomainAvailability::Available, $answers['free.test']->availability);
        $this->assertSame(DomainAvailability::Unavailable, $answers['gone-taken.test']->availability);
        $this->assertSame(DomainAvailability::Unknown, $answers['silence-unknown.test']->availability);

        // A premium answer carries its own price, because the TLD's list price
        // is not the one the registry will take.
        $premium = $answers['posh-premium.test'];
        $this->assertSame(DomainAvailability::Premium, $premium->availability);
        $this->assertNotNull($premium->premiumCost);
        $this->assertNotNull($premium->providerReference);
    }

    #[Test]
    public function a_new_registration_is_locked_against_transfer(): void
    {
        // Registries do this, and a platform that assumed otherwise would
        // offer a transfer that fails.
        $registered = $this->registrar()->register(new RegistrationRequest('fresh.test', 1, []));

        $this->assertTrue($registered->transferLocked);
    }

    #[Test]
    public function a_transfer_needs_a_code_and_does_not_finish_at_once(): void
    {
        $registrar = $this->registrar();

        try {
            $registrar->startTransfer('incoming.test', '   ');
            $this->fail('a transfer without a code should be refused');
        } catch (DomainRegistrarException $e) {
            $this->assertFalse($e->isIndeterminate());
        }

        $started = $registrar->startTransfer('incoming.test', 'AUTH-CODE-1234');

        // Pending is a real answer and not a failure to have finished. A
        // losing registrar has days to object.
        $this->assertTrue($started->isPending());
    }

    #[Test]
    public function a_transfer_that_never_finishes_is_expressible(): void
    {
        $registrar = $this->registrar();

        $registrar->startTransfer('stuck-slow.test', 'AUTH-CODE-1234');

        $this->assertTrue($registrar->transferStatus('stuck-slow.test')->isPending());
        $this->assertTrue($registrar->transferStatus('stuck-slow.test')->isPending());
    }

    #[Test]
    public function the_portfolio_survives_into_another_process(): void
    {
        $path = storage_path('framework/testing/domains-'.uniqid().'.state');
        config()->set('domains.fake.state_path', $path);

        try {
            $this->registrar()->register(new RegistrationRequest('shared.test', 1, []));

            // A different instance, standing in for the worker that renews
            // what a web request bought.
            $this->assertContains('shared.test', $this->registrar()->heldNames());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_forgets_contacts_rather_than_writing_them_to_disk(): void
    {
        $path = storage_path('framework/testing/domains-'.uniqid().'.state');
        config()->set('domains.fake.state_path', $path);

        try {
            $registrar = $this->registrar();
            $registrar->register(new RegistrationRequest('privacy.test', 1, []));

            $registrar->setContacts('privacy.test', [
                'registrant' => new ContactDetails(
                    name: 'A Real Person',
                    email: 'person@example.test',
                    phone: '+96500000000',
                    addressLineOne: '1 Somewhere Street',
                    city: 'Kuwait City',
                    country: 'KW',
                ),
            ]);

            /*
             * A fake that kept a registrant's home address in a file on a
             * developer's laptop would be exactly the leak this module's
             * encryption exists to prevent, in the one place nobody would
             * think to look.
             */
            $contents = (string) file_get_contents($path);

            $this->assertStringNotContainsString('A Real Person', $contents);
            $this->assertStringNotContainsString('Somewhere Street', $contents);
            $this->assertStringNotContainsString('person@example.test', $contents);
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function a_seeded_holding_is_how_reconciliation_finds_a_surprise(): void
    {
        $registrar = $this->registrar();

        $registrar->seedHolding('surprise.test', CarbonImmutable::now()->addYear());

        $this->assertContains('surprise.test', $registrar->heldNames());

        // And the other half: a name the platform believes it holds that the
        // registrar has never heard of.
        $registrar->forgetHolding('surprise.test');

        $this->assertNotContains('surprise.test', $registrar->heldNames());
    }
}
