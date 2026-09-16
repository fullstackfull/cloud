<?php

declare(strict_types=1);

namespace Tests\Feature\Naming;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Infrastructure\Models\Rack;
use Lynomia\Modules\Infrastructure\Application\Naming\AuditInfrastructureNaming;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the platform does about a name that predates the standard.
 *
 * ===========================================================================
 * THE RULE THIS TEST DEFENDS
 * ===========================================================================
 *
 * It reports it. It does not rename it, it does not refuse to start, and it
 * does not hide it.
 *
 * Each of those three is a real temptation and each is wrong in its own way.
 * Renaming breaks the orders, audit entries, deployment plans and metric
 * series that point at the old value — a tidy-up that costs the platform its
 * history. Refusing to start turns a naming policy into an outage. Hiding it
 * means the estate never converges, because nobody is told what to converge.
 *
 * So: a legacy value is a WARNING with a next action, and it is still a
 * warning after somebody reads it. What fails is something that is wrong
 * *now* — two rows that are one identity, or a production row carrying a name
 * that cannot resolve.
 */
final class LegacyNamesAreReportedAndNeverQuietlyRewrittenTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<NamingFinding>
     */
    private function audit(bool $production = false): array
    {
        return (new AuditInfrastructureNaming)->execute($production);
    }

    /**
     * @param  list<NamingFinding>  $findings
     * @return list<NamingFinding>
     */
    private function of(array $findings, string $id, ?CheckStatus $status = null): array
    {
        return array_values(array_filter(
            $findings,
            static fn (NamingFinding $f): bool => $f->id === $id && ($status === null || $f->status === $status),
        ));
    }

    #[Test]
    public function a_value_written_before_the_standard_is_a_warning_with_the_canonical_spelling_in_it(): void
    {
        $datacenter = Datacenter::factory()->create(['slug' => 'ref-dc-alpha-1']);

        // The sort of thing a historical estate holds: a rack labelled the way
        // somebody typed it into a spreadsheet.
        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'Rack A_1', 'units' => 42]);

        $warnings = $this->of($this->audit(), 'naming.noncanonical', CheckStatus::Warning);

        $this->assertCount(1, $warnings);
        $this->assertSame('rack.name', $warnings[0]->target);
        $this->assertStringContainsString('Rack A_1', $warnings[0]->summary);
        $this->assertStringContainsString('rack-a-1', $warnings[0]->summary, 'The finding should offer the canonical spelling.');
        $this->assertNotNull($warnings[0]->nextAction);
    }

    #[Test]
    public function auditing_a_legacy_row_leaves_it_exactly_as_it_was(): void
    {
        $datacenter = Datacenter::factory()->create();
        $rack = Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'Rack A_1', 'units' => 42]);

        $before = DB::table('racks')->where('id', $rack->getKey())->first();

        $this->audit();
        $this->audit(production: true);

        $after = DB::table('racks')->where('id', $rack->getKey())->first();

        $this->assertEquals($before, $after, 'The audit must be a read. A rename is somebody\'s decision, not a side effect of looking.');
    }

    #[Test]
    public function a_legacy_row_does_not_stop_the_audit_from_reaching_the_rest_of_the_estate(): void
    {
        $datacenter = Datacenter::factory()->create();
        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'Rack A_1', 'units' => 42]);

        $findings = $this->audit();

        // The suffix and production-row checks still ran and still answered.
        $this->assertNotSame([], $this->of($findings, 'naming.dns_suffix'));
        $this->assertNotSame([], $this->of($findings, 'naming.reference_value_in_production'));
    }

    #[Test]
    public function two_rows_that_normalise_to_one_identity_are_a_failure_rather_than_a_warning(): void
    {
        $datacenter = Datacenter::factory()->create();

        // PostgreSQL's unique index compares literally, so these are two rows
        // to the database and one rack to everybody else. This is the check
        // that catches what the index cannot.
        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'A1', 'units' => 42]);
        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'a1', 'units' => 42]);

        $failures = $this->of($this->audit(), 'naming.collision', CheckStatus::Fail);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('one identity', $failures[0]->summary);
        $this->assertTrue($failures[0]->blocks());
    }

    #[Test]
    public function the_same_code_in_two_datacenters_is_not_a_collision(): void
    {
        // The positive twin. Two datacenters each with rack A1 is how racks are
        // labelled, and a collision check that flagged it would be telling
        // operators to invent prefixes.
        $first = Datacenter::factory()->create(['slug' => 'ref-dc-alpha-1']);
        $second = Datacenter::factory()->create(['slug' => 'ref-dc-alpha-2']);

        Rack::query()->create(['datacenter_id' => $first->getKey(), 'name' => 'A1', 'units' => 42]);
        Rack::query()->create(['datacenter_id' => $second->getKey(), 'name' => 'A1', 'units' => 42]);

        $this->assertSame([], $this->of($this->audit(), 'naming.collision', CheckStatus::Fail));
    }

    #[Test]
    public function a_production_row_carrying_a_reference_value_is_a_failure(): void
    {
        ManagedServer::factory()->create([
            'name' => 'ref-machine-alpha-1-hv-a',
            'environment' => DeploymentEnvironment::Production,
            'management_address' => '192.0.2.21',
        ]);

        $failures = $this->of($this->audit(production: true), 'naming.reference_value_in_production', CheckStatus::Fail);

        $this->assertNotSame([], $failures);
        $this->assertStringContainsString('reference value', $failures[0]->summary);
        $this->assertNotNull($failures[0]->nextAction);
    }

    #[Test]
    public function the_same_row_in_a_non_production_environment_is_not_a_failure(): void
    {
        // The positive twin again: the reference estate is loaded as
        // development, and every one of its names is a reference name. That is
        // what it is for, and the audit must not report the model of an estate
        // as a defect in one.
        ManagedServer::factory()->create([
            'name' => 'ref-machine-alpha-1-hv-a',
            'environment' => DeploymentEnvironment::Development,
            'management_address' => '192.0.2.21',
        ]);

        $this->assertSame([], $this->of($this->audit(), 'naming.reference_value_in_production', CheckStatus::Fail));
    }

    #[Test]
    public function a_production_provider_under_a_reserved_domain_is_a_failure(): void
    {
        ProviderInstance::query()->create([
            'name' => 'live-dns',
            'category' => ProviderCategory::Dns,
            'driver' => 'cloudflare',
            'environment' => DeploymentEnvironment::Production,
            'endpoint' => 'https://api.dns.reference.example',
        ]);

        $failures = $this->of($this->audit(production: true), 'naming.reference_value_in_production', CheckStatus::Fail);

        $this->assertNotSame([], $failures);
        $this->assertStringContainsString('provider_instances.endpoint', $failures[0]->target);
    }

    #[Test]
    public function a_configured_reference_zone_fails_for_production_and_is_accepted_for_the_reference_estate(): void
    {
        config()->set('infrastructure.naming.internal_dns_suffix', 'dc1.reference.example');

        $production = $this->of($this->audit(production: true), 'naming.dns_suffix', CheckStatus::Fail);

        $this->assertCount(1, $production, 'A production deployment must not compose names under a reserved example zone.');
        $this->assertStringContainsString('reserved for documents', $production[0]->summary);
        $this->assertStringContainsString('INFRASTRUCTURE_INTERNAL_DNS_SUFFIX', (string) $production[0]->nextAction);

        $reference = $this->of($this->audit(), 'naming.dns_suffix', CheckStatus::Pass);

        $this->assertCount(1, $reference);
    }

    #[Test]
    public function an_operator_configured_zone_passes_and_the_platform_reports_what_it_composes_under(): void
    {
        config()->set('infrastructure.naming.internal_dns_suffix', 'dc1.operator-chosen-zone.net');

        $findings = $this->of($this->audit(production: true), 'naming.dns_suffix', CheckStatus::Pass);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('dc1.operator-chosen-zone.net', $findings[0]->summary);
    }

    #[Test]
    public function with_no_zone_configured_the_platform_says_so_instead_of_inventing_one(): void
    {
        $this->assertNull(config('infrastructure.naming.internal_dns_suffix'));

        $findings = $this->of($this->audit(), 'naming.dns_suffix', CheckStatus::Pass);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('composes none', $findings[0]->summary);
    }

    #[Test]
    public function a_hostname_under_a_second_zone_is_reported_once_a_zone_is_chosen(): void
    {
        config()->set('infrastructure.naming.internal_dns_suffix', 'dc1.operator-chosen-zone.net');

        DB::table('hosting_nodes')->insert([
            'id' => '01hnamingaudit0000000000a1',
            'datacenter_id' => Datacenter::factory()->create()->getKey(),
            'slug' => 'ref-hosting-alpha-1',
            'hostname' => 'web-01.some-other-zone.example',
            'panel' => 'cpanel',
            'status' => 'active',
            'accepts_new_accounts' => true,
            'panel_licensed' => false,
            'cloudlinux' => false,
            'litespeed' => false,
            'account_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warnings = $this->of($this->audit(), 'naming.one_authority', CheckStatus::Warning);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('web-01.some-other-zone.example', $warnings[0]->summary);
        $this->assertStringContainsString('Two live naming schemes', (string) $warnings[0]->nextAction);
    }

    #[Test]
    public function the_command_exits_zero_for_a_warning_and_one_for_a_failure(): void
    {
        $datacenter = Datacenter::factory()->create();
        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'Rack A_1', 'units' => 42]);

        // A legacy value alone does not fail a pipeline. The alternative is a
        // flag to silence the command, and then the failures are silenced too.
        $this->artisan('infra:naming:audit')
            ->assertExitCode(0)
            ->expectsOutputToContain('[WARN]');

        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'rack-a-1', 'units' => 42]);

        $this->artisan('infra:naming:audit')
            ->assertExitCode(1)
            ->expectsOutputToContain('[FAIL]');
    }

    #[Test]
    public function the_command_emits_machine_readable_findings(): void
    {
        $datacenter = Datacenter::factory()->create();
        Rack::query()->create(['datacenter_id' => $datacenter->getKey(), 'name' => 'Rack A_1', 'units' => 42]);

        $this->artisan('infra:naming:audit --json')->assertExitCode(0);

        // Rendered separately so the assertion is about the shape rather than
        // about the console formatting.
        $findings = (new AuditInfrastructureNaming)->execute(production: false);
        $warning = $this->of($findings, 'naming.noncanonical', CheckStatus::Warning)[0]->toArray();

        $this->assertSame('naming.noncanonical', $warning['id']);
        $this->assertSame('warning', $warning['status']);
        $this->assertSame('rack.name', $warning['concept']);
        $this->assertSame('operator_code', $warning['kind']);
        $this->assertIsString($warning['next_action']);
    }
}
