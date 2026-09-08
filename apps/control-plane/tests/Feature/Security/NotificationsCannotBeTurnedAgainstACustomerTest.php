<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobFailed;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The notification system, attacked from three directions.
 *
 * A notification is the one thing this platform sends to a person's inbox
 * unprompted, and it carries two things worth stealing: other customers'
 * messages, and whatever the platform decides to put in a mail header. It also
 * carries a risk that has nothing to do with attackers — a provider's own
 * error text, forwarded to a customer, tells them about the fleet and tells
 * them nothing they can act on.
 */
final class NotificationsCannotBeTurnedAgainstACustomerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function another_customers_notification_cannot_be_read_or_marked_read(): void
    {
        [$mine, $me] = $this->account();
        [$theirs] = $this->account();

        $foreign = Notification::factory()->ofType(NotificationType::ServiceReady)->create([
            'customer_id' => $theirs->getKey(),
            'data' => ['service' => 'their-server'],
        ]);

        Notification::factory()->ofType(NotificationType::ServiceReady)->create([
            'customer_id' => $mine->getKey(),
            'data' => ['service' => 'my-server'],
        ]);

        // Not in the list.
        $listed = $this->actingAs($me)->getJson('/api/v1/notifications')->assertOk()->json('data');

        $this->assertCount(1, $listed);
        $this->assertStringNotContainsString('their-server', json_encode($listed, JSON_THROW_ON_ERROR));

        /*
         * And not reachable by id. 404 rather than 403: a customer who can
         * tell "exists, not yours" from "does not exist" can enumerate how
         * many notifications the platform has raised, and when.
         */
        $this->actingAs($me)
            ->postJson('/api/v1/notifications/'.$foreign->getKey().'/read')
            ->assertStatus(404);

        $this->assertNull($foreign->refresh()->read_at);
    }

    #[Test]
    public function a_failed_build_tells_the_customer_nothing_about_the_fleet(): void
    {
        /*
         * "capacity_exceeded" is the platform's vocabulary for its own
         * scheduler. A customer reading it learns nothing they can act on
         * while learning something about how full the fleet is — and a
         * provider's raw message can carry a node name, a storage name or a
         * URL.
         */
        [$customer] = $this->account();

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'label' => 'web-kw-01',
        ]);

        event(new ProvisioningJobFailed(
            provisioningJobId: '01JEXAMPLEEXAMPLEEXAMPLE00',
            kind: ProvisioningJobKind::CreateVps,
            serviceId: (string) $service->getKey(),
            failureClass: FailureClass::Capacity,
            errorCode: 'compute.capacity_exceeded',
        ));

        $notification = Notification::query()->where('customer_id', $customer->getKey())->sole();

        $serialised = json_encode($notification->data ?? [], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('capacity_exceeded', $serialised);
        $this->assertStringNotContainsString('pve-', $serialised);
        $this->assertStringNotContainsString('capacity', mb_strtolower($serialised));
    }

    #[Test]
    public function a_hostname_cannot_smuggle_a_mail_header(): void
    {
        /*
         * The customer names their own machine, and that name is interpolated
         * into the subject line of an email the platform sends. A newline in a
         * subject is a header, and a header a customer chose is a Bcc a
         * customer chose.
         */
        Mail::fake();

        [$customer] = $this->account();

        $notification = Notification::factory()->ofType(NotificationType::ServiceReady)->create([
            'customer_id' => $customer->getKey(),
            'data' => ['service' => "web-kw-01\r\nBcc: everyone@example.test", 'image' => ''],
        ]);

        $rendered = app(RenderNotification::class)->execute($notification, 'en');

        $this->assertStringNotContainsString("\r", $rendered->title);
        $this->assertStringNotContainsString("\n", $rendered->title);

        /*
         * The word survives, and should: "Bcc:" in the middle of a subject is
         * text a customer chose to call their server, and censoring words
         * would be pretending to solve a problem that is entirely about the
         * line break. What matters is that the subject is still one line.
         */
        $this->assertSame(1, substr_count($rendered->title, "\n") + 1);
    }

    #[Test]
    public function a_customer_cannot_raise_a_notification_for_somebody_else(): void
    {
        // There is no endpoint that creates one, and this is the assertion
        // that keeps it that way: everything a customer can reach is a read or
        // a read-receipt.
        [$mine, $me] = $this->account();
        [$theirs] = $this->account();

        app(NotifyCustomer::class)->execute(
            customerId: (string) $theirs->getKey(),
            type: NotificationType::ServiceReady,
            idempotencyKey: 'security-test:1',
            data: ['service' => 'their-server'],
        );

        $this->assertSame(
            0,
            Notification::query()->where('customer_id', $mine->getKey())->count(),
        );

        $this->actingAs($me)
            ->postJson('/api/v1/notifications', ['type' => 'service.ready'])
            ->assertStatus(405);
    }

    /**
     * @return array{0: Customer, 1: User}
     */
    private function account(): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $customer->members()->create([
            'user_id' => $user->getKey(),
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        return [$customer, $user];
    }
}
