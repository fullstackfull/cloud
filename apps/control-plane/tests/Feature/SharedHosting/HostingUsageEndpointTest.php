<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Lynomia\Modules\SharedHosting\Http\Resources\HostingAccountUsageResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/hosting/{account}/usage.
 *
 * Two things are being protected here, and neither of them is the arithmetic.
 *
 * A figure the panel never reported must arrive as null, not as zero: the sync
 * refuses to write an empty answer precisely so that a customer at 95% of
 * quota is never recorded at 0%, and rendering the absence as a zero here
 * would undo that at the last possible moment.
 *
 * And every figure must carry the moment it was measured, loudly enough that a
 * stale reading is visibly stale. Nothing in this endpoint calls the node — a
 * per-request call would be a customer-triggered load on the machine their
 * neighbours' sites run on — so the honest thing to publish is a reading and
 * its age.
 */
final class HostingUsageEndpointTest extends HostingApiTestCase
{
    #[Test]
    public function it_reports_usage_against_the_package_quota(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);
        $account->forceFill([
            'disk_used_mib' => 5_120,
            'bandwidth_used_mib' => 128_000,
            'usage_synced_at' => now()->subMinutes(10),
        ])->save();

        $body = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.account_id', $account->id)
            ->assertJsonPath('data.username', $account->username)
            ->assertJsonPath('data.disk.used_mib', 5_120)
            ->assertJsonPath('data.disk.quota_mib', 10_240)
            ->assertJsonPath('data.bandwidth.used_mib', 128_000)
            ->assertJsonPath('data.bandwidth.quota_mib', 512_000)
            ->assertJsonPath('meta.never_measured', false)
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.source', 'last_panel_sync')
            ->json();

        // Compared as numbers rather than through assertJsonPath: JSON does
        // not distinguish 50 from 50.0, and a test that depended on which one
        // the encoder produced would be testing the encoder.
        $this->assertEqualsWithDelta(50.0, $body['data']['disk']['used_percent'], 0.01);
        $this->assertEqualsWithDelta(25.0, $body['data']['bandwidth']['used_percent'], 0.01);
    }

    #[Test]
    public function it_says_when_the_figures_were_measured(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);
        $measuredAt = now()->subMinutes(30);
        $account->forceFill(['disk_used_mib' => 1_024, 'usage_synced_at' => $measuredAt])->save();

        $body = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.measured_at', $measuredAt->toImmutable()->toIso8601String())
            ->json();

        $this->assertGreaterThanOrEqual(1_800, $body['meta']['age_seconds']);
    }

    #[Test]
    public function a_reading_older_than_the_staleness_window_is_marked_stale(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);
        $account->forceFill([
            'disk_used_mib' => 9_000,
            'usage_synced_at' => now()->subSeconds(HostingAccountUsageResource::STALE_AFTER_SECONDS + 60),
        ])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            // The number is still reported — it is the customer's last known
            // usage — but nothing about the response lets a client present it
            // as current.
            ->assertJsonPath('data.disk.used_mib', 9_000)
            ->assertJsonPath('meta.stale', true)
            ->assertJsonPath('meta.never_measured', false)
            ->assertJsonPath('meta.stale_after_seconds', HostingAccountUsageResource::STALE_AFTER_SECONDS);
    }

    #[Test]
    public function an_account_that_has_never_been_measured_reports_null_and_not_zero(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.disk.used_mib', null)
            ->assertJsonPath('data.disk.used_percent', null)
            ->assertJsonPath('data.bandwidth.used_mib', null)
            ->assertJsonPath('data.measured_at', null)
            ->assertJsonPath('meta.never_measured', true)
            // Never measured is not stale: there is no figure to have aged.
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.age_seconds', null)
            ->json();

        // Zero would be indistinguishable from "uses nothing", which is what
        // quota enforcement and an overage bill are computed from.
        $this->assertNotSame(0, $body['data']['disk']['used_mib']);
        $this->assertNotSame(0, $body['data']['bandwidth']['used_mib']);
    }

    #[Test]
    public function an_account_with_no_package_reports_the_quota_as_unknown(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);
        $account->forceFill(['hosting_package_id' => null, 'disk_used_mib' => 2_048])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.disk.used_mib', 2_048)
            ->assertJsonPath('data.disk.quota_mib', null)
            // No quota means no percentage. A progress bar drawn against an
            // unknown denominator is a fabrication.
            ->assertJsonPath('data.disk.used_percent', null);
    }

    #[Test]
    public function another_customers_usage_is_not_found(): void
    {
        [, $user] = $this->accountWithOwner();
        [$neighbour] = $this->accountWithOwner();
        $theirs = $this->hostingAccountFor($neighbour, username: 'neighbour4', atPanel: false);
        $theirs->forceFill(['disk_used_mib' => 7_777, 'usage_synced_at' => now()])->save();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$theirs->id.'/usage')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource.not_found');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('7777', $body);
        $this->assertStringNotContainsString($theirs->username, $body);
    }

    #[Test]
    public function it_carries_nothing_about_the_node(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);
        $account->forceFill(['disk_used_mib' => 100, 'usage_synced_at' => now()])->save();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk();

        $response
            ->assertJsonMissingPath('data.hosting_node_id')
            ->assertJsonMissingPath('data.node')
            ->assertJsonMissingPath('data.customer_id')
            ->assertJsonMissingPath('meta.node');

        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString($this->node()->hostname, $body);
        $this->assertStringNotContainsString((string) $this->node()->credentials_reference, $body);
        $this->assertStringNotContainsString($this->node()->id, $body);
    }

    /*
     * ---------------------------------------------------------------------
     * "Unlimited" is a promise, and the platform may only make it once
     * ---------------------------------------------------------------------
     *
     * The same distinction the used_mib figures are so careful about — a
     * number the panel never reported is null, never 0 — applies to the
     * denominator. A quota of null means one of two entirely different
     * things, and only one of them is "unlimited": a package that promises
     * no ceiling, and an account whose package the platform cannot see at
     * all. Collapsing them tells a customer their unknown quota is infinite,
     * which is the single most expensive thing a hosting panel can be wrong
     * about in the customer's favour.
     */

    #[Test]
    public function an_account_with_no_package_is_not_told_its_quota_is_unlimited(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $account = $this->hostingAccountFor($customer, atPanel: false);
        $account->forceFill(['hosting_package_id' => null, 'disk_used_mib' => 2_048])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.disk.quota_mib', null)
            // Null, not false and certainly not true: there is no package to
            // read a ceiling from, so the platform has no answer to give.
            ->assertJsonPath('data.disk.unlimited', null)
            ->assertJsonPath('data.bandwidth.unlimited', null);
    }

    #[Test]
    public function a_package_that_really_promises_no_ceiling_still_says_so(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $unmetered = HostingPackage::factory()->create([
            'slug' => 'hosting-unmetered',
            'panel_package_name' => 'lyn_unmetered_internal',
            'disk_quota_mib' => 10_240,
            'bandwidth_quota_mib' => null,
        ]);

        $account = $this->hostingAccountFor($customer, atPanel: false);
        $account->forceFill([
            'hosting_package_id' => $unmetered->id,
            'bandwidth_used_mib' => 900_000,
        ])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/hosting/'.$account->id.'/usage')
            ->assertOk()
            ->assertJsonPath('data.bandwidth.unlimited', true)
            ->assertJsonPath('data.bandwidth.quota_mib', null)
            ->assertJsonPath('data.bandwidth.used_percent', null)
            // The other half of the same package is metered, and says so.
            ->assertJsonPath('data.disk.unlimited', false);
    }
}
