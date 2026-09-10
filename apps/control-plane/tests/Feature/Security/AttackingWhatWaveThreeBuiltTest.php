<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * The adversarial pass over what Wave 3 built.
 *
 * Giving every resource a page changed the shape of the attack surface in one
 * specific way: an id that used to appear only inside a list the server built
 * is now a segment of a URL a person types. So the questions are the same six
 * questions in six places — can I read somebody else's machine by putting
 * their id in my address bar, can I act on their subscription, can I reach an
 * action the screen had disabled — and the answers have to come from the
 * server, because a disabled button is a courtesy and not a control.
 *
 * Every case here writes nothing.
 */
final class AttackingWhatWaveThreeBuiltTest extends VpsApiTestCase
{
    private Customer $mine;

    private User $me;

    private Customer $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->mine, $this->me] = $this->accountWithOwner();
        [$this->theirs] = $this->accountWithOwner();
    }

    /* ---------------------------------------------------------------------
     | Reading somebody else's resource by typing its id
     */

    #[Test]
    public function every_resource_page_reads_nothing_that_belongs_to_another_account(): void
    {
        $theirMachine = $this->machineFor($this->theirs, hostname: 'not-mine-01');

        $theirChassis = DedicatedServer::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'serial' => 'SN-NOT-MINE',
        ]);

        $theirAccount = HostingAccount::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'hosting_node_id' => HostingNode::factory()->create()->getKey(),
            'hosting_package_id' => HostingPackage::factory()->create()->getKey(),
            'primary_domain' => 'not-mine.test',
        ]);

        $theirSite = WordPressSite::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'domain' => 'not-mine-site.test',
        ]);

        $theirDomain = Domain::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'name' => 'not-mine.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
        ]);

        $theirZone = DnsZone::factory()->active()->create([
            'customer_id' => $this->theirs->getKey(),
            'name' => 'not-mine-zone.test',
        ]);

        /*
         * Six families, and the same answer from all six: not found. Every one
         * of these lookups starts from the acting customer rather than
         * fetching the row and then checking who owns it, so there is no
         * moment at which the request holds another tenant's data — which is
         * also why the answer is 404 rather than 403. A 403 would confirm the
         * id names something real.
         */
        $addresses = [
            '/api/v1/vps/'.$theirMachine->getKey(),
            '/api/v1/vps/'.$theirMachine->getKey().'/templates',
            '/api/v1/vps/'.$theirMachine->getKey().'/backups',
            '/api/v1/dedicated/'.$theirChassis->getKey(),
            '/api/v1/hosting/'.$theirAccount->getKey(),
            '/api/v1/hosting/'.$theirAccount->getKey().'/usage',
            '/api/v1/wordpress/sites/'.$theirSite->getKey(),
            '/api/v1/domains/'.$theirDomain->getKey(),
            '/api/v1/domains/not-mine.test',
            '/api/v1/domains/not-mine.test/contacts',
            '/api/v1/dns/zones/'.$theirZone->getKey(),
            '/api/v1/dns/zones/not-mine-zone.test',
            '/api/v1/dns/zones/not-mine-zone.test/records',
            '/api/v1/services/'.$theirMachine->service_id,
            '/api/v1/services/'.$theirMachine->service_id.'/events',
        ];

        foreach ($addresses as $address) {
            $response = $this->actingAs($this->me)->getJson($address);

            $this->assertSame(
                404,
                $response->getStatusCode(),
                sprintf('%s answered %d for another account.', $address, $response->getStatusCode()),
            );

            $body = $response->getContent();
            $this->assertIsString($body);

            // And nothing about the resource leaks in the refusal itself.
            foreach (['not-mine-01', 'SN-NOT-MINE', 'not-mine.test', 'not-mine-zone.test'] as $identity) {
                $this->assertStringNotContainsString($identity, $body);
            }
        }
    }

    /* ---------------------------------------------------------------------
     | Acting on somebody else's resource
     */

    #[Test]
    public function another_accounts_subscription_cannot_be_ended_from_my_resource_page(): void
    {
        // The resource pages offer a cancellation, and the id travels in the
        // path. A subscription belonging to somebody else is not found.
        $theirSubscription = Subscription::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'status' => SubscriptionStatus::Active,
        ]);

        $this->actingAs($this->me)
            ->postJson('/api/v1/subscriptions/'.$theirSubscription->getKey().'/cancel', [
                'confirmation' => (string) $theirSubscription->getKey(),
            ])
            ->assertNotFound();

        $this->assertSame(
            SubscriptionStatus::Active,
            $theirSubscription->refresh()->status,
        );
    }

    #[Test]
    public function another_accounts_domain_cannot_have_its_renewal_setting_changed_by_name(): void
    {
        $theirDomain = Domain::factory()->create([
            'customer_id' => $this->theirs->getKey(),
            'name' => 'not-mine.test',
            'tld' => 'test',
            'state' => DomainState::Active,
            'provider' => 'fake',
            'auto_renew' => true,
        ]);

        // A domain name is guessable in a way a ULID is not, which is exactly
        // why the lookup is scoped rather than filtered.
        $this->actingAs($this->me)
            ->putJson('/api/v1/domains/not-mine.test/auto-renew', ['auto_renew' => false])
            ->assertNotFound();

        $this->assertTrue((bool) $theirDomain->refresh()->auto_renew);
    }

    /* ---------------------------------------------------------------------
     | Reaching an action the screen had disabled
     */

    #[Test]
    public function a_control_the_screen_disables_is_refused_by_the_server_too(): void
    {
        /*
         * The resource pages disable controls on the API's own published
         * availability. That is a courtesy: it saves a customer from a
         * pointless refusal. The refusal is where the rule actually lives, so
         * each of these is sent past the disabled button.
         */
        $suspended = $this->machineFor($this->mine, status: ServiceStatus::Suspended);

        $this->actingAs($this->me)
            ->withHeader('Idempotency-Key', 'bypassing-a-disabled-start')
            ->postJson('/api/v1/vps/'.$suspended->getKey().'/power', ['action' => 'start'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.not_active');

        $this->actingAs($this->me)
            ->withHeader('Idempotency-Key', 'bypassing-a-disabled-rebuild')
            ->postJson('/api/v1/vps/'.$suspended->getKey().'/reinstall', [
                'confirm_hostname' => $suspended->hostname,
            ])
            ->assertStatus(409);

        // A machine with a job already live against it: the page shows the
        // reason and the endpoint enforces it.
        $busy = $this->machineFor($this->mine, hostname: 'busy-01');

        $this->jobAgainst($busy);

        $this->actingAs($this->me)
            ->withHeader('Idempotency-Key', 'bypassing-a-disabled-reboot')
            ->postJson('/api/v1/vps/'.$busy->getKey().'/power', ['action' => 'reboot'])
            ->assertStatus(409);
    }

    #[Test]
    public function an_image_that_is_not_offered_for_this_machine_is_refused_whatever_shape_the_id_is(): void
    {
        $machine = $this->machineFor($this->mine, hostname: 'web-01');

        // A ULID that names something real of an entirely different kind, and
        // one that names nothing at all. Both are 404, and neither tells the
        // caller which.
        $aPlan = Plan::factory()->create();

        foreach ([(string) $aPlan->getKey(), '01JZZZZZZZZZZZZZZZZZZZZZZZ'] as $templateId) {
            $this->actingAs($this->me)
                ->withHeader('Idempotency-Key', 'rebuild-with-'.$templateId)
                ->postJson('/api/v1/vps/'.$machine->getKey().'/reinstall', [
                    'confirm_hostname' => 'web-01',
                    'template_id' => $templateId,
                ])
                ->assertNotFound();
        }
    }

    /* ---------------------------------------------------------------------
     | The operator area
     */

    #[Test]
    public function the_operator_area_is_closed_to_a_customer_who_types_its_address(): void
    {
        /*
         * The portal hides the operator navigation from a login with no
         * operator permission. That is presentation: the same session reaches
         * `/api/admin`, because both surfaces authenticate with the same
         * guard, and every administrative route carries the permission that
         * actually refuses.
         */
        foreach ([
            '/api/admin/customers',
            '/api/admin/provisioning/jobs',
            '/api/admin/infrastructure/servers',
        ] as $address) {
            $response = $this->actingAs($this->me)->getJson($address);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                sprintf('%s answered %d to a customer.', $address, $response->getStatusCode()),
            );
        }
    }

    /**
     * A provisioning job live against a machine's service.
     */
    private function jobAgainst(mixed $machine): void
    {
        ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'kind' => ProvisioningJobKind::Restart,
            'status' => ProvisioningJobStatus::Running,
        ]);
    }
}
