<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/dedicated/{server}/power.
 *
 * Three verbs against a physical chassis. The tests that matter here are not
 * the happy paths — they are the machine that belongs to somebody else, the
 * machine an operator has open on a bench, and the controller that stops
 * answering halfway through a reset.
 */
final class DedicatedServerPowerEndpointTest extends DedicatedApiTestCase
{
    /**
     * Put a controller of our own behind one machine, so the test can see
     * which physical operation was issued rather than only its effect.
     */
    private function recordController(
        DedicatedServer $server,
        PowerState $reportedState = PowerState::On,
        bool $timeOut = false,
    ): RecordingDedicatedProvider {
        $provider = new RecordingDedicatedProvider($reportedState, $timeOut);

        $endpoint = $server->preferredBmcEndpoint();
        $this->assertNotNull($endpoint);

        $this->app->make(DedicatedProviderFactory::class)->swap($endpoint, $provider);

        return $provider;
    }

    #[Test]
    public function powering_a_machine_on_is_accepted(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['power_state' => PowerState::Off]);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            // 202: the controller took the instruction, the chassis has not
            // finished acting on it.
            ->assertStatus(202)
            ->assertJsonPath('data.id', $server->id)
            ->assertJsonPath('data.power_state', 'on')
            ->assertJsonPath('meta.action', 'on')
            ->assertJsonPath('meta.accepted', true);

