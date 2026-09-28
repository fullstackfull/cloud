<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Console\Commands\GenerateOpenApiSpec;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\E2ESeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SoftwareCatalogueSeeder;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Schema;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Domains\Infrastructure\Models\Domain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentPlan;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\WordPressSite;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
use Lynomia\Support\YamlEmitter;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use stdClass;
use Tests\Support\PublishedSchema;
use Tests\TestCase;

/**
 * What the API sends is the shape `docs/openapi.yaml` publishes for it.
 *
 * `TheClientAndTheDescriptionAgreeTest` compares property names, and
 * nothing compared types, so fields were published as one type and sent as
 * another: a subscription's `service_is_running` (published a string, sent
 * a boolean), an operator's dedicated server's `rack_unit` (a string, sent
 * an integer), a hosting node's `load_average` (a string, sent a number),
 * and a plan option's `credit`, `charge` and `amount_due_now` (a Money, sent
 * null for a plan the platform would refuse). Its first run found more: a
 * machine's controller, desired state and current plan published as an
 * object and sent as `data: null` when there is none, a console session id
 * sent in upper case against the lower-case `Ulid` pattern, and a 422 from
 * the domain search and the push impact that neither operation published.
 * The readiness list's `dependencies`, reported as `[]` by a probe that
 * decoded bodies into PHP arrays, is `{}` on the wire; decoded here with
 * objects kept, an empty list where an object is published fails.
 *
 * What it does: seeds the development world and the fixtures the browser
 * specs read (`RolePermissionSeeder`, `SoftwareCatalogueSeeder`,
 * `DevelopmentSeeder` and `E2ESeeder`, in that order),
 * then sends a GET to every `api/` route that has one
 * and whose path parameters it can fill (`parameterFor()` says how; the
 * routes it cannot fill are listed in `UNREACHED` and asserted to be
 * exactly those), as the seeded customer on `api/v1` and as the seeded
 * administrator on `api/admin`. Every response whose status the operation
 * publishes with a JSON schema is checked against that schema with
 * {@see PublishedSchema}, a structural checker whose docblock states which
 * keywords it implements; a response with a status the operation does not
 * publish is a failure, and so is any 5xx. Page operations are also asked
 * for `?per_page=1&page=2`, and `queriesFor()` names the other queries sent.
 *
 * What it reads as the description: the array `openapi:generate` serialises
 * (`GenerateOpenApiSpec::description()`), after asserting that its
 * serialisation is the committed file byte for byte.
 *
 * What it does not do: it sees what the seeded world contains, so a field
 * that is null in every seeded row is checked only as null, and a branch no
 * seeded row reaches is not checked. `MUST` names the operations that must
 * have been checked, with a body, for the test to pass: the ones whose
 * published schema was wrong, and every GET whose envelope is `list`.
 */
final class EveryResponseIsTheShapeItsDescriptionPublishesTest extends TestCase
{
    use RefreshDatabase;

    /** GET routes whose path parameters this test does not fill. */
    private const array UNREACHED = [
        'api.admin.infrastructure.deployments.show' => 'the seeders record no deployment job',
        'api.admin.infrastructure.plans.show' => 'the seeders record no deployment plan',
        'api.admin.support.attachments.download' => 'answers a file, not JSON',
        'api.v1.backups.downloads.show' => 'needs a signed single-use token',
        'api.v1.fake_gateway.show' => 'the payment simulator\'s page, not an API response',
        'api.v1.invitations.show' => 'needs an invitation token the seeders do not mint',
        'api.v1.operations.show' => 'the seeded customer has no operation the list shows',
        'api.v1.orders.show' => 'the seeded customer has no order',
        'api.v1.payments.show' => 'the seeded customer has no payment',
        'api.v1.support.attachments.download' => 'answers a file, not JSON',
        'api.v1.verification.verify' => 'needs a signed verification link',
    ];

    /**
     * Operations that must have been checked against a published body
     * schema: every one whose published schema or answer was found wrong in
     * the re-audit after round eight, and (asserted separately, so a new one
     * is covered without being named here) every GET whose envelope is
     * `list`.
     */
    private const array MUST = [
        'api.v1.subscriptions.index',
        'api.v1.subscriptions.show',
        'api.v1.subscriptions.plan_options',
        'api.admin.infrastructure.dedicated',
        'api.admin.infrastructure.hosting_nodes',
        'api.admin.readiness.products.index',
        'api.admin.readiness.products.show',
        'api.v1.activity.index',
        'api.v1.notifications.index',
        'api.v1.backups.index',
        'api.v1.vps.console',
    ];

