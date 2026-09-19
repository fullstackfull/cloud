<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * "Your server is ready" lands on the server.
 *
 * The inbox published a hardcoded collection path — every service notification
 * pointed at `/services` — so a customer with six machines was told one of
 * them was ready and left to work out which. Wave 3 resolves the notification's
 * own stored subject into a `{kind, id}` handle.
 *
 * The word "resolved" is the point. Nothing is inferred from the title, the
 * body, the type or the timestamp: a deep link guessed from text is a link to
 * somebody else's resource waiting to happen. Where the subject is not
 * something with a page of its own, the handle is null and the collection link
 * remains the answer.
 */
final class ANotificationPointsAtTheThingItIsAboutTest extends VpsApiTestCase
{
    #[Test]
    public function a_notification_about_a_service_points_at_the_machine_that_service_built(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $machine = $this->machineFor($customer, hostname: 'web-kw-01');
        $service = Service::query()->findOrFail($machine->service_id);

        Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'subject_type' => $service->getMorphClass(),
            'subject_id' => (string) $service->getKey(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.resource.kind', 'vps')
            // The machine's id, not the service's: the portal's address for a
            // machine is /vps/{machine}, and a handle carrying a service id
            // would be a link to nothing.
            ->assertJsonPath('data.0.resource.id', (string) $machine->getKey());
    }

    #[Test]
    public function a_notification_about_an_invoice_points_at_the_invoice(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $invoice = Invoice::factory()->create(['customer_id' => $customer->getKey()]);

        Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => (string) $invoice->getKey(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.resource.kind', 'invoice')
            ->assertJsonPath('data.0.resource.id', (string) $invoice->getKey());
    }

    #[Test]
    public function a_notification_with_no_subject_carries_no_handle_and_keeps_its_collection_link(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // A message about the account itself: there is no resource page for
        // it, and inventing one would be worse than the list it already links
        // to.
        Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.resource', null);

        // The link the inbox has always sent is still there, so a client falls
        // back to it rather than rendering a row with no way in.
        $this->assertArrayHasKey('link', (array) $response->json('data.0'));
    }

    #[Test]
    public function a_service_whose_machine_no_longer_exists_carries_no_handle(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // A terminated service: the row is kept for the record and the machine
        // behind it is gone. A handle here would point at a page that 404s.
        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'status' => ServiceStatus::Terminated,
        ]);

        Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'subject_type' => $service->getMorphClass(),
            'subject_id' => (string) $service->getKey(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.resource', null);
    }

    #[Test]
    public function marking_one_read_answers_with_the_same_handle_as_the_list(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $machine = $this->machineFor($customer, hostname: 'web-kw-02');
        $service = Service::query()->findOrFail($machine->service_id);

        $notification = Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'subject_type' => $service->getMorphClass(),
            'subject_id' => (string) $service->getKey(),
        ]);

        // The same document from both endpoints, so a client that re-renders
        // a row after acknowledging it does not lose the way in.
        $this->actingAs($user)
            ->postJson('/api/v1/notifications/'.$notification->getKey().'/read')
            ->assertOk()
            ->assertJsonPath('data.resource.kind', 'vps')
            ->assertJsonPath('data.resource.id', (string) $machine->getKey());
    }

    #[Test]
    public function another_accounts_notification_is_not_readable_and_leaks_no_handle(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirMachine = $this->machineFor($theirs, hostname: 'not-mine-01');
        $theirService = Service::query()->findOrFail($theirMachine->service_id);

        $theirNotification = Notification::factory()->create([
            'customer_id' => $theirs->getKey(),
            'subject_type' => $theirService->getMorphClass(),
            'subject_id' => (string) $theirService->getKey(),
        ]);

        $this->machineFor($mine);

        $response = $this->actingAs($me)->getJson('/api/v1/notifications')->assertOk();

        $this->assertSame(0, $response->json('meta.total'));

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString((string) $theirMachine->getKey(), $body);

        $this->actingAs($me)
            ->postJson('/api/v1/notifications/'.$theirNotification->getKey().'/read')
            ->assertNotFound();
    }
}
