<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Dedicated\Application\Actions\ExpireAbandonedPowerClaims as Expire;

/**
 * Settles dedicated power claims whose worker died before the controller
 * answered. Every one becomes indeterminate; nothing is sent to a chassis.
 */
final class ExpireAbandonedPowerClaims extends Command
{
    protected $signature = 'dedicated:expire-abandoned-power-claims';

    protected $description = 'Settle dedicated power claims that outlived their lease as indeterminate, never re-sending them';

    public function handle(Expire $expire): int
    {
        $outcome = $expire->execute();

        $this->line((string) json_encode([
            'command' => 'dedicated:expire-abandoned-power-claims',
            'examined' => $outcome['examined'],
            'settled' => $outcome['settled'],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
