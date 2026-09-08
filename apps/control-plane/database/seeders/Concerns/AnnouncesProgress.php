<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * Says what a seeder did, when there is a console listening.
 *
 * Seeding is one of the few things in this repository a person watches happen,
 * and "3 products, 9 plans, 32 prices" is what tells them it did what they
 * meant rather than what the last edit left behind.
 */
trait AnnouncesProgress
{
    /**
     * Laravel annotates Seeder::$command as a non-nullable Command and never
     * initialises it, so it is null for a seeder invoked from application code
     * rather than from `db:seed` — which is why the framework's own code
     * guards every use of it with isset(). The guard here is the same
     * condition stated in terms of the thing that decides it, which is also
     * the only case where there is anything to print to.
     */
    protected function announce(string $message): void
    {
        if (! app()->runningInConsole()) {
            return;
        }

        $this->command->info($message);
    }
}
