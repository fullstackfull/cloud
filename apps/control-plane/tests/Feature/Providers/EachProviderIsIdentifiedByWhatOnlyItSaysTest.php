<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Lynomia\Modules\Providers\Domain\DTOs\ConnectionResult;
use Lynomia\Modules\Providers\Domain\DTOs\TestTarget;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Testers\RedfishConnectionTester;
use Lynomia\Modules\Providers\Infrastructure\Testers\StripeConnectionTester;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

/**
 * The positive twin of the negative matrix.
 *
 * ===========================================================================
 * WHY BOTH HALVES ARE NEEDED
 * ===========================================================================
 *
 * A gate that only proves testers say no is vacuous: a tester that returned
 * IdentityMismatch unconditionally would pass every one of the ninety-eight
 * cases beside this file, and the platform would report an estate that is
 * entirely unreachable — which is as wrong as reporting one that is entirely
 * fine, and harder to notice because it looks cautious.
 *
 * So each driver is given its own product's real answer here and must reach a
 * usable state, and is given each of that product's *specific* failures and
 * must reach the state that names it. The two files together say: this driver
 * recognises its product, and nothing else.
 *
 * ===========================================================================
 * WHAT THE FAILURES ARE FOR
 * ===========================================================================
 *
 * Each one is a different afternoon for the operator who reads it. A lapsed
 * cPanel licence is a purchase order; a WHM token with the wrong ACLs is a
 * checkbox in WHM; a Backup Server registered as a compute cluster is one
 * field on one row; a live Stripe key in staging is an incident. A single
 * "Error" for all four sends the wrong person every time.
 */
