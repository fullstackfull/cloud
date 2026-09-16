<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Domains\Domain\Enums\DomainContactRole;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainContact;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\CustomerInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressSiteState;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Support\Infrastructure\Models\SupportAttachment;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

/**
 * One account, holding one of everything a customer can address.
 *
 * The inventory W5.9 §2 asks for, as rows rather than as a list in a document:
 * a machine, a chassis, a hosting account, a WordPress site, a domain, a zone
 * and a record in it, a backup, an order, an invoice, a payment, a
 * subscription, a wallet, an address assignment, a job in flight, a support
 * ticket with a message and an attachment, a notification, a colleague, an
 * unaccepted invitation, a browser session, an API token, and an open
 * country-and-currency change request.
 *
 * Each object is created in a state the customer surface will actually serve.
 * That matters more than it sounds: `CustomerDedicatedServers` excludes a
 * retired chassis and `CustomerIpAssignments` excludes a released address, so
 * a factory default would have produced a row that 404s for its *owner* — and
 * a cross-tenant test whose fixture 404s for everybody proves nothing at all.
 * The own-tenant walk in the matrix test is what holds this honest.
 */
trait BuildsACustomerObjectGraph
{
    /**
     * @return array<string, string>
     */
    protected function objectGraphFor(Customer $customer, User $user): array
    {
        $tag = Str::lower(Str::random(6));

        // ── The service a machine hangs off ──────────────────────────────
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Active,
            'label' => "vps-{$tag}",
            'activated_at' => now(),
        ]);

        $cluster = ComputeCluster::factory()->create();

        $vm = VirtualMachine::factory()->create([
            'service_id' => $service->id,
            'cluster_id' => $cluster->id,
            'hostname' => "vps-{$tag}.example.test",
        ]);

        // ── A chassis, in a state the customer surface serves ────────────
        $server = DedicatedServer::factory()->create([
            'customer_id' => $customer->id,
            'status' => DedicatedServerStatus::Active,
            'retired_at' => null,
        ]);

        // ── Hosting and WordPress ───────────────────────────────────────
        $hosting = HostingAccount::factory()->create(['customer_id' => $customer->id]);

        $site = WordPressSite::factory()->create([
            'customer_id' => $customer->id,
            'domain' => "wp-{$tag}.test",
            'state' => WordPressSiteState::Ready,
            'installed' => true,
            'dns_ready' => true,
        ]);

        // ── A domain and a zone with a record in it ─────────────────────
        $domain = Domain::factory()->create([
            'customer_id' => $customer->id,
            'name' => "held-{$tag}.test",
            'state' => DomainState::Active,
        ]);

        DomainContact::factory()->create([
            'domain_id' => $domain->id,
            'role' => DomainContactRole::Registrant,
        ]);

        $zone = DnsZone::factory()->create([
            'customer_id' => $customer->id,
            'name' => "zone-{$tag}.test",
            'state' => DnsState::Active,
        ]);

        $record = DnsRecord::factory()->create([
            'dns_zone_id' => $zone->id,
            'state' => DnsState::Active,
        ]);

        // ── A backup of that machine ────────────────────────────────────
        /*
         * `virtual_machine_id` is what makes this backup reachable, and saying
         * so is the point. `GET /vps/{vm}/backups/{backup}` resolves the child
         * as `->where('virtual_machine_id', $machine->getKey())
         * ->whereKey($backup)`, so a backup carrying only the service id is a
         * row that 404s for its own owner. That is the §3 rule working: the
         * parent being owned is not what authorises the child.
         */
        $backup = Backup::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'virtual_machine_id' => $vm->id,
            'cluster_id' => $cluster->id,
            'state' => BackupState::Succeeded,
            'size_bytes' => 4_294_967_296,
            'finished_at' => now(),
        ]);

        // ── Money ───────────────────────────────────────────────────────
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $invoice = Invoice::factory()->create(['customer_id' => $customer->id]);
        $payment = Transaction::factory()->create(['customer_id' => $customer->id]);
        $plan = Plan::factory()->create();
        $price = PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency' => 'KWD']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
        ]);
        Wallet::factory()->create(['customer_id' => $customer->id, 'balance_minor' => 50_000]);

        // ── An address still held ───────────────────────────────────────
        $assignment = IpAssignment::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'released_at' => null,
        ]);

        // ── Work in flight ──────────────────────────────────────────────
        $operation = ProvisioningJob::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
        ]);

        // ── Support: a ticket, a customer-visible message, an attachment ─
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->id]);

        $message = SupportMessage::factory()->create([
            'ticket_id' => $ticket->id,
            'is_internal_note' => false,
        ]);

        $attachment = SupportAttachment::query()->create([
            'message_id' => $message->id,
            'disk' => 'local',
            'path' => "support/{$tag}.txt",
            'original_name' => 'server.log',
            'mime_type' => 'text/plain',
            'size_bytes' => 512,
            'checksum' => hash('sha256', 'server.log'),
        ]);

        // ── A notification ──────────────────────────────────────────────
        $notification = Notification::factory()->create([
            'customer_id' => $customer->id,
            'read_at' => null,
        ]);

        // ── A colleague, and somebody invited but not yet joined ────────
        $colleague = User::factory()->create(['email_verified_at' => now()]);
        $member = $customer->members()->create([
            'user_id' => $colleague->id,
            'role' => CustomerRole::Member,
            'accepted_at' => now(),
        ]);

        /*
         * Its own token. The factory's default hashes one fixed secret, which
         * is right for a test that needs to know the token — and wrong here,
         * because the matrix builds two accounts and the second one collided
         * with the first on a unique index.
         */
        $invitation = CustomerInvitation::factory()->create([
            'customer_id' => $customer->id,
            'accepted_at' => null,
            'token_hash' => CustomerInvitation::hashOf("invite-{$tag}-".bin2hex(random_bytes(8))),
        ]);

        // ── A browser session and an API token, both this user's ────────
        $session = $this->aSessionFor($user);

        /*
         * forceCreate, because Sanctum's base model declares its own
         * `$fillable` — name, token, abilities, expires_at — and a `$fillable`
         * beats this subclass's `$guarded`. The columns that make a token
         * belong to somebody are therefore not mass-assignable, which is the
         * right default for a credential and means a fixture has to say so.
         */
        $token = PersonalAccessToken::query()->forceCreate([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->id,
            'customer_id' => $customer->id,
            'name' => "automation-{$tag}",
            'token' => hash('sha256', Str::random(40)),
            'abilities' => ['*'],
        ]);

        // An active install profile, so a reinstall body names one the
        // platform will accept and the case reaches the tenancy scope.
        $profile = OsInstallProfile::factory()->create(['is_active' => true]);

        // ── An open change request ──────────────────────────────────────
        $change = $this->anOpenCountryChangeFor($customer, $user);

        return [
            'vm' => (string) $vm->id,
            'vm_hostname' => (string) $vm->hostname,
            'server' => (string) $server->id,
            'server_serial' => (string) $server->serial,
            'hosting' => (string) $hosting->id,
            'site' => (string) $site->id,
            'site_domain' => (string) $site->domain,
            'domain' => (string) $domain->id,
            'zone' => (string) $zone->id,
            'zone_name' => (string) $zone->name,
            'record' => (string) $record->id,
            'backup' => (string) $backup->id,
            'order' => (string) $order->id,
            'invoice' => (string) $invoice->id,
            'payment' => (string) $payment->id,
            'plan' => (string) $plan->id,
            'price' => (string) $price->id,
            'os_profile' => (string) $profile->slug,
            'subscription' => (string) $subscription->id,
            'assignment' => (string) $assignment->id,
            'operation' => (string) $operation->id,
            'service' => (string) $service->id,
            'ticket' => (string) $ticket->id,
            'attachment' => (string) $attachment->id,
            'notification' => (string) $notification->id,
            'member' => (string) $member->id,
            'invitation' => (string) $invitation->id,
            'session' => $session,
            'token' => (string) $token->id,
            'change' => $change,
        ];
    }

    /**
     * A row in the session table, written directly.
     *
     * The sessions table is the driver's, not a model's, and the endpoint under
     * test lists and revokes rows in it. Writing one is the only way to have a
     * session belonging to a *particular* user that another user can then try
     * to revoke.
     */
    private function aSessionFor(User $user): string
    {
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    private function anOpenCountryChangeFor(Customer $customer, User $user): string
    {
        $id = (string) Str::ulid();

        DB::table('customer_country_currency_changes')->insert([
            'id' => $id,
            'customer_id' => $customer->id,
            'requested_by_user_id' => $user->id,
            'from_country' => 'KW',
            'to_country' => 'AE',
            'from_currency' => 'KWD',
            'to_currency' => 'AED',
            'state' => 'requested',
            'reason' => 'Relocating the business.',
            /*
             * The shape CountryCurrencyImpact::toArray() writes, because that
             * is the only thing that ever writes this column and the resource
             * reads `$impact['facts']` without a fallback. A fixture inventing
             * its own shape 500s the endpoint — which is a fixture fault, not a
             * product one, and worth writing down so the next reader does not
             * re-diagnose it as a defect.
             */
            'impact' => json_encode([
                'facts' => ['open_invoices' => 0, 'active_subscriptions' => 1, 'wallet_balance_minor' => 50000],
                'blockers' => [],
                'warnings' => [],
            ]),
            'analysed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
