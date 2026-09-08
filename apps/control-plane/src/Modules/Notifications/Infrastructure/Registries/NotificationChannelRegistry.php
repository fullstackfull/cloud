<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Registries;

use Lynomia\Modules\Notifications\Domain\Contracts\NotificationDeliveryChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use RuntimeException;

/**
 * Which class delivers which channel.
 *
 * The same shape as the provisioning handler registry, and for the same reason
 * Phase 29 learned the hard way: a channel that is declared and not registered
 * must fail loudly at the point of use rather than quietly do nothing. There is
 * a test that every implemented channel resolves.
 */
final class NotificationChannelRegistry
{
    /** @var array<string, NotificationDeliveryChannel> */
    private array $channels = [];

    public function register(NotificationDeliveryChannel $channel): void
    {
        $this->channels[$channel->channel()->value] = $channel;
    }

    public function for(NotificationChannel $channel): NotificationDeliveryChannel
    {
        return $this->channels[$channel->value]
            ?? throw new RuntimeException(sprintf(
                'No delivery channel is registered for %s. Declared channels must be registered or removed.',
                $channel->value,
            ));
    }

    public function has(NotificationChannel $channel): bool
    {
        return isset($this->channels[$channel->value]);
    }
}
