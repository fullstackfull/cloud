<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationPreference;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which optional messages a person wants, and which they may not refuse.
 *
 * The API refuses a change it would not honour. A setting that appears to save
 * and then does nothing is worse than one that says no: the first leaves a
 * customer believing they will not be emailed, and the second is a policy they
 * can read.
 */
final class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    #[Test]
    public function every_category_is_listed_with_whether_it_can_be_changed(): void
    {
        /*
         * Listed rather than omitted. A screen that simply left security and
         * billing out would leave a customer wondering whether they had been
         * switched off silently.
         */
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk();

        $rows = collect($response->json('data'));

        $this->assertSame(8, $rows->count(), 'Four categories across two implemented channels.');

        $security = $rows->firstWhere(fn ($r) => $r['category'] === 'security' && $r['channel'] === 'email');
        $this->assertFalse($security['changeable']);
        $this->assertTrue($security['enabled']);

        $service = $rows->firstWhere(fn ($r) => $r['category'] === 'service' && $r['channel'] === 'email');
        $this->assertTrue($service['changeable']);

        // In-app is never changeable, on any category: the inbox is the
        // account's own record of what happened to it.
        foreach ($rows->where('channel', 'in_app') as $row) {
            $this->assertFalse($row['changeable'], $row['category'].' in-app should not be changeable.');
        }
    }

    #[Test]
    public function a_customer_can_silence_service_email(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences', [
                'category' => 'service',
                'channel' => 'email',
                'enabled' => false,
            ])
            ->assertOk();

        $this->assertFalse(
            NotificationPreference::query()
                ->where('user_id', $this->user->getKey())
                ->where('category', 'service')
                ->sole()
                ->enabled,
        );
    }

    #[Test]
    public function billing_email_is_refused_rather_than_accepted_and_ignored(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences', [
                'category' => 'billing',
                'channel' => 'email',
                'enabled' => false,
            ])
            ->assertStatus(422);

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function the_inbox_cannot_be_silenced_on_any_category(): void
    {
        foreach (['security', 'billing', 'service', 'operational'] as $category) {
            $this->actingAs($this->user)
                ->putJson('/api/v1/me/notification-preferences', [
                    'category' => $category,
                    'channel' => 'in_app',
                    'enabled' => false,
                ])
                ->assertStatus(422);
        }

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    #[Test]
    public function preferences_are_per_person_not_per_account(): void
    {
        // Two people on one account read different mail. A finance contact
        // silencing service email must not silence it for the engineer.
        $other = User::factory()->create();

        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences', [
                'category' => 'service',
                'channel' => 'email',
                'enabled' => false,
            ])
            ->assertOk();

        $response = $this->actingAs($other)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk();

        $service = collect($response->json('data'))
            ->firstWhere(fn ($r) => $r['category'] === 'service' && $r['channel'] === 'email');

        $this->assertTrue($service['enabled']);
    }

    #[Test]
    public function an_unknown_category_is_refused(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/me/notification-preferences', [
                'category' => 'marketing',
                'channel' => 'email',
                'enabled' => false,
            ])
            ->assertStatus(422);
    }
}
