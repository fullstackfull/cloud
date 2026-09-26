<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F-46 deleted the two "operational" notification types — an incident, and
 * planned maintenance — because nothing in the platform could raise either,
 * and the category held nothing else. A category is a switch on the
 * preferences screen, so it went with them; these pin the two consequences.
 */
final class ASwitchForNothingIsNotOfferedTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = 'forget_email_choices_about_a_category_nothing_sends';

    #[Test]
    public function the_preferences_screen_offers_only_categories_some_notification_belongs_to(): void
    {
        $user = User::factory()->create();

        $offered = collect($this->actingAs($user)->getJson('/api/v1/me/notification-preferences')->assertOk()->json('data'))
            ->pluck('category')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $sent = collect(NotificationType::cases())
            ->map(static fn (NotificationType $type): string => $type->category()->value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame($sent, $offered);
        $this->assertNotContains('operational', $offered);
    }

    #[Test]
    public function a_stored_choice_about_the_retired_category_is_forgotten_and_nothing_else_is(): void
    {
        $user = User::factory()->create();

        /*
         * Written the way the screen used to allow: somebody switched off the
         * maintenance emails, and separately the service emails.
         */
        foreach (['operational', 'service'] as $category) {
            DB::table('notification_preferences')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'category' => $category,
                'channel' => 'email',
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->migration()->up();

        $this->assertSame(
            ['service'],
            DB::table('notification_preferences')->where('user_id', $user->id)->pluck('category')->all(),
        );

        // A row naming a case that no longer exists cannot be read back at
        // all; with it gone, the screen answers, and the choice that still
        // means something is still honoured.
        $service = collect($this->actingAs($user)->getJson('/api/v1/me/notification-preferences')->assertOk()->json('data'))
            ->first(static fn (array $row): bool => $row['category'] === 'service' && $row['channel'] === 'email');

        $this->assertFalse($service['enabled']);
    }

    private function migration(): Migration
    {
        $files = glob(database_path('migrations/*_'.self::MIGRATION.'.php')) ?: [];

        $this->assertCount(1, $files, 'Exactly one migration should be named '.self::MIGRATION.'.');

        return require $files[0];
    }
}