    /** Below this many operations checked, the walk has stopped reaching the API. */
    private const int FLOOR = 110;

    #[Test]
    public function the_description_it_reads_is_the_committed_file(): void
    {
        $this->assertSame(
            (string) file_get_contents(base_path('../../'.GenerateOpenApiSpec::OUTPUT)),
            GenerateOpenApiSpec::HEADER.YamlEmitter::emit(GenerateOpenApiSpec::description()),
            'The document this test reads is not the committed docs/openapi.yaml. Run `php artisan openapi:generate`.',
        );
    }

    #[Test]
    public function every_response_the_seeded_world_answers_is_the_shape_its_operation_publishes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SoftwareCatalogueSeeder::class);
        $this->seed(DevelopmentSeeder::class);
        $this->seed(E2ESeeder::class);

        $customer = User::query()->where('email', 'customer@lynomia.local')->firstOrFail();
        $admin = User::query()->where('email', 'admin@lynomia.local')->firstOrFail();
        $account = $customer->customers()->firstOrFail();

        $description = GenerateOpenApiSpec::description();
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = $description['components']['schemas'];
        $checker = new PublishedSchema($schemas);

        $checked = [];
        $unreached = [];
        $wrong = [];

        foreach (GenerateOpenApiSpec::describableRoutes() as $name => $route) {
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $uri = $this->uriFor($route, $account, str_starts_with($route->uri(), 'api/admin') ? $admin : $customer);

            if ($uri === null) {
                $unreached[] = $name;

                continue;
            }

            /** @var array<string, mixed> $operation */
            $operation = $description['paths']['/'.$route->uri()]['get'];
            $queries = self::queriesFor($name);

            if (in_array(['$ref' => '#/components/parameters/page'], $operation['parameters'] ?? [], true)) {
                $queries[] = '?per_page=1&page=2';
            }

            foreach ($queries as $query) {
                $this->app['auth']->forgetGuards();
                app('cache')->store()->flush();

                $response = $this->actingAs(str_starts_with($route->uri(), 'api/admin') ? $admin : $customer, 'sanctum')
                    ->getJson($uri.$query);

                $status = (string) $response->status();
                $published = $operation['responses'][$status] ?? null;
                $where = "GET {$uri}{$query} ({$name}) {$status}";

                if ($response->status() >= 500 || ! is_array($published)) {
                    $wrong[] = ($response->status() >= 500 ? "{$where}: a server error: " : "{$where}: the operation publishes no {$status}: ")
                        .substr((string) $response->getContent(), 0, 200);

                    continue;
                }

                /** @var ?array<string, mixed> $schema */
                $schema = $published['content']['application/json']['schema'] ?? null;

                if ($schema === null) {
                    continue;
                }

                $body = json_decode((string) $response->getContent(), false);

                foreach (array_slice(array_values(array_unique($checker->violations($body, $schema))), 0, 8) as $violation) {
                    $wrong[] = "{$where}: {$violation}";
                }

                if ($response->status() < 300) {
                    $checked[$name] = true;
                }
            }
        }

        $this->assertSame([], $wrong, "These responses are not what the description publishes for them:\n  ".implode("\n  ", $wrong));

        sort($unreached);
        $expected = array_keys(self::UNREACHED);
        sort($expected);
        $this->assertSame($expected, $unreached, 'The GET routes this test does not reach are not the ones UNREACHED names.');

        $missing = array_values(array_diff(self::MUST, array_keys($checked)));
        $this->assertSame([], $missing, 'These operations were not checked against a published body.');

        /** @var array<string, array<string, mixed>> $operations */
        $operations = require base_path('resources/openapi/operations.php');
        $lists = [];

        foreach (GenerateOpenApiSpec::describableRoutes() as $name => $route) {
            if (in_array('GET', $route->methods(), true) && ($operations[$name]['response']['envelope'] ?? null) === 'list') {
                $lists[] = $name;
            }
        }

        $this->assertNotSame([], $lists, 'The walk found no operation published with the list envelope.');
        $this->assertSame([], array_values(array_diff($lists, array_keys($checked))), 'These list operations were not checked against a published body.');

        $this->assertGreaterThanOrEqual(self::FLOOR, count($checked), 'Barely any operations were checked; the walk has stopped reaching the API.');
    }

    /**
     * The checker itself: each of these must be refused, and its twin
     * accepted. Without this, a checker that accepted everything would pass
     * the test above.
     */
    #[Test]
    public function the_checker_refuses_the_mismatches_it_exists_to_find(): void
    {
        $checker = new PublishedSchema([
            'Money' => ['type' => 'object', 'required' => ['amount'], 'properties' => ['amount' => ['type' => 'integer']], 'additionalProperties' => false],
            'Ulid' => ['type' => 'string', 'pattern' => '^[0-9a-hjkmnp-tv-z]{26}$'],
        ]);

        $cases = [
            'a boolean published as a string' => [['type' => ['string', 'null']], true, 'x'],
            'an integer published as a string' => [['type' => ['string', 'null']], 12, null],
            'a number published as a string' => [['type' => ['string', 'null']], 0.5, '0.5'],
            'null published as a Money' => [['$ref' => '#/components/schemas/Money'], null, (object) ['amount' => 1]],
            'an empty list published as an object' => [['type' => 'object'], [], new stdClass],
            'a missing required property' => [['$ref' => '#/components/schemas/Money'], new stdClass, (object) ['amount' => 0]],
            'a property nobody published' => [['$ref' => '#/components/schemas/Money'], (object) ['amount' => 1, 'x' => 1], (object) ['amount' => 1]],
            'an upper-case ULID' => [['$ref' => '#/components/schemas/Ulid'], '01M3KEWCXZP47ZXAAZ2QFCD3NN', '01m3kewcxzp47zxaaz2qfcd3nn'],
            'a value outside the enum' => [['enum' => ['a', 'b']], 'c', 'a'],
            'no branch of a oneOf' => [['oneOf' => [['$ref' => '#/components/schemas/Money'], ['type' => 'null']]], 'x', null],
            'a value two branches of a oneOf accept' => [['oneOf' => [['type' => 'string'], ['type' => ['string', 'null']]]], 'x', null],
            'a wrong item' => [['type' => 'array', 'items' => ['type' => 'integer']], [1, 'two'], [1, 2]],
            'a keyword it does not implement' => [['not' => ['type' => 'null']], 1, null],
        ];

        foreach ($cases as $what => [$schema, $refused, $accepted]) {
            $this->assertNotSame([], $checker->violations($refused, $schema), "The checker accepted {$what}.");

            if ($what !== 'a keyword it does not implement') {
                $this->assertSame([], $checker->violations($accepted, $schema), "The checker refused the twin of {$what}.");
            }
        }
    }

    /**
     * The queries an operation is asked with: none, except that a search is
     * asked for a name in a namespace on sale, and with no name at all (a
     * refusal whose status and body must be published too).
     *
     * @return list<string>
     */
    private static function queriesFor(string $name): array
    {
        return $name === 'api.v1.domains.search'
            ? ['?name=conformance-example.'.DomainTld::query()->where('enabled', true)->orderBy('tld')->value('tld'), '']
            : [''];
    }

    private function uriFor(RoutingRoute $route, Customer $account, User $as): ?string
    {
        $uri = '/'.$route->uri();

        foreach ($route->parameterNames() as $parameter) {
            $value = $this->parameterFor($route, $parameter, $account) ?? $this->fromItsList($route, $parameter, $as);

            if ($value === null) {
                return null;
            }

            $uri = str_replace('{'.$parameter.'}', $value, $uri);
        }

        return $uri;
    }

    /**
     * For `…/things/{thing}` with no row to fill it from: the id of the
     * first item `GET …/things` answers, when that is a route with no
     * parameters of its own. A catalogue plan has no list of its own; it is
     * the first plan of the first product that has one.
     */
    private function fromItsList(RoutingRoute $route, string $parameter, User $as): ?string
    {
        $this->app['auth']->forgetGuards();

        if ($route->uri() === 'api/v1/catalog/plans/{plan}') {
            foreach ((array) $this->actingAs($as)->getJson('/api/v1/catalog/products')->json('data.*.id') as $product) {
                $plan = $this->actingAs($as)->getJson('/api/v1/catalog/products/'.$product)->json('data.plans.0.id');

                if (is_string($plan)) {
                    return $plan;
                }
            }

            return null;
        }

        $suffix = '/{'.$parameter.'}';

        if (! str_ends_with($route->uri(), $suffix) || substr_count($route->uri(), '{') !== 1) {
            return null;
        }

        $id = $this->actingAs($as)->getJson('/'.substr($route->uri(), 0, -strlen($suffix)))->json('data.0.id');

        return is_string($id) ? $id : null;
    }

    /**
     * A value for one path parameter: on `api/v1` a row of the seeded
     * customer's, on `api/admin` any row. A parameter the route binds to a
     * model is filled from that model; the rest are named here.
     */
    private function parameterFor(RoutingRoute $route, string $parameter, Customer $account): ?string
    {
        $customer = str_starts_with($route->uri(), 'api/v1');
        $owned = static fn (string $model) => $model::query()->where('customer_id', $account->getKey());
        $services = Service::query()->where('customer_id', $account->getKey())->select('id');

        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $signature) {
            $model = $signature->getType()?->getName();

            if ($signature->getName() !== $parameter || $model === null || ! is_subclass_of($model, Model::class)) {
                continue;
            }

            /** @var Model $instance */
            $instance = new $model;
            $query = $model::query();

            if ($customer && Schema::hasColumn($instance->getTable(), 'customer_id')) {
                $query->where('customer_id', $account->getKey());
            }

            $found = $query->first();

            return $found === null ? null : (string) $found->getRouteKey();
        }

        $backup = Backup::query()->where('customer_id', $account->getKey())->first();

        $found = match (true) {
            $customer && $parameter === 'service' => $owned(Service::class)->first(),
            $customer && $parameter === 'vm' && in_array('backup', $route->parameterNames(), true) => $backup === null ? null : VirtualMachine::query()->where('service_id', $backup->service_id)->first(),
            $customer && $parameter === 'vm' => VirtualMachine::query()->whereIn('service_id', $services)->first(),
            $customer && $parameter === 'backup' => $backup,
            $customer && $parameter === 'site' => $owned(WordPressSite::class)->first(),
            $customer && $parameter === 'subscription' => $owned(Subscription::class)->first(),
            $customer && $parameter === 'zone' => $owned(DnsZone::class)->first(),
            $customer && $parameter === 'domain' => $owned(Domain::class)->first(),
            $customer && $parameter === 'account' => $owned(HostingAccount::class)->first(),
            $customer && $parameter === 'invoice' => $owned(Invoice::class)->first(),
            $customer && $parameter === 'server' => DedicatedServer::query()->whereIn('service_id', $services)->first(),
            ! $customer && $parameter === 'customer' => Customer::query()->find($account->getKey()),
            ! $customer && $parameter === 'server' && str_contains($route->uri(), 'dedicated/') => DedicatedServer::query()->first(),
            ! $customer && $parameter === 'server' => ManagedServer::query()->first(),
            ! $customer && $parameter === 'pool' => IpPool::query()->first(),
            ! $customer && $parameter === 'credential' => CredentialReference::query()->first(),
            ! $customer && $parameter === 'licence' => Licence::query()->first(),
            ! $customer && $parameter === 'provider' => ProviderInstance::query()->first(),
            ! $customer && $parameter === 'deployment' => DeploymentJob::query()->first(),
            ! $customer && $parameter === 'plan' => DeploymentPlan::query()->first(),
            ! $customer && $parameter === 'ticket' => SupportTicket::query()->first(),
            ! $customer && $parameter === 'operation' => VmReinstall::query()->first(),
            default => null,
        };

        if ($found instanceof Model) {
            return (string) $found->getKey();
        }

        return match (true) {
            ! $customer && $parameter === 'product' => Product::inDependencyOrder()[0]->value,
            ! $customer && $parameter === 'role' => Role::query()->where('name', '!=', 'customer')->value('name'),
            ! $customer && $parameter === 'type' => 'vps_reinstall',
            default => null,
        };
    }
}
