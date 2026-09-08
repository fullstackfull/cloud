<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Ipam\Application\Actions\ReapExpiredReservations;
use Lynomia\Modules\Ipam\Application\Actions\ReleaseQuarantinedAddresses;

/**
 * Puts addresses back into circulation.
 *
 * Two separate reclamations, both written and neither ever run. An address
 * pool is finite and every path out of it was one-way: a reservation whose
 * build never finished stayed reserved, and an address quarantined because a
 * provisioning call timed out stayed quarantined. A /24 does not last long
 * under that, and the symptom when it runs out is not "the pool is empty" —
 * it is orders failing to place, days later, for reasons nobody connects to
 * builds that failed weeks earlier.
 *
 * They run together because they are the same job from an operator's point of
 * view, and separately inside because they are not the same decision:
 *
 *  - an expired reservation was never given to a machine, so it goes straight
 *    back to the pool;
 *  - a quarantined address may have been configured on a machine that may
 *    exist, so it only comes back after the quarantine period has elapsed —
 *    which is the platform's way of saying "long enough that anything using it
 *    would have been noticed".
 */
final class ReclaimAddresses extends Command
{
    protected $signature = 'ipam:reclaim
        {--reservations=250 : How many expired reservations to reap in one pass}
        {--quarantined=1000 : How many quarantined addresses to consider in one pass}';

    protected $description = 'Return expired reservations and elapsed quarantines to the address pools';

    public function handle(
        ReapExpiredReservations $reap,
        ReleaseQuarantinedAddresses $release,
    ): int {
        $reaped = $reap->execute((int) $this->option('reservations'));
        $released = $release->execute((int) $this->option('quarantined'));

        $this->line((string) json_encode([
            'command' => 'ipam:reclaim',
            'reservations_reaped' => count($reaped),
            'quarantined_released' => $released,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
