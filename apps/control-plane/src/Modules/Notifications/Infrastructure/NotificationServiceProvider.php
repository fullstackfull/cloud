<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Lynomia\Modules\Notifications\Domain\Contracts\TransactionalEmailProvider;
use Lynomia\Modules\Notifications\Infrastructure\Channels\EmailChannel;
use Lynomia\Modules\Notifications\Infrastructure\Channels\InAppChannel;
use Lynomia\Modules\Notifications\Infrastructure\Providers\LaravelMailTransport;
use Lynomia\Modules\Notifications\Infrastructure\Registries\NotificationChannelRegistry;

/**
 * Where a notification channel becomes reachable.
 *
 * The registry is a singleton because a channel is stateless and building the
 * mail client twice per request is waste, and because the delivery job resolves
 * it once per attempt.
 *
 * Every channel the enum declares as implemented must be registered here.
 * `NotificationChannelsAreRegisteredTest` asserts exactly that — the lesson
 * Phase 29 paid for, where five provisioning handlers were written, tested and
 * unregistered, and every one of them presented to a customer as a working
 * button.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TransactionalEmailProvider::class, LaravelMailTransport::class);

        $this->app->singleton(NotificationChannelRegistry::class, function ($app): NotificationChannelRegistry {
            $registry = new NotificationChannelRegistry;

            $registry->register(new InAppChannel);
            $registry->register($app->make(EmailChannel::class));

            return $registry;
        });
    }
}
