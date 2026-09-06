<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The base seeder runs only what is safe and necessary in every environment:
 * permissions and roles. Sample accounts, the catalogue and the inventory live
 * behind DevelopmentSeeder and never run in production.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);

        if (app()->environment('local', 'development')) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
