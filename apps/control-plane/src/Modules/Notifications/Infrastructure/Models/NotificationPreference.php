<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;

/**
 * A person's choice to stop receiving one category on one channel.
 *
 * Rows exist only where somebody has turned something off. The absence of a
 * row means enabled, which keeps a new category working for every existing
 * account without a backfill — and means a customer who never opened the
 * settings page is not silently missing messages because a default was
 * written wrong.
 *
 * @property string $id
 * @property string $user_id
 * @property NotificationCategory $category
 * @property NotificationChannel $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }
}
