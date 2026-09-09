<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * The estate on one screen: counts by state for every concern, and the
 * short list of what needs a person. Reads only; contacts nothing.
 */
final class OverviewController
{
    public function index(): JsonResponse
    {
        $group = static fn (string $table, string $column): array => DB::table($table)
            ->selectRaw($column.' as key, count(*) as total')
            ->groupBy($column)
            ->pluck('total', 'key')
            ->map(static fn ($n): int => (int) $n)
            ->all();

        $providers = DB::table('provider_instances')->selectRaw('state, readiness, count(*) as total')->groupBy('state', 'readiness')->get();
        $enabledNotReady = 0;
        $byReadiness = [];
        $byProviderState = [];

        foreach ($providers as $row) {
            $byReadiness[$row->readiness] = ($byReadiness[$row->readiness] ?? 0) + (int) $row->total;
            $byProviderState[$row->state] = ($byProviderState[$row->state] ?? 0) + (int) $row->total;

            if ($row->state === ProviderState::Enabled->value && $row->readiness !== ReadinessState::ReadyForProduction->value) {
                $enabledNotReady += (int) $row->total;
            }
        }

        $deployments = $group('deployment_jobs', 'state');
        $credentials = $group('credential_references', 'state');
        $licences = $group('licences', 'state');

        $waiting = ($deployments[DeploymentState::Indeterminate->value] ?? 0) + ($deployments[DeploymentState::NeedsReview->value] ?? 0);

        $drift = (int) DB::table('resource_drifts')
            ->where('provider', 'infrastructure')
            ->whereIn('status', [DriftStatus::Open->value, DriftStatus::Acknowledged->value])
            ->count();

        return response()->json(['data' => [
            'machines' => [
                'total' => (int) DB::table('managed_servers')->count(),
                'by_classification' => $group('managed_servers', 'safety_class'),
                'by_state' => $group('managed_servers', 'state'),
            ],
            'providers' => [
                'total' => array_sum($byProviderState),
                'by_state' => $byProviderState,
                'by_readiness' => $byReadiness,
            ],
            'credentials' => ['by_state' => $credentials],
            'licences' => ['by_state' => $licences],
            'products' => ['by_state' => $group('product_readiness', 'state')],
            'deployments' => ['by_state' => $deployments],
            'sites' => [
                'datacenters' => (int) DB::table('datacenters')->count(),
                'racks' => (int) DB::table('racks')->count(),
            ],
            // What needs a person, as counts. Each is a link on the screen.
            'attention' => [
                'deployments_waiting' => $waiting,
                'providers_enabled_not_ready' => $enabledNotReady,
                'credentials_missing' => $credentials['missing'] ?? 0,
                'licences_expiring' => ($licences['expiring'] ?? 0) + ($licences['expired'] ?? 0),
                'infrastructure_drift_open' => $drift,
                'machines_never_classified' => (int) DB::table('managed_servers')->whereNull('safety_changed_at')->count(),
            ],
        ]]);
    }
}