final class EachProviderIsIdentifiedByWhatOnlyItSaysTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Proxmox VE
    // -----------------------------------------------------------------------

    #[Test]
    public function a_proxmox_cluster_that_reports_its_build_and_its_nodes_is_connected(): void
    {
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2', 'repoid' => 'faa83925c9f0e5a3']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1', 'status' => 'online']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => [
                '/' => ['Sys.Audit' => 1, 'Datastore.Audit' => 1, 'Datastore.AllocateSpace' => 1],
                '/vms' => [
                    'VM.Allocate' => 1, 'VM.Audit' => 1, 'VM.PowerMgmt' => 1, 'VM.Console' => 1,
                    'VM.Config.CPU' => 1, 'VM.Config.Memory' => 1, 'VM.Config.Disk' => 1, 'VM.Config.Network' => 1,
                    'VM.Config.Options' => 1, 'VM.Config.HWType' => 1, 'VM.Config.Cloudinit' => 1,
                ],
            ]]),
        ]);

        $result = $this->test('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006', $this->proxmoxToken());

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['create']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['console']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['task_polling']);

        /*
         * Reinstall and templates are settled by privileges like everything
         * else. They used to be absent from the map and stayed Unknown on
         * every real cluster, which left VPS satisfiable only by the
         * simulator (F-13). Whether an image boots is verification's
         * question, exactly as whether a create builds is.
         */
        $this->assertSame(CapabilityState::Supported, $result->capabilities['reinstall']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['templates']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['inventory_sync']);
    }

    #[Test]
    public function a_proxmox_token_holding_only_the_allocation_privilege_cannot_create(): void
    {
        /*
         * `create` is one POST that writes cores, memory, disks, a NIC, options
         * and cloud-init keys. On the tester's reading of the Proxmox API —
         * which nothing in this repository verifies against a cluster — a
         * token holding VM.Allocate alone cannot complete it, so the tester
         * must not offer it.
         */
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2', 'repoid' => 'faa83925c9f0e5a3']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1', 'status' => 'online']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => [
                '/' => ['Sys.Audit' => 1],
                '/vms' => ['VM.Allocate' => 1, 'VM.PowerMgmt' => 1],
            ]]),
        ]);

        $result = $this->test('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006', $this->proxmoxToken());

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['create']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['destroy']);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['inventory_sync']);
    }

    #[Test]
    public function a_proxmox_token_with_only_audit_privileges_is_connected_read_only(): void
    {
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1']]]),
            '*/api2/json/access/permissions*' => Http::response(['data' => ['/' => ['Sys.Audit' => 1, 'Datastore.Audit' => 1]]]),
        ]);

        $result = $this->test('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006', $this->proxmoxToken());

        $this->assertSame(ConnectionState::ConnectedReadOnly, $result->state);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['create']);
        $this->assertStringContainsString('onboarded read-only', (string) $result->detail);
    }

    #[Test]
    public function a_backup_server_registered_as_a_compute_cluster_is_a_mismatch_and_not_a_credential_problem(): void
    {
        /*
         * The wrong-product-of-the-right-vendor case, which no status code
         * tells apart: both Proxmox products answer /api2/json/version with
         * the same envelope, and only Proxmox VE has a /nodes collection.
         */
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '3.2.7', 'release' => '3.2', 'repoid' => 'c2b8ff2d']]),
            '*/api2/json/nodes*' => Http::response(['data' => null], 404),
        ]);

        $result = $this->test('proxmox', ProviderCategory::Compute, 'https://pbs.example.test:8007', $this->proxmoxToken());

        $this->assertSame(ConnectionState::IdentityMismatch, $result->state);
        $this->assertStringContainsString('proxmox_backup', (string) $result->detail);
        $this->assertNotSame(ConnectionState::AuthFailed, $result->state);
    }

    #[Test]
    public function a_proxmox_envelope_that_refuses_the_token_is_an_authentication_failure(): void
    {
        // The envelope is the evidence. A 401 with no envelope is a mismatch,
        // which the negative matrix beside this file asserts.
        Http::fake(['*' => Http::response(['data' => null], 401)]);

        $result = $this->test('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006', $this->proxmoxToken());

        $this->assertSame(ConnectionState::AuthFailed, $result->state);
        $this->assertSame(CapabilityState::BlockedCredentials, $result->capabilities['create']);
    }

    #[Test]
    public function a_credential_that_is_not_a_proxmox_token_is_never_dialled(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $result = $this->test('proxmox', ProviderCategory::Compute, 'https://pve.example.test:8006', 'root:hunter2');

        $this->assertSame(ConnectionState::CredentialMalformed, $result->state);
        Http::assertNothingSent();

        $this->assertStringContainsString('Username and password authentication is not supported', (string) $result->stepsAsArray()[0]['detail']);
    }

    // -----------------------------------------------------------------------
    // Proxmox Backup path
    // -----------------------------------------------------------------------

    #[Test]
    public function a_cluster_with_no_backup_datastore_is_connected_and_cannot_back_anything_up(): void
    {
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1']]]),
            '*/api2/json/storage*' => Http::response(['data' => [['storage' => 'local', 'type' => 'dir']]]),
        ]);

        $result = $this->test('proxmox_backup', ProviderCategory::Backup, 'https://pve.example.test:8006', $this->proxmoxToken());

        /*
         * Connected is the truthful state — the cluster is reachable and the
         * token works — and the capabilities are what carry the bad news. The
         * readiness engine turns an Unsupported `create` into a blocker on
         * every product that needs backups, which is the accurate chain. A
         * failure state here would say the cluster is broken, and it is not.
         */
        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['create']);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['restore']);
        $this->assertStringContainsString('pbs role', (string) $result->detail);
    }

    #[Test]
    public function a_cluster_with_a_backup_datastore_still_cannot_verify_a_backup(): void
    {
        Http::fake([
            '*/api2/json/version*' => Http::response(['data' => ['version' => '8.2.4', 'release' => '8.2']]),
            '*/api2/json/nodes*' => Http::response(['data' => [['node' => 'pve-1']]]),
            '*/api2/json/storage*' => Http::response(['data' => [['storage' => 'pbs-1', 'type' => 'pbs']]]),
        ]);

        $result = $this->test('proxmox_backup', ProviderCategory::Backup, 'https://pve.example.test:8006', $this->proxmoxToken());

        $this->assertSame(ConnectionState::Connected, $result->state);

        /*
         * The capability list and the adapter have to agree. The adapter's
         * supportsVerification() answers false because a PBS verification runs
         * on the backup server's own schedule and the hypervisor API has no
         * endpoint that starts one — so a capability row claiming verification
         * would put a button on a screen that the adapter refuses.
         */
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['verify']);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['file_restore']);
    }

    // -----------------------------------------------------------------------
    // cPanel / WHM
    // -----------------------------------------------------------------------

    #[Test]
    public function a_whm_node_that_echoes_its_command_and_grants_the_acls_is_connected(): void
    {
        Http::fake([
            '*/json-api/myprivs*' => Http::response([
                'metadata' => ['command' => 'myprivs', 'result' => 1],
                'data' => ['create-acct' => 1, 'kill-acct' => 1, 'suspend-acct' => 1, 'list-accts' => 1],
            ]),
            '*/json-api/version*' => Http::response([
                'metadata' => ['command' => 'version', 'result' => 1, 'reason' => 'OK'],
                'data' => ['version' => '11.126.0.4'],
            ]),
        ]);

        $result = $this->test('cpanel', ProviderCategory::Hosting, 'https://whm.example.test:2087', 'lynomia:TOKEN0000000000000000');

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['create_account']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['terminate']);

        // Not in the ACL map, so left Unknown rather than refused: WHM has
        // renamed ACL keys between versions, and a false Unsupported takes a
        // product off sale.
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['sso']);
    }

    #[Test]
    public function an_unlicensed_cpanel_node_is_a_licence_problem_and_not_a_credential_one(): void
    {
        Http::fake(['*' => Http::response(
            '<html><head><title>cPanel License Error</title></head><body>This server\'s cPanel license has expired.</body></html>',
            200,
        )]);

        $result = $this->test('cpanel', ProviderCategory::Hosting, 'https://whm.example.test:2087', 'lynomia:TOKEN0000000000000000');

        $this->assertSame(ConnectionState::LicenceMissing, $result->state);
        $this->assertSame(CapabilityState::BlockedLicence, $result->capabilities['create_account']);
        $this->assertStringContainsString('no credential change will make this node serve accounts', (string) $result->detail);
    }

    #[Test]
    public function a_whm_token_with_no_write_acls_is_connected_read_only(): void
    {
        Http::fake([
            '*/json-api/myprivs*' => Http::response([
                'metadata' => ['command' => 'myprivs', 'result' => 1],
                'data' => ['list-accts' => 1],
            ]),
            '*/json-api/version*' => Http::response([
                'metadata' => ['command' => 'version', 'result' => 1],
                'data' => ['version' => '11.126.0.4'],
            ]),
        ]);

        $result = $this->test('cpanel', ProviderCategory::Hosting, 'https://whm.example.test:2087', 'lynomia:TOKEN0000000000000000');

        $this->assertSame(ConnectionState::ConnectedReadOnly, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['usage']);
    }

    // -----------------------------------------------------------------------
    // DirectAdmin
    // -----------------------------------------------------------------------

    #[Test]
    public function a_directadmin_node_answering_its_system_command_is_connected(): void
    {
        Http::fake([
            '*/CMD_API_SYSTEM_INFO*' => Http::response('kernel=5.15.0&os=linux&loadavg=0.14&hostname=da-1'),
            '*/CMD_API_LICENSE*' => Http::response('error=0&status=active&expires=2027-01-01'),
            '*/CMD_API_SHOW_USERS*' => Http::response('list[]=alice&list[]=bob'),
        ]);

        $result = $this->test('directadmin', ProviderCategory::Hosting, 'https://da.example.test:2222', 'admin:KEY00000000000000');

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['usage']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['create_account']);
    }

    #[Test]
    public function directadmins_login_page_identifies_the_product_and_refuses_the_credential(): void
    {
        /*
         * The one case where an HTML body is proof of two things at once.
         * DirectAdmin answers an API request it will not authenticate with its
         * own login screen, and that screen carries the product's name — so
         * the identity is established and the credential is refused, which is
         * an authentication failure rather than a mismatch. An HTML page that
         * does *not* name DirectAdmin is a mismatch, and the negative matrix
         * asserts that.
         */
        Http::fake(['*' => Http::response(
            '<!doctype html><html><head><title>DirectAdmin Login</title></head><body>Username</body></html>',
        )]);

        $result = $this->test('directadmin', ProviderCategory::Hosting, 'https://da.example.test:2222', 'admin:KEY00000000000000');

        $this->assertSame(ConnectionState::AuthFailed, $result->state);
    }

    #[Test]
    public function a_directadmin_credential_that_cannot_list_accounts_is_not_an_administrator(): void
    {
        Http::fake([
            '*/CMD_API_SYSTEM_INFO*' => Http::response('kernel=5.15.0&os=linux&loadavg=0.14'),
            '*/CMD_API_LICENSE*' => Http::response('error=0&status=active'),
            '*/CMD_API_SHOW_USERS*' => Http::response('error=1&text=You+cannot+access+this+resource&details=admin+only'),
        ]);

        $result = $this->test('directadmin', ProviderCategory::Hosting, 'https://da.example.test:2222', 'reseller:KEY00000000000000');

        $this->assertSame(ConnectionState::PermissionInsufficient, $result->state);
        $this->assertStringContainsString('administrator', (string) $result->detail);
    }

    #[Test]
    public function an_unlicensed_directadmin_node_is_a_licence_problem(): void
    {
        Http::fake([
            '*/CMD_API_SYSTEM_INFO*' => Http::response('error=1&text=License+Error&details=Your+license+has+expired'),
        ]);

        $result = $this->test('directadmin', ProviderCategory::Hosting, 'https://da.example.test:2222', 'admin:KEY00000000000000');

        $this->assertSame(ConnectionState::LicenceMissing, $result->state);
    }

    // -----------------------------------------------------------------------
    // Cloudflare
    // -----------------------------------------------------------------------

    #[Test]
    public function a_cloudflare_token_that_verifies_is_connected_and_claims_no_write(): void
    {
        Http::fake([
            '*/user/tokens/verify*' => Http::response([
                'success' => true, 'errors' => [], 'messages' => [['code' => 10000, 'message' => 'This API Token is valid and active']],
                'result' => ['status' => 'active'],
            ]),
            '*/zones*' => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => [['name' => 'example.test']]]),
        ]);

        $result = $this->test('cloudflare', ProviderCategory::Dns, null, str_repeat('a', 40));

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['reconcile']);

        // Every capability that changes DNS. A test that established them
        // would have changed a customer's records to do it.
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['create_zone']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['records']);
    }

    #[Test]
    public function an_expired_cloudflare_token_is_an_authentication_failure_despite_the_two_hundred(): void
    {
        /*
         * Cloudflare answers HTTP 200 with `success: true` for a token that
         * verifies, and then reports the token itself as expired. A tester
         * reading the status code calls that Connected; one reading `success`
         * agrees with it. Both are wrong, and the customer finds out when a
         * zone stops updating.
         */
        Http::fake(['*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => ['status' => 'expired'],
        ])]);

        $result = $this->test('cloudflare', ProviderCategory::Dns, null, str_repeat('a', 40));

        $this->assertSame(ConnectionState::AuthFailed, $result->state);
        $this->assertStringContainsString('expired', (string) $result->detail);
    }

    #[Test]
    public function a_cloudflare_global_api_key_is_refused_before_it_is_sent(): void
    {
        /*
         * The legacy global key carries every permission on the account and
         * cannot be scoped or revoked without changing the account's own
         * login. Refusing to send it is the point: a platform-wide credential
         * must not be onboardable by pasting it into the box meant for a
         * scoped token.
         */
        Http::fake(['*' => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['status' => 'active']])]);

        $result = $this->test('cloudflare', ProviderCategory::Dns, null, str_repeat('a', 37));

        $this->assertSame(ConnectionState::CredentialMalformed, $result->state);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_reverse_dns_driver_shares_the_account_and_claims_nothing_it_has_not_read(): void
    {
        Http::fake([
            '*/user/tokens/verify*' => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['status' => 'active']]),
            '*/zones*' => Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => []]),
        ]);

        $result = $this->test('cloudflare_rdns', ProviderCategory::ReverseDns, null, str_repeat('b', 40));

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['set_ptr']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['clear_ptr']);
    }

    // -----------------------------------------------------------------------
    // Redfish and iLO
    // -----------------------------------------------------------------------

    #[Test]
    public function a_redfish_controller_that_declares_its_specification_version_is_connected(): void
    {
        $this->fakeRedfish(manufacturer: 'Dell Inc.');

        $result = $this->test('redfish', ProviderCategory::Bmc, '10.40.0.11', 'lynomia:PASSWORD00000000');

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['inventory']);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['power_state']);

        // Writes. The only way to establish power control is to use it.
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['power_control']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['boot_override']);
    }

    #[Test]
    public function an_ilo_driver_pointed_at_another_vendors_controller_is_a_mismatch(): void
    {
        /*
         * A conforming Redfish controller, and not an HPE one. The iLO adapter
         * reads firmware from /redfish/v1/Managers/1/UpdateService/FirmwareInventory,
         * which is HPE's path and not the standard's — so this machine would
         * pass a naive connection test and fail every operation that matters.
         */
        $this->fakeRedfish(manufacturer: 'Dell Inc.');

        $result = $this->test('ilo', ProviderCategory::Bmc, '10.40.0.12', 'lynomia:PASSWORD00000000');

        $this->assertSame(ConnectionState::IdentityMismatch, $result->state);
        $this->assertStringContainsString('redfish driver', (string) $result->detail);
    }

    #[Test]
    public function an_ilo_driver_pointed_at_an_hpe_controller_is_connected(): void
    {
        $this->fakeRedfish(manufacturer: 'HPE', oem: 'Hpe');

        $result = $this->test('ilo', ProviderCategory::Bmc, '10.40.0.12', 'lynomia:PASSWORD00000000');

        $this->assertSame(ConnectionState::Connected, $result->state);
    }

    #[Test]
    public function a_redfish_controller_that_refuses_the_account_is_an_authentication_failure(): void
    {
        /*
         * The cleanest case in the whole phase, and the reason the service
         * root is read first. The product is established by the standard's own
         * document, served unauthenticated; the refusal is established by the
         * standard's own status code on the collection behind it. Nothing here
         * is inferred from a 401 that could have come from anywhere.
         */
        Http::fake([
            '*/redfish/v1/Systems*' => Http::response(['error' => ['code' => 'Base.1.0.GeneralError']], 401),
            '*redfish/v1/' => Http::response(['@odata.id' => '/redfish/v1/', 'RedfishVersion' => '1.6.0']),
        ]);

        $result = $this->test('redfish', ProviderCategory::Bmc, '10.40.0.11', 'lynomia:WRONGPASSWORD00');

        $this->assertSame(ConnectionState::AuthFailed, $result->state);
        $this->assertSame(CapabilityState::BlockedCredentials, $result->capabilities['inventory']);
    }

    #[Test]
    public function a_redfish_discovery_reports_only_what_the_machine_said(): void
    {
        $this->fakeRedfish(manufacturer: 'Dell Inc.');

        /** @var RedfishConnectionTester $tester */
        $tester = app(ConnectionTesterFactory::class)->for('redfish');

        $facts = $tester->discover($this->target('redfish', ProviderCategory::Bmc, '10.40.0.11', 'lynomia:PASSWORD00000000'));

        $this->assertSame('Dell Inc.', $facts['vendor']);
        $this->assertSame('PowerEdge R660', $facts['model']);
        $this->assertSame('1.6.0', $facts['redfish.version']);

        // Absent rather than empty. A blank serial number on a screen reads as
        // a fact about the machine; it would be a fact about the tester.
        $this->assertArrayNotHasKey('bios.version', $facts);
    }

    #[Test]
    public function a_discovery_against_an_impostor_reports_nothing(): void
    {
        Http::fake(['*' => Http::response('OK')]);

        $tester = app(ConnectionTesterFactory::class)->for('redfish');

        $this->assertSame(
            [],
            $tester->discover($this->target('redfish', ProviderCategory::Bmc, '10.40.0.11', 'lynomia:PASSWORD00000000')),
            'A discovery that reports a serial number for a machine it never identified has invented one.',
        );
    }

    // -----------------------------------------------------------------------
    // Stripe
    // -----------------------------------------------------------------------

    #[Test]
    public function a_stripe_test_key_on_a_production_row_is_refused_without_being_sent(): void
    {
        $sent = false;

        ApiRequestor::setHttpClient($this->stripeHttp(function () use (&$sent): array {
            $sent = true;

            return ['{}', 200, []];
        }));

        $result = (new StripeConnectionTester)->test(new TestTarget(
            driver: 'stripe',
            environment: DeploymentEnvironment::Production,
            endpoint: null,
            secret: 'sk_test_'.str_repeat('a', 24),
            probeCapabilities: ProviderCategory::Payment->capabilities(),
        ));

        $this->assertSame(ConnectionState::IdentityMismatch, $result->state);
        $this->assertFalse($sent, 'The safest thing to do with a key that should not be here is not to send it.');
        $this->assertStringContainsString('never paid for', (string) $result->detail);
    }

    #[Test]
    public function a_stripe_live_key_on_a_staging_row_is_refused_without_being_sent(): void
    {
        $sent = false;

        ApiRequestor::setHttpClient($this->stripeHttp(function () use (&$sent): array {
            $sent = true;

            return ['{}', 200, []];
        }));

        $result = (new StripeConnectionTester)->test(new TestTarget(
            driver: 'stripe',
            environment: DeploymentEnvironment::Staging,
            endpoint: null,
            secret: 'sk_live_'.str_repeat('a', 24),
            probeCapabilities: ProviderCategory::Payment->capabilities(),
        ));

        $this->assertSame(ConnectionState::IdentityMismatch, $result->state);
        $this->assertFalse($sent);
        $this->assertStringContainsString('charge real cards', (string) $result->detail);
    }

    #[Test]
    public function a_stripe_account_that_can_take_charges_is_connected_and_claims_no_charge(): void
    {
        ApiRequestor::setHttpClient($this->stripeHttp(static function (string $url): array {
            if (str_contains($url, '/v1/balance')) {
                return ['{"object":"balance","livemode":false,"available":[]}', 200, []];
            }

            return ['{"id":"acct_1LynomiaTest","object":"account","charges_enabled":true,"default_currency":"kwd"}', 200, []];
        }));

        $result = (new StripeConnectionTester)->test($this->stripeTarget('sk_test_'.str_repeat('a', 24)));

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['currencies']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['charge']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['refund']);
    }

    #[Test]
    public function a_stripe_account_whose_charges_are_not_enabled_is_read_only(): void
    {
        ApiRequestor::setHttpClient($this->stripeHttp(static function (string $url): array {
            if (str_contains($url, '/v1/balance')) {
                return ['{"object":"balance","livemode":false}', 200, []];
            }

            return ['{"id":"acct_1LynomiaTest","object":"account","charges_enabled":false}', 200, []];
        }));

        $result = (new StripeConnectionTester)->test($this->stripeTarget('sk_test_'.str_repeat('a', 24)));

        $this->assertSame(ConnectionState::ConnectedReadOnly, $result->state);
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['charge']);
        $this->assertStringContainsString('Onboarding is incomplete at Stripe', (string) $result->detail);
    }

    #[Test]
    public function a_key_stripe_rejects_is_an_authentication_failure(): void
    {
        ApiRequestor::setHttpClient($this->stripeHttp(static fn (): array => [
            '{"error":{"type":"invalid_request_error","message":"Invalid API Key provided: sk_test_**********"}}', 401, [],
        ]));

        $result = (new StripeConnectionTester)->test($this->stripeTarget('sk_test_'.str_repeat('a', 24)));

        $this->assertSame(ConnectionState::AuthFailed, $result->state);
    }

    #[Test]
    public function something_that_is_not_stripe_answering_for_stripe_is_a_mismatch(): void
    {
        ApiRequestor::setHttpClient($this->stripeHttp(static fn (): array => ['{"object":"list","data":[]}', 200, []]));

        $result = (new StripeConnectionTester)->test($this->stripeTarget('sk_test_'.str_repeat('a', 24)));

        $this->assertSame(ConnectionState::IdentityMismatch, $result->state);
        $this->assertFalse($result->state->usable());
    }

    #[Test]
    public function a_publishable_key_is_not_a_credential(): void
    {
        $result = (new StripeConnectionTester)->test($this->stripeTarget('pk_test_'.str_repeat('a', 24)));

        $this->assertSame(ConnectionState::CredentialMalformed, $result->state);
    }

    // -----------------------------------------------------------------------
    // IPMI
    // -----------------------------------------------------------------------

    #[Test]
    public function a_bmc_that_answers_the_device_id_command_is_connected(): void
    {
        Process::fake([
            '*chassis power status*' => Process::result('Chassis Power is on'),
            '*' => Process::result(
                "Device ID                 : 32\n"
                ."Firmware Revision         : 2.75\n"
                ."IPMI Version              : 2.0\n"
                ."Manufacturer Name         : Dell Inc.\n"
            ),
        ]);

        $result = $this->test('ipmi', ProviderCategory::Bmc, '10.40.0.20', 'lynomia:PASSWORD00000000');

        $this->assertSame(ConnectionState::Connected, $result->state);
        $this->assertSame(CapabilityState::Supported, $result->capabilities['inventory']);
        $this->assertSame(CapabilityState::Unknown, $result->capabilities['power_control']);

        // IPMI has no inventory service worth the name, and claiming firmware
        // inventory would put a screen in front of an operator that the
        // adapter cannot fill.
        $this->assertSame(CapabilityState::Unsupported, $result->capabilities['firmware']);
    }

    #[Test]
    public function an_ipmi_refusal_that_names_the_authentication_failure_is_one(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'Error: RAKP 2 HMAC is invalid', exitCode: 1)]);

        $result = $this->test('ipmi', ProviderCategory::Bmc, '10.40.0.20', 'lynomia:WRONGPASSWORD00');

        $this->assertSame(ConnectionState::AuthFailed, $result->state);
    }

    #[Test]
    public function an_ipmi_session_failure_that_says_nothing_is_not_guessed_at(): void
    {
        /*
         * ipmitool prints the same sentence for a wrong password, a disabled
         * LAN interface, a blocked port and a machine that is not plugged in.
         * Choosing between them would be inventing an answer, and every one of
         * the four sends an operator somewhere different.
         */
        Process::fake(['*' => Process::result(
            output: '',
            errorOutput: 'Error: Unable to establish IPMI v2 / RMCP+ session',
            exitCode: 1,
        )]);

        $result = $this->test('ipmi', ProviderCategory::Bmc, '10.40.0.20', 'lynomia:PASSWORD00000000');

        $this->assertSame(ConnectionState::NeedsReview, $result->state);
        $this->assertNotSame(ConnectionState::AuthFailed, $result->state);
        $this->assertNotSame(ConnectionState::NetworkFailed, $result->state);
    }

    #[Test]
    public function an_ipmi_command_that_exits_clean_without_a_device_id_has_identified_nothing(): void
    {
        Process::fake(['*' => Process::result('')]);

        $result = $this->test('ipmi', ProviderCategory::Bmc, '10.40.0.20', 'lynomia:PASSWORD00000000');

        $this->assertSame(ConnectionState::NeedsReview, $result->state);
        $this->assertFalse($result->state->usable());
    }

    #[Test]
    public function an_ipmi_address_with_shell_metacharacters_is_data_and_not_a_command(): void
    {
        Process::fake(['*' => Process::result("Device ID : 32\nFirmware Revision : 2.75\n")]);

        $this->test('ipmi', ProviderCategory::Bmc, '10.40.0.20;id', 'lynomia:PASSWORD00000000');

        Process::assertRan(function (PendingProcess $process): bool {
            /*
             * An array, so the values reach execve() directly and no shell
             * parses them. The address is whatever an operator or a discovery
             * job wrote, and an interpolated string would make a hostname
             * containing metacharacters arbitrary command execution as the
             * queue worker — which holds the credentials for every BMC in the
             * fleet.
             */
            return is_array($process->command) && in_array('10.40.0.20;id', $process->command, true);
        });
    }

    // -----------------------------------------------------------------------

    private function test(string $driver, ProviderCategory $category, ?string $endpoint, string $secret): ConnectionResult
    {
        return app(ConnectionTesterFactory::class)->for($driver)->test(
            $this->target($driver, $category, $endpoint, $secret),
        );
    }

    private function target(string $driver, ProviderCategory $category, ?string $endpoint, string $secret): TestTarget
    {
        return new TestTarget(
            driver: $driver,
            environment: DeploymentEnvironment::Staging,
            endpoint: $endpoint,
            secret: $secret,
            probeCapabilities: $category->capabilities(),
            identity: 'a row under test',
        );
    }

    private function proxmoxToken(): string
    {
        // Structurally a Proxmox token and the secret of nothing.
        return 'lynomia@pve!control=00000000-0000-0000-0000-000000000000';
    }

    private function fakeRedfish(string $manufacturer, ?string $oem = null): void
    {
        $root = ['@odata.id' => '/redfish/v1/', 'RedfishVersion' => '1.6.0', 'Systems' => ['@odata.id' => '/redfish/v1/Systems']];

        if ($oem !== null) {
            $root['Oem'] = [$oem => ['@odata.type' => '#HpeiLO.v2_0_0.HpeiLO']];
        }

        Http::fake([
            '*/redfish/v1/UpdateService/*' => Http::response(['Members' => []]),
            '*/redfish/v1/Systems/*' => Http::response([
                'Manufacturer' => $manufacturer,
                'Model' => 'PowerEdge R660',
                'PowerState' => 'On',
                'ProcessorSummary' => ['Model' => 'Xeon Gold 6430', 'Count' => 2],
                'MemorySummary' => ['TotalSystemMemoryGiB' => 512],
            ]),
            '*/redfish/v1/Systems' => Http::response(['Members' => [['@odata.id' => '/redfish/v1/Systems/System.Embedded.1']]]),
            '*redfish/v1/' => Http::response($root),
        ]);
    }

    private function stripeTarget(string $key): TestTarget
    {
        return new TestTarget(
            driver: 'stripe',
            environment: DeploymentEnvironment::Staging,
            endpoint: null,
            secret: $key,
            probeCapabilities: ProviderCategory::Payment->capabilities(),
        );
    }

    /**
     * The SDK's own HTTP seam, so that identity is proven through Stripe's
     * parsing rather than around it.
     *
     * @param  callable(string): array{0: string, 1: int, 2: array<mixed>}  $answer
     */
    private function stripeHttp(callable $answer): ClientInterface
    {
        return new class($answer) implements ClientInterface
        {
            /** @param callable(string): array{0: string, 1: int, 2: array<mixed>} $answer */
            public function __construct(private $answer) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return ($this->answer)((string) $absUrl);
            }
        };
    }

    protected function tearDown(): void
    {
        // The SDK's client is a static, so a fake left in place would leak
        // into every test that runs after this file.
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }
}
