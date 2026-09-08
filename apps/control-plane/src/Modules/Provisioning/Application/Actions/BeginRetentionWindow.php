<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Starts the clock a service's data is kept against, and writes the date down.
 *
 * The date is stored rather than computed from `suspended_at` plus a number in
 * configuration. Both give the same answer today; only one of them gives the
 * same answer the morning after somebody changes the number — and the customer
 * has already been told a date. A promise that moves when a config file
 * changes is not a promise.
 *
 * Set once, like `cancel_at`. A service that is suspended, reactivated and
 * suspended again is a different question from one whose window is being
 * quietly extended by a retry, and the first request is the one the customer
 * was told about.
 */
final readonly class BeginRetentionWindow
{
    /** Why a service stopped serving. Recorded, because it decides what the sweep may do. */
    public const string BY_CUSTOMER = 'customer_cancelled';

    public const string BY_NON_PAYMENT = 'non_payment';

    public const string BY_OPERATOR = 'operator';

    public function execute(Service $service, string $reason, ?DateTimeImmutable $at = null): Service
    {
        $now = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $days = max(0, (int) config('provisioning.termination.suspended_retention_days', 30));

        return DB::transaction(function () use ($service, $reason, $now, $days): Service {
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->getKey());

            $locked->forceFill([
                'retention_ends_at' => $locked->retention_ends_at ?? $now->addDays($days),
                'ended_reason' => $locked->ended_reason ?? $reason,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Called off, for a customer who paid or changed their mind.
     *
     * The warning stamp is cleared with it: a service that goes back into this
     * state months later needs telling again, and a customer who was warned in
     * March about data that was never destroyed is owed the warning in
     * September.
     */
    public function cancel(Service $service): Service
    {
        return DB::transaction(function () use ($service): Service {
            /** @var Service $locked */
            $locked = Service::query()->lockForUpdate()->findOrFail($service->getKey());

            $locked->forceFill([
                'retention_ends_at' => null,
                'retention_warned_at' => null,
                'ended_reason' => null,
            ])->save();

            return $locked->refresh();
        });
    }
}
