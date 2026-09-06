<?php

declare(strict_types=1);

namespace Tests\Unit\SharedHosting;

use Lynomia\Modules\SharedHosting\Application\Actions\PreflightHostingNode;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodePreflightFacts;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Enums\PreflightRefusalReason;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingPreflightFailedException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The gate a machine passes before a panel is installed on it.
 *
 * Every condition here refuses. None of them warns, and the tests say so one
 * by one, because each condition otherwise produces a node that LOOKS
 * installed: the panel answers, the fleet inventory shows it ready, accounts
 * can be created — and the breakage surfaces weeks later on a machine that by
 * then has customers on it.
 *
 * The licence case is asserted by its error code as well as its behaviour.
 * "hosting.license_required" is a contract: it appears in installer output,
 * provisioning results and support tickets, and an operator searching for it
 * has to find every occurrence.
 */
final class PreflightHostingNodeTest extends TestCase
{
    #[Test]
    public function a_clean_supported_machine_with_a_licence_passes(): void
    {
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts());

        $this->assertTrue($report->passed());
        $this->assertSame([], $report->errorCodes());
    }

    #[Test]
    public function it_refuses_an_unsupported_operating_system(): void
    {
        // The vendor ships no packages for this. The installer gets far enough
        // to look like it worked and leaves a stack nobody can patch.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            osId: 'gentoo',
            osVersion: '2.17',
        ));

        $this->assertTrue($report->refused());
        $this->assertContains('hosting.unsupported_os', $report->errorCodes());
    }

    #[Test]
    public function it_accepts_a_patch_release_of_a_supported_major_version(): void
    {
        // Vendors support a release series, not a point release; refusing a
        // node that had merely been patched would refuse the whole fleet.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(osVersion: '9.4'));

        $this->assertTrue($report->passed());
    }

    #[Test]
    public function it_refuses_a_machine_that_is_not_clean(): void
    {
        // The panel takes ownership of the web server, the mail stack and the
        // database. Installed over an existing one, both configurations exist
        // and every panel update fights the other.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            conflictingServices: ['nginx', 'mariadb-server'],
        ));

        $this->assertContains('hosting.machine_not_clean', $report->errorCodes());
    }

    #[Test]
    public function it_refuses_a_hostname_that_is_not_a_fully_qualified_domain(): void
    {
        // The panel signs its own services and stamps outgoing mail with this
        // name; a bare label cannot be certified by any CA.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(hostname: 'node1'));

        $this->assertContains('hosting.hostname_not_fqdn', $report->errorCodes());
    }

    #[Test]
    public function it_refuses_a_hostname_that_does_not_resolve(): void
    {
        // Licence validation, certificate issuance and mail delivery all
        // resolve this name first, and all three fail in ways that look
        // unrelated to each other.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            forwardAddresses: [],
            reverseHostnames: [],
        ));

        $this->assertContains('hosting.hostname_does_not_resolve', $report->errorCodes());
    }

    #[Test]
    public function it_refuses_when_forward_and_reverse_dns_disagree(): void
    {
        /*
         * The most expensive one to discover late. Receiving mail servers check
         * that the connecting address's PTR resolves back to the greeting name;
         * when it does not, mail is accepted and silently filed as spam, and
         * the complaint arrives weeks later as "my contact form stopped
         * working".
         */
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            reverseHostnames: ['static-203-0-113-9.upstream.test'],
        ));

        $this->assertContains('hosting.dns_mismatch', $report->errorCodes());
    }

    #[Test]
    public function it_refuses_when_a_port_the_panel_needs_is_already_bound(): void
    {
        // The installer binds these itself; whatever holds them now is
        // displaced, and on a machine where that is customer-facing the
        // displacement is the outage.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(boundPorts: [22, 80, 3306]));

        $this->assertContains('hosting.ports_in_use', $report->errorCodes());
    }

    #[Test]
    public function it_refuses_without_a_licence_and_reports_hosting_license_required(): void
    {
        /*
         * cPanel/WHM, DirectAdmin, CloudLinux and LiteSpeed are commercial
         * products. Without a valid licence the platform stops cleanly and
         * says so; it never patches, bypasses or works around the vendor's
         * licensing, and no configuration flag enables such a thing.
         *
         * A refusal rather than a warning because the install would otherwise
         * SUCCEED and then refuse to serve — so the first thing to discover the
         * missing licence would be a customer's paid order.
         */
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            licence: new LicenceStatus(valid: false, product: 'cpanel', state: 'expired', detail: 'licence expired'),
        ));

        $this->assertTrue($report->refusedFor(PreflightRefusalReason::LicenceRequired));
        $this->assertContains('hosting.license_required', $report->errorCodes());
    }

    #[Test]
    public function a_licence_check_that_could_not_run_counts_as_no_licence(): void
    {
        // A check that did not run has not passed. Treating an unreachable
        // vendor as a pass is how an unlicensed node reaches production.
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(licenceChecked: false));

        $this->assertContains('hosting.license_required', $report->errorCodes());
    }

    #[Test]
    public function no_configuration_flag_can_turn_the_licence_refusal_into_a_warning(): void
    {
        /*
         * Asserted deliberately. The licence check reads no configuration at
         * all, so there is nothing an operator under pressure can set to make
         * it pass — which is the point of the whole policy.
         */
        config([
            'hosting.licence.block_provisioning_when_unlicensed' => false,
            'hosting.preflight.allow_unlicensed' => true,
            'hosting.preflight.warn_only' => true,
        ]);

        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            licence: new LicenceStatus(valid: false, product: 'cpanel', state: 'expired'),
        ));

        $this->assertTrue($report->refused());
        $this->assertContains('hosting.license_required', $report->errorCodes());
    }

    #[Test]
    public function each_condition_produces_its_own_distinct_error_code(): void
    {
        $report = $this->preflight()->execute(HostingPanel::Cpanel, $this->facts(
            hostname: 'node1',
            osId: 'gentoo',
            osVersion: '2.17',
            conflictingServices: ['httpd'],
            boundPorts: [80],
            licence: new LicenceStatus(valid: false, product: 'cpanel'),
        ));

        $codes = $report->errorCodes();

        // All the checks run even after one fails: a machine being
        // commissioned is cheap to reinstall for about an hour, and finding
        // its problems one round trip at a time spends exactly that hour.
        $this->assertEqualsCanonicalizing([
            'hosting.unsupported_os',
            'hosting.machine_not_clean',
            'hosting.hostname_not_fqdn',
            'hosting.ports_in_use',
            'hosting.license_required',
        ], $codes);

        // Distinct, not merely present: an operator has to be able to act on
        // each one separately.
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    #[Test]
    public function the_licence_refusal_is_the_one_the_exception_reports_even_when_others_are_present(): void
    {
        // It is the only refusal an operator cannot fix by editing the
        // machine, and reporting it behind "port 80 is in use" sends somebody
        // to debug the wrong thing.
        try {
            $this->preflight()->assertReady(HostingPanel::Cpanel, $this->facts(
                boundPorts: [80],
                licence: new LicenceStatus(valid: false, product: 'cpanel'),
            ));

            $this->fail('A refused preflight did not stop the caller.');
        } catch (HostingPreflightFailedException $e) {
            $this->assertSame('hosting.license_required', $e->errorCode());
            $this->assertSame(2, $e->context()['refusal_count']);
        }
    }

    #[Test]
    public function assert_ready_throws_so_an_installer_cannot_proceed_by_forgetting_to_look(): void
    {
        $this->expectException(HostingPreflightFailedException::class);

        $this->preflight()->assertReady(HostingPanel::Cpanel, $this->facts(hostname: 'node1'));
    }

    #[Test]
    public function directadmins_own_supported_platforms_are_used_for_a_directadmin_install(): void
    {
        // Debian is supported by DirectAdmin and not by cPanel. A single
        // shared list would refuse half the fleet or admit machines the vendor
        // does not support.
        $facts = $this->facts(osId: 'debian', osVersion: '12');

        $this->assertTrue($this->preflight()->execute(HostingPanel::DirectAdmin, $facts)->passed());
        $this->assertContains(
            'hosting.unsupported_os',
            $this->preflight()->execute(HostingPanel::Cpanel, $facts)->errorCodes(),
        );
    }

    private function preflight(): PreflightHostingNode
    {
        return new PreflightHostingNode;
    }

    /**
     * @param  list<string>  $forwardAddresses
     * @param  list<string>  $reverseHostnames
     * @param  list<string>  $conflictingServices
     * @param  list<int>  $boundPorts
     */
    private function facts(
        string $hostname = 'node1.lynomia.test',
        string $osId = 'almalinux',
        string $osVersion = '9',
        ?array $forwardAddresses = null,
        ?array $reverseHostnames = null,
        array $conflictingServices = [],
        array $boundPorts = [22],
        ?LicenceStatus $licence = null,
        bool $licenceChecked = true,
    ): NodePreflightFacts {
        return new NodePreflightFacts(
            hostname: $hostname,
            osId: $osId,
            osVersion: $osVersion,
            forwardAddresses: $forwardAddresses ?? ['203.0.113.9'],
            reverseHostnames: $reverseHostnames ?? [$hostname],
            conflictingServices: $conflictingServices,
            boundPorts: $boundPorts,
            // licenceChecked: false is the case where the vendor could not be
            // asked at all, which preflight has to treat exactly like a
            // missing licence.
            licence: $licenceChecked
                ? ($licence ?? new LicenceStatus(valid: true, product: 'cpanel', state: 'active'))
                : null,
        );
    }
}
