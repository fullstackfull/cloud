<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Domain\Enums\ServerState;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

/**
 * The control centre as numbers: how many machines are in each
 * classification, how many providers are enabled and no longer ready, how
 * many credentials are missing, how many licences are lapsing, where each
 * product stands, and — the one that pages — how many deployments are
 * sitting there waiting for a person.
 *
 * Six queries, every combination published at zero, and no label that names
 * a machine or a person: the series are counts by state, and the screen is
 * where the names are.
 */
final readonly class ControlCenterCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'control_center';
    }

    public function collect(): array
    {
        $counts = $this->counts();

        return [
            $this->machines($counts['servers']),
            ...$this->providers($counts['providers']),
            $this->byState('lynomia_credentials', CredentialState::cases(), $counts['credentials'], 'Credential references by state. A missing one is a reference the deployment controller has no value behind; a revoked one is a reference nothing may try again.'),
            $this->byState('lynomia_licences', LicenceState::cases(), $counts['licences'], 'Licences by state, as the calendar decides them. Expiring means within thirty days.'),
            $this->products($counts['products']),
            $this->deployments($counts['deployments']),
        ];
    }

    /**
     * Every count this collector needs, in one round trip.
     *
     * Six small group-bys unioned rather than six queries: the metrics
     * endpoint has a query budget that the rest of the platform had already
     * spent, and the control centre is not the reason to widen it by six.
     *
     * @return array<string, array<string, int>>
     */
    private function counts(): array
    {
        $rows = DB::select(<<<'SQL'
            select 'servers' as source, safety_class || '|' || state as key, count(*) as total from managed_servers group by safety_class, state
            union all
            select 'providers', category || '|' || state || '|' || readiness, count(*) from provider_instances group by category, state, readiness
            union all
            select 'credentials', state, count(*) from credential_references group by state
            union all
            select 'licences', state, count(*) from licences group by state
            union all
            select 'products', product || '|' || state, count(*) from product_readiness group by product, state
            union all
            select 'deployments', state || '|' || kind, count(*) from deployment_jobs group by state, kind
        SQL);

        $counts = ['servers' => [], 'providers' => [], 'credentials' => [], 'licences' => [], 'products' => [], 'deployments' => []];

        foreach ($rows as $row) {
            $counts[(string) $row->source][(string) $row->key] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function machines(array $counts): Metric
    {
        $samples = [];

        foreach (SafetyClass::cases() as $class) {
            foreach (ServerState::cases() as $state) {
                $samples[] = MetricSample::of(['classification' => $class->value, 'state' => $state->value], $counts[$class->value.'|'.$state->value] ?? 0);
            }
        }

        return Metric::gauge('lynomia_managed_servers', 'Machines by safety classification and lifecycle state. A machine arrives do_not_touch and stays so until somebody decides.', $samples);
    }

    /**
     * @param  array<string, int>  $counts  category|state|readiness => total
     * @return list<Metric>
     */
    private function providers(array $counts): array
    {
        $byCategoryReadiness = [];
        $byState = [];
        $enabledNotReady = 0;

        foreach ($counts as $key => $total) {
            [$category, $state, $readiness] = explode('|', $key, 3);
            $byCategoryReadiness[$category.'|'.$readiness] = ($byCategoryReadiness[$category.'|'.$readiness] ?? 0) + $total;
            $byState[$state] = ($byState[$state] ?? 0) + $total;

            if ($state === ProviderState::Enabled->value && $readiness !== ReadinessState::ReadyForProduction->value) {
                $enabledNotReady += $total;
            }
        }

        $readiness = [];

        foreach (ProviderCategory::cases() as $category) {
            foreach (ReadinessState::cases() as $state) {
                $readiness[] = MetricSample::of(['category' => $category->value, 'readiness' => $state->value], $byCategoryReadiness[$category->value.'|'.$state->value] ?? 0);
            }
        }

        $states = [];

        foreach (ProviderState::cases() as $state) {
            $states[] = MetricSample::of(['state' => $state->value], $byState[$state->value] ?? 0);
        }

        return [
            Metric::gauge('lynomia_provider_readiness', 'Providers by category and readiness. ready_for_production means nothing in the control plane is stopping live use; it is not proof anything works.', $readiness),
            Metric::gauge('lynomia_provider_state', 'Providers by the state an operator put them in.', $states),
            Metric::gauge('lynomia_providers_enabled_not_ready', 'Providers an operator enabled that are no longer ready: a credential revoked, a licence lapsed, an endpoint that stopped answering. Nothing switches them off automatically, so this is the number that needs a person.', [MetricSample::of([], $enabledNotReady)]),
        ];
    }

    /**
     * @param  list<\BackedEnum>  $states
     * @param  array<string, int>  $counts
     */
    private function byState(string $metric, array $states, array $counts, string $help): Metric
    {
        $samples = [];

        foreach ($states as $state) {
            $samples[] = MetricSample::of(['state' => (string) $state->value], (int) ($counts[$state->value] ?? 0));
        }

        return Metric::gauge($metric, $help, $samples);
    }

    /**
     * @param  array<string, int>  $counts  product|state => total (one row per product)
     */
    private function products(array $counts): Metric
    {
        $samples = [];

        foreach (Product::cases() as $product) {
            $assessed = array_any(array_keys($counts), static fn (string $key): bool => str_starts_with($key, $product->value.'|'));

            foreach (ProductReadinessState::cases() as $state) {
                // A product never assessed is not_ready, which is also what
                // the first assessment would say.
                $on = $assessed
                    ? ($counts[$product->value.'|'.$state->value] ?? 0) > 0
                    : $state === ProductReadinessState::NotReady;

                $samples[] = MetricSample::of(['product' => $product->value, 'state' => $state->value], $on ? 1 : 0);
            }
        }

        return Metric::gauge('lynomia_product_readiness', 'One per product: which rung it is on. ready_to_sell is a person\'s declaration and is withdrawn automatically when the evidence goes.', $samples);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function deployments(array $counts): Metric
    {
        $samples = [];

        foreach (DeploymentState::cases() as $state) {
            foreach (DeploymentKind::cases() as $kind) {
                $samples[] = MetricSample::of(['state' => $state->value, 'kind' => $kind->value], $counts[$state->value.'|'.$kind->value] ?? 0);
            }
        }

        return Metric::gauge('lynomia_deployments', 'Deployment runs by state and kind. indeterminate and needs_review wait for a person and are never retried; the alert on them is the one this collector exists for.', $samples);
    }
}