        $this->assertSame(PowerState::On, $server->refresh()->power_state);
    }

    #[Test]
    public function off_asks_the_operating_system_rather_than_cutting_the_power(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        $controller = $this->recordController($server);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'off'])
            ->assertStatus(202);

        /*
         * ACPI, not the power rail. A hard cut loses whatever the host had not
         * flushed, and the customer API does not offer it at all — so `off`
         * must never resolve to powerOff().
         */
        $this->assertSame(['graceful_shutdown'], $controller->calls);
    }

    #[Test]
    public function asking_a_host_to_shut_down_is_not_an_observation_that_it_did(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['power_state' => PowerState::On]);

        /*
         * The recording controller answers exactly as the Redfish adapter
         * does: a GracefulShutdown is reported with a resulting state of Off
         * the moment the controller accepts it — and a controller accepts it
         * whether or not anything inside the machine is listening.
         *
         * A host with no acpid, a hung kernel or a dialog box open stays up.
         * Writing `off` on the strength of the request would leave the
         * platform's own record saying a machine that is still serving traffic
         * is powered down, and the customer reading `is_powered_on: false`
         * under a server they can still ssh into.
         */
        $this->recordController($server);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'off'])
            ->assertStatus(202)
            // The 202 carries the whole truth: taken, not finished.
            ->assertJsonPath('meta.accepted', true)
            ->assertJsonPath('data.power_state', 'on')
            ->assertJsonPath('data.is_powered_on', true);

        $this->assertSame(PowerState::On, $server->refresh()->power_state);
    }

    #[Test]
    public function cycling_a_running_machine_resets_it(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        $controller = $this->recordController($server, PowerState::On);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            ->assertStatus(202);

        // Read first, then reset. Sending power-on to a machine that is
        // already running does nothing, and the customer would be told their
        // server was rebooting when it never did.
        $this->assertSame(['power_state', 'reset'], $controller->calls);
    }

    #[Test]
    public function cycling_a_machine_that_is_off_powers_it_on_instead_of_resetting_it(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['power_state' => PowerState::Off]);

        $controller = $this->recordController($server, PowerState::Off);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            ->assertStatus(202);

        $this->assertSame(['power_state', 'power_on'], $controller->calls);
    }

    #[Test]
    public function a_machine_whose_state_is_unknown_is_reset_rather_than_powered_on(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        // A controller that did not say is not a machine that is off. Treating
        // the two alike is how a running host is left untouched while its
        // owner watches for a reboot that never comes.
        $controller = $this->recordController($server, PowerState::Unknown);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            ->assertStatus(202);

        $this->assertSame(['power_state', 'reset'], $controller->calls);
    }

    #[Test]
    public function a_controller_that_stops_answering_is_not_a_failure_and_is_never_retried(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['power_state' => PowerState::On]);

        $controller = $this->recordController($server, PowerState::On, timeOut: true);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            // 504, not 502. "The upstream failed" invites a retry; "the
            // platform stopped waiting" is the answer a sensible client treats
            // as unknown.
            ->assertStatus(504)
            ->assertJsonPath('error.code', 'dedicated.power_operation_indeterminate')
            ->assertJsonPath('error.details.safe_to_retry', false);

        // Exactly one reset was attempted. The chassis may be going down right
        // now; a second one interrupts an install or a shutdown mid-flight.
        $this->assertSame(1, $controller->mutationsAttempted);

        // And nothing was written from a guess: the column still says what was
        // last observed, not what was hoped for.
        $this->assertSame(PowerState::On, $server->refresh()->power_state);
    }

    #[Test]
    public function a_controller_that_refuses_out_loud_is_a_gateway_failure(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);
        // The fake refuses any address carrying this marker, out loud — which
        // means nothing happened and the caller may try again.
        $server->bmcEndpoints()->firstOrFail()->forceFill([
            'address' => FakeDedicatedProvider::addressWith('192.0.2.40', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER),
        ])->save();

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            ->assertStatus(502)
            // Not the BMC layer's own code: that exception is written for an
            // operator and carries an operator's detail, and the renderer
            // publishes a domain exception's context verbatim.
            ->assertJsonPath('error.code', 'dedicated.server_control_unavailable')
            // A refusal spoken out loud settles what happened: nothing.
            ->assertJsonPath('error.details.safe_to_retry', true);
    }

    #[Test]
    public function a_failing_controller_is_not_described_to_the_customer(): void
    {
        [$customer, $user] = $this->accountWith();

        $server = $this->serverFor($customer);
        $endpoint = $server->bmcEndpoints()->firstOrFail();
        $endpoint->forceFill([
            'address' => FakeDedicatedProvider::addressWith('192.0.2.40', FakeDedicatedProvider::PROVIDER_FAILURE_MARKER),
        ])->save();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            ->assertStatus(502);

        $body = $response->getContent();
        $this->assertIsString($body);

        /*
         * The whole point of the translation.
         *
         * `error.message` and `error.details` are published verbatim from a
         * domain exception, and the BMC layer's exceptions carry — by design,
         * for an operator — the management address, the adapter's name, the
         * internal operation verb and the controller's own prose. Every one of
         * those is a field DedicatedServerResource is written to withhold; the
         * error path must not be the way they get out.
         */
        foreach ([
            $endpoint->address,
            '192.0.2.40',
            'bmc-secret-key-name',
            'power_on',
            'BMC adapter',
            'refused this address by design',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body, $internal.' leaked into the error body');
        }

        /** @var array<string, mixed> $details */
        $details = (array) $response->json('error.details');

        foreach (['provider', 'operation', 'address', 'provider_message', 'path', 'status', 'bmc_endpoint_id'] as $field) {
            $this->assertArrayNotHasKey($field, $details, $field.' is in the customer-visible error details');
        }
    }

    #[Test]
    public function a_controller_the_platform_cannot_log_in_to_never_names_the_key_its_password_is_read_from(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);
        $endpoint = $server->bmcEndpoints()->firstOrFail();

        /*
         * The real factory rather than the fake, with nothing configured under
         * the row's credentials_reference — the shape of a machine racked
         * before its controller credential was added to configuration.
         *
         * The exception the factory raises names the configuration key the BMC
         * password is read from. It is not a secret, but it is the name of the
         * place the secret lives, and a customer asking their own server to
         * power on has no business learning it.
         */
        config()->set('dedicated.provider', null);
        config()->set('dedicated.credentials', []);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'dedicated.server_control_unavailable');

        $body = $response->getContent();
        $this->assertIsString($body);

        $this->assertStringNotContainsString('bmc-secret-key-name', $body);
        $this->assertStringNotContainsString((string) $endpoint->getKey(), $body);
        $this->assertStringNotContainsString('credentials', $body);
    }

    #[Test]
    public function a_machine_that_is_being_rebuilt_is_not_reset_while_the_installer_runs(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNPOWER00001']);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-then-reset-01')
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNPOWER00001'])
            ->assertStatus(202);

        // A reinstall deliberately leaves the machine `active` — the status
        // change belongs to the handler — so the in-service check alone would
        // wave this straight through to a chassis reset mid-erase.
        $this->assertSame(DedicatedServerStatus::Active, $server->refresh()->status);

        $controller = $this->recordController($server);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dedicated.operation_refused')
            ->assertJsonPath('error.details.in_flight_kind', 'reinstall');

        $this->assertSame([], $controller->calls);
    }

    #[Test]
    public function an_unrelated_job_on_the_service_does_not_take_the_recovery_button_away(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        /*
         * The mirror of the test above, and the reason the guard is scoped to
         * reinstalls rather than to "anything in flight". A power request is
         * how a customer recovers a host that has stopped listening; a billing
         * suspend queued against the same service has nothing to do with the
         * chassis, and a job stuck in `running` must not cost them the button.
         */
        ProvisioningJob::factory()
            ->kind(ProvisioningJobKind::Suspend)
            ->status(ProvisioningJobStatus::Running)
            ->create([
                'service_id' => $server->service_id,
                'customer_id' => $customer->getKey(),
                'payload' => ['dedicated_server_id' => $server->id],
            ]);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            ->assertStatus(202);
    }

    #[Test]
    public function the_power_limiter_does_not_spend_the_reinstall_allowance(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['serial' => 'SNPOWER00002', 'power_state' => PowerState::Off]);

        /*
         * Five power requests, against a reinstall limiter of five per minute.
         *
         * A numeric `throttle:N,1` keys on the caller alone, so without a
         * per-route prefix both write routes — and every other unprefixed
         * numeric limiter in the application — share one counter per user, and
         * this loop would leave the customer unable to rebuild a machine they
         * have just been told is compromised.
         */
        foreach (range(1, 5) as $ignored) {
            $this->actingAs($user)
                ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
                ->assertStatus(202);
        }

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-after-power-01')
            ->postJson("/api/v1/dedicated/{$server->id}/reinstall", ['confirm_serial' => 'SNPOWER00002'])
            ->assertStatus(202);
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->serverFor($theirs);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$hers->id}/power", ['action' => 'cycle'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        // And nothing happened to her machine.
        $this->assertSame(PowerState::On, $hers->refresh()->power_state);
    }

    #[Test]
    public function a_customer_id_in_the_body_does_not_widen_anything(): void
    {
        [, $user] = $this->accountWith();
        [$theirs] = $this->accountWith();

        $hers = $this->serverFor($theirs);

        // A customer id arriving in a body is a request to act on somebody
        // else's data. It is not read, so it changes nothing.
        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$hers->id}/power", [
                'action' => 'cycle',
                'customer_id' => $theirs->getKey(),
            ])
            ->assertStatus(404);
    }

    #[Test]
    public function a_machine_under_maintenance_is_refused(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['status' => DedicatedServerStatus::Maintenance]);

        $controller = $this->recordController($server);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            // A customer power cycling a host in the middle of a firmware
            // flash bricks it in a way no support ticket recovers.
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dedicated.operation_refused')
            ->assertJsonPath('error.details.status', 'maintenance');

        $this->assertSame([], $controller->calls);
    }

    #[Test]
    public function a_machine_that_is_still_being_installed_is_refused(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['status' => DedicatedServerStatus::Provisioning]);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'off'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.status', 'provisioning');
    }

    #[Test]
    public function an_unrecognised_verb_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        $controller = $this->recordController($server);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'ipmitool chassis power off'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['action']]]]);

        // Nothing composed from the caller's words ever reached a controller.
        $this->assertSame([], $controller->calls);
    }

    #[Test]
    public function a_missing_action_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['action']]]]);
    }

    #[Test]
    public function a_member_who_may_only_watch_the_account_cannot_power_anything(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $watcher = $this->memberOf($customer, CustomerRole::Member);

        $server = $this->serverFor($customer);
        $controller = $this->recordController($server);

        $this->actingAs($watcher)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'cycle'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame([], $controller->calls);
    }

    #[Test]
    public function the_response_says_nothing_about_the_controller_that_did_the_work(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);
        $endpoint = $server->bmcEndpoints()->firstOrFail();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            ->assertStatus(202);

        $body = $response->getContent();
        $this->assertIsString($body);

        foreach ([$endpoint->address, 'bmc-secret-key-name', 'redfish', 'power_on'] as $internal) {
            // The operation's own verb is audit vocabulary — "used in logs and
            // audit records, never shown to a customer" — and so is the
            // protocol the platform happens to speak to its own hardware.
            $this->assertStringNotContainsString($internal, $body, $internal.' leaked into the response');
        }

        /** @var array<string, mixed> $meta */
        $meta = (array) $response->json('meta');

        foreach (['endpoint_id', 'protocol', 'task_id', 'operation'] as $field) {
            $this->assertArrayNotHasKey($field, $meta);
        }
    }

    #[Test]
    public function a_machine_with_no_management_controller_cannot_be_powered_by_anybody(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);
        $server->bmcEndpoints()->delete();

        $this->actingAs($user)
            ->postJson("/api/v1/dedicated/{$server->id}/power", ['action' => 'on'])
            // A platform fault, not a customer one: the machine was sold
            // without a way to reach it out of band. Reported in the customer
            // surface's own vocabulary rather than the BMC layer's, which is
            // what keeps the endpoint id and the address out of the body.
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'dedicated.server_control_unavailable');
    }
}
