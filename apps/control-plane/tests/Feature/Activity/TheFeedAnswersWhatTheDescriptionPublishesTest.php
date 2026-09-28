<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/v1/activity` answers with the envelope `docs/openapi.yaml`
 * publishes for it.
 *
 * The feed is cursor-paginated and its `meta` is `next_cursor` and
 * `per_page`. The description used to publish it with the numbered-page
 * envelope every other paged list uses (`PaginationMeta`: page, per_page,
 * total, last_page, max_per_page) and the `page` parameter, and nothing
 * compared the two: a client generated from the description would have read
 * a total and a last page that never arrive, and asked for pages the feed
 * ignores.
 *
 * What it compares: the keys of `meta` in two real responses (one with a
 * next cursor, one without) against the property names of the schema the
 * operation's 200 response references for `meta`, both ways, and the
 * schema's `required` list against the keys; the envelope's own keys against
 * the page schema's properties; and that the operation publishes no `page`
 * parameter. It reads the committed `docs/openapi.yaml` by its generated
 * layout (two-space indentation, as `YamlEmitter` writes it), not with a YAML
 * parser, and fails by name if the layout stops matching. It compares names,
 * not types. It also holds, by request, that a `per_page` of 100 is served
 * and one of 101 or 0 refused with `validation.failed`, as the published
 * parameter says.
 */
final class TheFeedAnswersWhatTheDescriptionPublishesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_meta_of_every_page_is_the_meta_the_description_publishes(): void
    {
        $user = $this->accountWithTwoEvents();

        $first = $this->actingAs($user)->getJson('/api/v1/activity?per_page=1')->assertOk();
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor, 'Two events at one per page leave a next page.');

        $last = $this->actingAs($user)->getJson('/api/v1/activity?per_page=1&cursor='.urlencode($cursor))->assertOk();
        $this->assertNull($last->json('meta.next_cursor'), 'The second of two events is the last page.');

        [$envelope, $meta] = self::published('/api/v1/activity');

        foreach (['with a next page' => $first->json(), 'on the last page' => $last->json()] as $which => $body) {
            $this->assertIsArray($body);
            $this->assertEqualsCanonicalizing($envelope['properties'], array_keys($body), "The envelope {$which} is not the one published.");
            $this->assertIsArray($body['meta']);
            $this->assertEqualsCanonicalizing(
                $meta['properties'],
                array_keys($body['meta']),
                "The feed's meta {$which} has the keys ".implode(', ', array_keys($body['meta']))
                ."; {$meta['name']}, which the description publishes for it, declares ".implode(', ', $meta['properties']).'.',
            );
            $this->assertSame([], array_diff($meta['required'], array_keys($body['meta'])), "{$meta['name']} requires a key the feed's meta {$which} does not carry.");
        }
    }

    /**
     * The description's `cursorPerPage` and docs/api.md say a `per_page`
     * outside 1 to 100 is refused rather than clamped.
     */
    #[Test]
    public function a_per_page_outside_one_to_a_hundred_is_refused_not_clamped(): void
    {
        $user = $this->accountWithTwoEvents();

        $this->actingAs($user)->getJson('/api/v1/activity?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);

        foreach ([101, 0] as $perPage) {
            $this->actingAs($user)->getJson('/api/v1/activity?per_page='.$perPage)
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation.failed');
        }
    }

    #[Test]
    public function the_description_publishes_no_page_number_for_the_feed(): void
    {
        $this->assertStringNotContainsString(
            '#/components/parameters/page"',
            implode("\n", self::operationBlock('/api/v1/activity')),
            'The feed is walked by cursor; a page number is ignored, so publishing one is a parameter that does nothing.',
        );
    }

    /**
     * The page schema the operation's 200 response references, and the
     * schema its `meta` property references.
     *
     * @return array{0: array{name: string, properties: list<string>, required: list<string>}, 1: array{name: string, properties: list<string>, required: list<string>}}
     */
    private static function published(string $path): array
    {
        $operation = self::operationBlock($path);
        $pageName = null;
        $inOk = false;

        foreach ($operation as $line) {
            if (preg_match('/^        "?200"?:\s*$/', $line) === 1) {
                $inOk = true;

                continue;
            }

            if ($inOk && preg_match('/"\$ref":\s*"#\/components\/schemas\/([A-Za-z0-9_]+)"/', $line, $m) === 1) {
                $pageName = $m[1];

                break;
            }
        }

        self::assertNotNull($pageName, "The scan found no schema for the 200 response of GET {$path}.");

        $page = self::schema($pageName);
        self::assertArrayHasKey('meta', $page['refs'], "{$pageName} has no meta property that references a schema.");

        return [$page, self::schema($page['refs']['meta'])];
    }

    /**
     * @return list<string> the lines of `paths.<path>.get`
     */
    private static function operationBlock(string $path): array
    {
        $lines = self::description();
        $block = [];
        $inPath = false;
        $inGet = false;

        foreach ($lines as $line) {
            if (preg_match('/^  "?(\/[^":]+)"?:\s*$/', $line, $m) === 1) {
                if ($inPath) {
                    break;
                }

                $inPath = $m[1] === $path;

                continue;
            }

            if (! $inPath) {
                continue;
            }

            if (preg_match('/^    ([a-z]+):\s*$/', $line, $m) === 1) {
                $inGet = $m[1] === 'get';

                continue;
            }

            if ($inGet) {
                $block[] = $line;
            }
        }

        self::assertNotSame([], $block, "The scan found no GET {$path} in docs/openapi.yaml.");

        return $block;
    }

    /**
     * @return array{name: string, properties: list<string>, required: list<string>, refs: array<string, string>}
     */
    private static function schema(string $name): array
    {
        $inSchemas = false;
        $inSchema = false;
        $section = null;
        $property = null;
        $out = ['name' => $name, 'properties' => [], 'required' => [], 'refs' => []];

        foreach (self::description() as $line) {
            if (rtrim($line) === '  schemas:') {
                $inSchemas = true;

                continue;
            }

            if (! $inSchemas) {
                continue;
            }

            if (preg_match('/^    "?([A-Za-z0-9_]+)"?:\s*$/', $line, $m) === 1) {
                if ($inSchema) {
                    break;
                }

                $inSchema = $m[1] === $name;

                continue;
            }

            if (! $inSchema) {
                continue;
            }

            if (preg_match('/^      ([a-zA-Z]+):/', $line, $m) === 1) {
                $section = $m[1];

                continue;
            }

            if ($section === 'required' && preg_match('/^        - "?([A-Za-z0-9_]+)"?\s*$/', $line, $m) === 1) {
                $out['required'][] = $m[1];
            }

            if ($section === 'properties' && preg_match('/^        "?([A-Za-z0-9_]+)"?:/', $line, $m) === 1) {
                $property = $m[1];
                $out['properties'][] = $property;

                continue;
            }

            if ($section === 'properties' && $property !== null
                && preg_match('/^          "\$ref":\s*"#\/components\/schemas\/([A-Za-z0-9_]+)"/', $line, $m) === 1) {
                $out['refs'][$property] = $m[1];
            }
        }

        self::assertNotSame([], $out['properties'], "The scan found no properties for the {$name} schema in docs/openapi.yaml.");

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function description(): array
    {
        $path = base_path('../../docs/openapi.yaml');
        self::assertFileExists($path);

        return explode("\n", (string) file_get_contents($path));
    }

    private function accountWithTwoEvents(): User
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);

        $service = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps', 'status' => ServiceStatus::Active]);
        $node = ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->id]);
        $machine = VirtualMachine::factory()->onNode($node)->forService($service)->create(['hostname' => 'feed-1']);

        for ($i = 0; $i < 2; $i++) {
            ProvisioningJob::factory()->create([
                'customer_id' => $customer->id,
                'service_id' => $machine->service_id,
                'kind' => ProvisioningJobKind::Restart,
                'status' => ProvisioningJobStatus::Succeeded,
                'idempotency_key' => 'feed-restart:'.$i,
                'created_at' => now()->subMinutes(2 - $i),
            ]);
        }

        return $user;
    }
}
