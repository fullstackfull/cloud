<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * The customer's inbox.
 *
 * Read and acknowledge only: there is deliberately no endpoint that creates a
 * notification, and none that deletes one. A customer who could delete the
 * record of their service being terminated would be deleting the only copy
 * they have of what happened to their account.
 */
final class NotificationInboxTest extends VpsApiTestCase
{
    #[Test]
    public function a_customer_reads_their_own_notifications_newest_first(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $older = Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'created_at' => now()->subHour(),
        ]);
        $newer = Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $newer->getKey())
            ->assertJsonPath('data.1.id', (string) $older->getKey())
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unread', 2);
    }

    #[Test]
    public function the_body_is_rendered_in_the_readers_language(): void
    {
        /*
         * The row holds facts. A customer who switches the portal to Arabic
         * sees their whole history in Arabic, including messages raised months
         * before they switched — which a stored English sentence could not do.
         */
        [$customer, $user] = $this->accountWithOwner();

        Notification::factory()->ofType(NotificationType::ServiceReady)->create([
            'customer_id' => $customer->getKey(),
            'data' => ['service' => 'web-kw-01'],
        ]);

        $english = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk();
        $this->assertStringContainsString('ready', strtolower((string) $english->json('data.0.title')));

        $user->forceFill(['locale' => 'ar'])->save();

        $arabic = $this->actingAs($user)->getJson('/api/v1/notifications')->assertOk();
        $this->assertStringContainsString('جاهز', (string) $arabic->json('data.0.title'));

        // The hostname survives both, because it is a placeholder in one whole
        // sentence rather than a fragment concatenated around one.
        $this->assertStringContainsString('web-kw-01', (string) $arabic->json('data.0.title'));
    }

    #[Test]
    public function another_tenants_notifications_are_not_visible_or_readable(): void
    {
        [, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $theirNotification = Notification::factory()->create(['customer_id' => $theirs->getKey()]);

        $this->actingAs($me)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // 404, not 403: a forbidden answer would confirm the id exists.
        $this->actingAs($me)
            ->postJson('/api/v1/notifications/'.$theirNotification->id.'/read')
            ->assertNotFound();

        $this->assertNull($theirNotification->fresh()?->read_at);
    }

    #[Test]
    public function marking_read_is_idempotent_and_does_not_restamp(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $notification = Notification::factory()->create(['customer_id' => $customer->getKey()]);

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk();

        $first = $notification->refresh()->read_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk();

        // A second click must not move the time somebody actually read it.
        $this->assertEquals($first, $notification->refresh()->read_at);
    }

    #[Test]
    public function marking_all_read_clears_the_badge(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Notification::factory()->count(3)->create(['customer_id' => $customer->getKey()]);
        Notification::factory()->read()->create(['customer_id' => $customer->getKey()]);

        $this->actingAs($user)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            // Only the three that were unread.
            ->assertJsonPath('data.marked_read', 3)
            ->assertJsonPath('data.unread', 0);

        $this->assertSame(0, Notification::query()->whereNull('read_at')->count());
    }

    #[Test]
    public function the_unread_filter_returns_only_unread(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Notification::factory()->create(['customer_id' => $customer->getKey()]);
        Notification::factory()->read()->create(['customer_id' => $customer->getKey()]);

        $this->actingAs($user)
            ->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // The total describes the filtered set; the badge does not.
            ->assertJsonPath('meta.unread', 1);
    }

    #[Test]
    public function a_read_only_member_can_still_read_the_inbox(): void
    {
        // Notifications are the account's record of what happened to it, and
        // somebody who may look at the services may look at that record.
        [$customer] = $this->accountWithOwner();

        Notification::factory()->create(['customer_id' => $customer->getKey()]);

        $billing = $this->memberOf($customer, CustomerRole::Billing);

        $this->actingAs($billing)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function there_is_no_way_for_a_customer_to_create_or_delete_one(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $notification = Notification::factory()->create(['customer_id' => $customer->getKey()]);

        // 405: the URI exists for GET, so the router knows the path and
        // refuses the verb.
        $this->actingAs($user)->postJson('/api/v1/notifications', [])->assertStatus(405);

        // 404: there is no route at this URI for any verb. The two answers
        // differ for a reason worth keeping — the second says the path itself
        // was never built, which is the stronger statement.
        $this->actingAs($user)->deleteJson('/api/v1/notifications/'.$notification->id)->assertNotFound();

        $this->assertNotNull($notification->fresh());
    }
}
