<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Lynomia\Modules\SharedHosting\Domain\DTOs\CreateAccountRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Providers\FakeHostingProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The controlled panel will not open an account for a site no resolver can
 * ever serve, or write to a contact address nobody can receive at.
 *
 * ===========================================================================
 * WHAT WAS WRONG (re-audit after round two, band D, unnumbered)
 * ===========================================================================
 *
 * `FakeHostingProvider::createAccount()` stored the primary domain and the
 * contact address unexamined — `abc.hosting.invalid`, `localhost`, anything.
 * That is the exact shape of F-04: a real order reached the panel as
 * `<username>.hosting.invalid`, and the one component in a position to see it
 * reported the account created. The handler no longer invents that name, so
 * this was a gap in the oracle rather than a live defect — which is why it
 * matters: a regression of F-04 would have been green here.
 *
 * ===========================================================================
 * WHAT IS REFUSED, AND WHAT IS DELIBERATELY NOT
 * ===========================================================================
 *
 * Refused: a name `DnsName` does not accept; a bare label; and anything at or
 * under the two special-use names that by definition never name a site on
 * the internet — `.invalid` (RFC 2606 / RFC 6761 §6.4: guaranteed never to
 * resolve) and `localhost` / `.localhost` (RFC 6761 §6.3: the loopback). A
 * contact address that is not an address, or whose domain is one of those.
 *
 * Accepted, by decision: `.test`, `.example` and `example.com`/`.net`/`.org`.
 * RFC 2606 reserves them for testing and documentation, which is precisely
 * what a controlled panel is for, and the suite uses them as its stand-in for
 * a customer's domain throughout. Refusing them would make the simulator
 * refuse every rehearsal the estate runs; the class F-04 was about is the
 * placeholder that can never be real, and that class is refused.
 *
 * WHAT THIS DOES NOT CLAIM: what WHM or DirectAdmin does with such a name has
 * not been established in this repository. The simulator declines to report
 * success for a request no caller can have meant; it is not modelling a
 * panel's validation.
 */
final class TheHostingSimulatorRefusesANameNoSiteCanHaveTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function namesNoSiteCanHave(): array
    {
        return [
            'the F-04 placeholder' => ['abc.hosting.invalid'],
            'the .invalid TLD itself' => ['invalid'],
            'upper case .INVALID' => ['Shop.Example.INVALID'],
            'localhost' => ['localhost'],
            'a name under .localhost' => ['shop.localhost'],
            'a bare label' => ['intranet'],
            'not a DNS name' => ['shop_front.example.com'],
            'blank' => ['  '],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesTheSuiteRehearsesWith(): array
    {
        return [
            'a .test name' => ['x.test'],
            'a .example name' => ['foo.example'],
            'example.com' => ['shop.example.com'],
            'an ordinary name' => ['acme-widgets.com.kw'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addressesNobodyReceivesAt(): array
    {
        return [
            'blank' => [''],
            'not an address' => ['owner'],
            'no domain' => ['owner@'],
            'an .invalid domain' => ['owner@abc.hosting.invalid'],
            'localhost' => ['root@localhost'],
            'a bare label' => ['owner@intranet'],
        ];
    }

    #[Test]
    #[DataProvider('namesNoSiteCanHave')]
    public function an_account_for_it_is_refused_and_nothing_is_opened(string $domain): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $this->refused(fn () => $provider->createAccount($node, $this->createRequest($domain, 'owner@acme.example')), 'domain');

        $this->assertSame([], $provider->listAccounts($node), 'The refused create left an account behind.');
        $this->assertNull($provider->credentialHandedTo($node, 'acme'));
    }

    #[Test]
    #[DataProvider('addressesNobodyReceivesAt')]
    public function an_account_whose_contact_nobody_receives_at_is_refused(string $contact): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $this->refused(fn () => $provider->createAccount($node, $this->createRequest('acme.example', $contact)), 'contact');

        $this->assertSame([], $provider->listAccounts($node));
    }

    #[Test]
    #[DataProvider('namesTheSuiteRehearsesWith')]
    public function the_reserved_names_the_suite_rehearses_with_are_still_accepted(string $domain): void
    {
        $provider = new FakeHostingProvider;
        $node = $this->node();

        $provider->createAccount($node, $this->createRequest($domain, 'owner@'.$domain));

        $this->assertCount(1, $provider->listAccounts($node));
    }

    private function refused(\Closure $call, string $what): void
    {
        try {
            $call();
        } catch (HostingProviderException $e) {
            // The panel answered and said no: nothing was written, so the
            // outcome is known.
            $this->assertFalse($e->isIndeterminate(), 'A refused '.$what.' was reported as an unknown outcome.');
            $this->assertStringContainsString($what, (string) ($e->context()['provider_message'] ?? ''));

            return;
        }

        $this->fail('The simulator opened an account for a '.$what.' nobody can use.');
    }

    private function node(): HostingNode
    {
        return (new HostingNode)->forceFill([
            'id' => '01JBQ8ZK4M3N5P7R9T1V3W5X83',
            'slug' => 'node-fake',
            'hostname' => 'node-fake.lynomia.test',
            'panel' => HostingPanel::Fake,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'licence_status' => 'active',
            'account_count' => 0,
            'disk_total_mib' => 1024,
            'disk_used_mib' => 128,
            'load_average' => 1.0,
        ]);
    }

    private function createRequest(string $domain, string $contact): CreateAccountRequest
    {
        return new CreateAccountRequest(
            username: 'acme',
            primaryDomain: $domain,
            password: 'a-password-somebody-can-type-9',
            packageName: 'starter',
            contactEmail: $contact,
        );
    }
}
