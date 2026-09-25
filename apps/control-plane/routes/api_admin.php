<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Admin\Http\Controllers\AuditController;
use Lynomia\Modules\Admin\Http\Controllers\BillingController;
use Lynomia\Modules\Admin\Http\Controllers\CountryCurrencyChangeController;
use Lynomia\Modules\Admin\Http\Controllers\CustomerController;
use Lynomia\Modules\Admin\Http\Controllers\DomainsController;
use Lynomia\Modules\Admin\Http\Controllers\DriftController;
use Lynomia\Modules\Admin\Http\Controllers\HostingController;
use Lynomia\Modules\Admin\Http\Controllers\InfrastructureController;
use Lynomia\Modules\Admin\Http\Controllers\OperationsController;
use Lynomia\Modules\Admin\Http\Controllers\ProvisioningController;
use Lynomia\Modules\Admin\Http\Controllers\ServiceController;
use Lynomia\Modules\Catalog\Http\Controllers\OperatorCatalogueController;
use Lynomia\Modules\Infrastructure\Http\Controllers\DeploymentJobController;
use Lynomia\Modules\Infrastructure\Http\Controllers\DeploymentPlanController;
use Lynomia\Modules\Infrastructure\Http\Controllers\DesiredStateController;
use Lynomia\Modules\Infrastructure\Http\Controllers\InventoryController;
use Lynomia\Modules\Infrastructure\Http\Controllers\OverviewController;
use Lynomia\Modules\Infrastructure\Http\Controllers\PreflightController;
use Lynomia\Modules\Infrastructure\Http\Controllers\ServerController;
use Lynomia\Modules\Infrastructure\Http\Controllers\SiteController;
use Lynomia\Modules\Infrastructure\Http\Controllers\SoftwareProfileController;
use Lynomia\Modules\Infrastructure\Http\Controllers\VmTemplateController;
use Lynomia\Modules\ProductReadiness\Http\Controllers\ProductReadinessController;
use Lynomia\Modules\Providers\Http\Controllers\ConnectionTestController;
use Lynomia\Modules\Providers\Http\Controllers\CredentialController;
use Lynomia\Modules\Providers\Http\Controllers\LicenceController;
use Lynomia\Modules\Providers\Http\Controllers\ProviderCatalogueController;
use Lynomia\Modules\Providers\Http\Controllers\ProviderController;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Http\Controllers\OperatorController;
use Lynomia\Modules\Rbac\Http\Controllers\RoleController;
use Lynomia\Modules\SharedHosting\Http\Controllers\OperatorHostingPackageController;
use Lynomia\Modules\Support\Http\Controllers\OperatorTicketController;

/*
|--------------------------------------------------------------------------
| Admin / NOC API — /api/admin
|--------------------------------------------------------------------------
|
| A separate prefix and route file from the customer API, so that
| administrative capability is not one forgotten check away from a customer
| session.
|
| The prefix is not the control. `auth:sanctum` here is the same guard the
| customer API uses, so the group alone would let any verified customer
| through: what actually separates the two surfaces is that every route below
| names the permission it requires. That is enforced by a test rather than by
| convention — tests/Feature/Rbac/AdminRoutesRequireAPermissionTest.php fails
| the build if a route is added here without one, if it names a permission that
| does not exist, or if it carries the tenant scope. It is the only way a rule
| like this survives contact with a deadline.
|
| Deliberately NOT the acting-customer middleware: an administrator acts on the
| platform, not on behalf of one account, and giving them a tenant scope would
| quietly hide the other accounts they are meant to be able to see.
|
| Permissions are named from the enum rather than as strings, so a rename is a
| compile error instead of an endpoint that silently denies everyone.
|
*/

Route::middleware(['auth:sanctum', 'verified', 'throttle:api'])->group(function (): void {
    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('permission:'.Permission::CustomerViewAny->value)
        ->name('customers.index');

    /*
     * Requests to change an account's country or currency. Reading the
     * queue is `customer.view_any`; deciding one is `customer.update`, the
     * permission for changing what an account says about itself — which is
     * exactly what this changes, after the platform has checked the facts.
     *
     * Declared before `customers/{customer}`: routes match in order, and a
     * literal segment declared after a parameter is a 404 for ever.
     */
    Route::get('customers/country-currency-changes', [CountryCurrencyChangeController::class, 'index'])
        ->middleware('permission:'.Permission::CustomerViewAny->value)
        ->name('customers.country_currency_changes.index');

    Route::post('customers/country-currency-changes/{change}/approve', [CountryCurrencyChangeController::class, 'approve'])
        ->middleware('permission:'.Permission::CustomerUpdate->value)
        ->name('customers.country_currency_changes.approve');

    Route::post('customers/country-currency-changes/{change}/reject', [CountryCurrencyChangeController::class, 'reject'])
        ->middleware('permission:'.Permission::CustomerUpdate->value)
        ->name('customers.country_currency_changes.reject');

    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:'.Permission::CustomerView->value)
        ->name('customers.show');

    /*
     * Suspension has its own permission, separate from update. Stopping an
     * account from buying anything more and correcting a typo in its address
     * are not the same decision, and a role that may do the second should not
     * thereby be able to take a customer offline.
     */
    Route::put('customers/{customer}/status', [CustomerController::class, 'setStatus'])
        ->middleware('permission:'.Permission::CustomerSuspend->value)
        ->name('customers.status');

    Route::get('provisioning/jobs', [ProvisioningController::class, 'index'])
        ->middleware('permission:'.Permission::ProvisioningView->value)
        ->name('provisioning.jobs');

    Route::get('provisioning/needs-review', [ProvisioningController::class, 'needingReview'])
        ->middleware('permission:'.Permission::ProvisioningView->value)
        ->name('provisioning.needs_review');

    /*
     * Putting a stopped job back into the pool. The action refuses anything
     * that would build a second resource or destroy data a second time, so
     * what this permission grants is the safe half of recovery and not a
     * general "run it again".
     */
    Route::post('provisioning/jobs/{job}/retry', [ProvisioningController::class, 'retry'])
        ->middleware('permission:'.Permission::ProvisioningRetry->value)
        ->name('provisioning.retry');

    /*
     * Adoption: an operator telling the platform that a machine the provider
     * already built belongs to a job that timed out. Behind provisioning.retry
     * rather than provisioning.view, because it changes what the platform
     * believes about the world on the strength of a person's word.
     */
    Route::post('provisioning/jobs/{job}/adopt', [ProvisioningController::class, 'adopt'])
        ->middleware('permission:'.Permission::ProvisioningRetry->value)
        ->name('provisioning.adopt');

    /*
     * Correcting the domain a stopped hosting build will serve: the repair a
     * retry cannot be, for a build refused because it names no domain or one
     * another live account serves. It writes the job's payload and nothing
     * else; the retry that follows is the one above. Behind provisioning.retry
     * for the same reason adoption is — it changes what the platform will do
     * on the strength of a person's word.
     */
    Route::put('provisioning/jobs/{job}/hosting-domain', [ProvisioningController::class, 'nameHostingDomain'])
        ->middleware('permission:'.Permission::ProvisioningRetry->value)
        ->name('provisioning.hosting_domain');

    /*
     * The destructive operations queue. Reading it is provisioning.view like
     * the job list; deciding the outcome of one is provisioning.retry, which
     * is the permission that already means "change what the platform believes
     * on the strength of a person's word".
     */
    Route::get('operations/reinstalls', [OperationsController::class, 'reinstalls'])
        ->middleware('permission:'.Permission::ProvisioningView->value)
        ->name('operations.reinstalls');

    Route::get('operations/reinstalls/{type}/{operation}', [OperationsController::class, 'reinstall'])
        ->middleware('permission:'.Permission::ProvisioningView->value)
        ->name('operations.reinstall');

    Route::post('operations/reinstalls/{type}/{operation}/resolve', [OperationsController::class, 'resolveReinstall'])
        ->middleware('permission:'.Permission::ProvisioningRetry->value)
        ->name('operations.reinstall_resolve');

    /*
     * The domain queues. Read-only, and under `service.view_any` because a
     * name is a service somebody bought: an operator who may list a customer's
     * machines may list their domains.
     *
     * There is no write here on purpose. An operator who needs to renew or
     * re-point a customer's name does it through the customer paths, so that
     * one set of rules about money, idempotency and the Timeout Rule applies
     * to everybody rather than two.
     */
    Route::get('domains', [DomainsController::class, 'index'])
        ->middleware('permission:'.Permission::ServiceViewAny->value)
        ->name('domains.index');

    Route::get('domains/operations', [DomainsController::class, 'operations'])
        ->middleware('permission:'.Permission::ServiceViewAny->value)
        ->name('domains.operations');

    Route::get('infrastructure/nodes', [InfrastructureController::class, 'nodes'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.nodes');

    Route::get('infrastructure/ip-pools', [InfrastructureController::class, 'ipPools'])
        ->middleware('permission:'.Permission::IpamView->value)
        ->name('infrastructure.ip_pools');

    Route::get('infrastructure/dedicated', [InfrastructureController::class, 'dedicatedInventory'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.dedicated');

    Route::get('infrastructure/hosting-nodes', [InfrastructureController::class, 'hostingNodes'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.hosting_nodes');

    Route::get('invoices', [BillingController::class, 'invoices'])
        ->middleware('permission:'.Permission::InvoiceViewAny->value)
        ->name('invoices.index');

    Route::get('transactions', [BillingController::class, 'transactions'])
        ->middleware('permission:'.Permission::PaymentViewAny->value)
        ->name('transactions.index');

    // Moving money back out is not implied by being able to look at payments.
    Route::post('transactions/{transaction}/refunds', [BillingController::class, 'refund'])
        ->middleware('permission:'.Permission::PaymentRefund->value)
        ->name('transactions.refund');

    /*
     * Cancelling a bill the platform should never have issued. Separate from
     * refund: this one moves no money, and the action itself refuses any
     * invoice that has taken any.
     */
    Route::post('invoices/{invoice}/void', [BillingController::class, 'voidInvoice'])
        ->middleware('permission:'.Permission::InvoiceManage->value)
        ->name('invoices.void');

    /*
     * The drift queue. Viewing and deciding are separate permissions: an NOC
     * shift can be given the screen without being given the authority to
     * declare a customer's missing machine a non-issue.
     */
    /*
     |--------------------------------------------------------------------------
     | Control Centre — the machines
     |--------------------------------------------------------------------------
     |
     | Two permissions rather than one across these routes, because they are two
     | different decisions. safety.change raises or lowers what may be done to a
     | machine; safety.allow_reimage clears one for a wipe. An operator can
     | reasonably hold the first and not the second, and the infrastructure-admin
     | role is granted exactly that way.
     |
     | Reaching the destructive classification is checked twice — on the route
     | and again in the controller — because the route protects the endpoint and
     | the controller protects the specific transition, and only one of those
     | knows which classification was asked for.
     */
    Route::get('infrastructure/servers', [ServerController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.index');

    Route::get('infrastructure/servers/{server}', [ServerController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.show');

    Route::post('infrastructure/servers', [ServerController::class, 'store'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.store');

    Route::post('infrastructure/servers/{server}/classify', [ServerController::class, 'classify'])
        ->middleware('permission:'.Permission::SafetyChange->value)
        ->name('infrastructure.servers.classify');

    Route::post('infrastructure/servers/{server}/clear-for-reimage', [ServerController::class, 'clearForReimage'])
        ->middleware('permission:'.Permission::AllowReimage->value)
        ->name('infrastructure.servers.clear_for_reimage');

    Route::delete('infrastructure/servers/{server}/clear-for-reimage', [ServerController::class, 'revokeReimageClearance'])
        ->middleware('permission:'.Permission::SafetyChange->value)
        ->name('infrastructure.servers.revoke_reimage_clearance');

    Route::post('infrastructure/servers/{server}/credential', [ServerController::class, 'attachCredential'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('infrastructure.servers.attach_credential');

    Route::delete('infrastructure/servers/{server}/credential', [ServerController::class, 'detachCredential'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('infrastructure.servers.detach_credential');

    Route::post('infrastructure/servers/{server}/discover', [ServerController::class, 'discover'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.discover');

    Route::get('infrastructure/servers/{server}/gpus', [ServerController::class, 'gpus'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.gpus.index');

    Route::post('infrastructure/servers/{server}/gpus', [ServerController::class, 'registerGpu'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.gpus.store');

    Route::get('infrastructure/servers/{server}/facts', [ServerController::class, 'facts'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.facts');

    Route::post('infrastructure/servers/{server}/connection-test', [ConnectionTestController::class, 'forServer'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.connection_test');

    /*
     | The execution chain: profile → desired state → plan → approval → run.
     |
     | Reading is the operator-view permission. Stating what a machine should
     | be and computing what that would take is infrastructure.manage; saying
     | yes to a specific plan is deployment.approve, which the
     | infrastructure-admin role does not hold; starting the run and settling
     | one that stopped is deployment.run. Three decisions, three permissions,
     | and the four-eyes rule inside the approval on top.
     */
    /*
     | The overview and the site registry. Reading is the operator-view
     | permission; registering a place is infrastructure.manage.
     */
    Route::get('infrastructure/overview', [OverviewController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.overview');

    /*
     | The unified preflight: what exactly prevents this from being used.
     |
     | The route requires the operator view, which is what a simulation run
     | needs — it reads this platform's own records and rehearses against
     | controlled providers. A read-only-real run sends real credentials to
     | real endpoints, which is the same act as pressing "test connection", so
     | the controller requires provider.manage for that mode as well. The
     | second half cannot be middleware: the permission depends on the mode,
     | and middleware does not see the body.
     |
     | Throttled because an operator clicking a button repeatedly must not
     | become a burst of outbound requests at somebody else's API. Nothing here
     | writes, in either mode, so the throttle is protecting providers rather
     | than this platform.
     */
    Route::post('infrastructure/preflight', [PreflightController::class, 'run'])
        ->middleware(['permission:'.Permission::InfrastructureView->value, 'throttle:preflight'])
        ->name('infrastructure.preflight');

    Route::get('infrastructure/regions', [SiteController::class, 'regions'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.regions.index');

    Route::get('infrastructure/datacenters', [SiteController::class, 'datacenters'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.datacenters.index');

    /*
     * The top of the estate. Every other inventory row hangs off a region
     * through a datacenter, and RegisterDatacenter used to begin by finding a
     * region nothing could create — so on a fresh deployment the chain was
     * unreachable from its first link, and the only ways in were a SQL client,
     * an edited seeder or the reference topology.
     */
    Route::post('infrastructure/regions', [SiteController::class, 'storeRegion'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.regions.store');

    Route::post('infrastructure/datacenters', [SiteController::class, 'storeDatacenter'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.datacenters.store');

    Route::get('infrastructure/racks', [SiteController::class, 'racks'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.racks.index');

    Route::post('infrastructure/racks', [SiteController::class, 'storeRack'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.racks.store');

    /*
     * The images a cluster may install.
     *
     * Operator data, for the same reason racks and datacenters are: onboarding
     * a real cluster must be somebody filling in the Control Center rather
     * than a developer editing a seeder. Everything downstream reads the same
     * table — placement resolves a plan's declared slug against it, and a VPS
     * build that finds no image is refused rather than started.
     *
     * DELETE withdraws rather than destroys: machines already built point at
     * the row, and "which image is this server running" is the first question
     * asked when a rebuild goes wrong.
     */
    /*
     * The catalogue: what this platform sells, and for how much.
     *
     * Before these existed nothing could write `products`, `plans`,
     * `plan_prices` or `hosting_packages` in production — the only writer was
     * a seeder that refuses to run there — so a clean deployment could model
     * everything it sells and sell none of it. That was E-11.
     *
     * POST is an upsert keyed on the slug, or on (plan, currency, period) for
     * a price: recording something twice is an operator correcting it, and
     * answering 409 would leave "fix the typo" with no route through the API.
     * DELETE withdraws rather than destroys, because orders, invoices and
     * subscriptions point at these rows.
     *
     * Pricing is its own permission. Changing what a thing costs is a
     * different act from describing it, and the roles that may do one are not
     * always the roles that may do the other.
     *
     * Every route here is `catalog.manage`, including the reads, and that is
     * deliberate rather than lazy. `catalog.view` looks like the obvious guard
     * for a listing and is the one permission a plain customer holds — the
     * baseline every customer login carries, which `AuthorizationTest` treats
     * as the sole exception to "staff only". Guarding these with it would hand
     * every customer the operator catalogue: withdrawn products, unlisted
     * plans, both languages and every price, active or not. A read of the
     * operator surface is an operator act.
     */
    Route::get('catalogue/products', [OperatorCatalogueController::class, 'products'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.products.index');

    Route::post('catalogue/products', [OperatorCatalogueController::class, 'recordProduct'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.products.record');

    Route::delete('catalogue/products/{product}', [OperatorCatalogueController::class, 'withdrawProduct'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.products.withdraw');

    Route::get('catalogue/plans', [OperatorCatalogueController::class, 'plans'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.plans.index');

    Route::post('catalogue/plans', [OperatorCatalogueController::class, 'recordPlan'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.plans.record');

    Route::delete('catalogue/plans/{plan}', [OperatorCatalogueController::class, 'withdrawPlan'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.plans.withdraw');

    Route::post('catalogue/plans/{plan}/prices', [OperatorCatalogueController::class, 'setPrice'])
        ->middleware('permission:'.Permission::PricingManage->value)
        ->name('catalogue.prices.set');

    Route::delete('catalogue/plans/{plan}/prices/{price}', [OperatorCatalogueController::class, 'withdrawPrice'])
        ->middleware('permission:'.Permission::PricingManage->value)
        ->name('catalogue.prices.withdraw');

    Route::get('catalogue/hosting-packages', [OperatorHostingPackageController::class, 'index'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.hosting_packages.index');

    Route::post('catalogue/hosting-packages', [OperatorHostingPackageController::class, 'store'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.hosting_packages.map');

    Route::delete('catalogue/hosting-packages/{package}', [OperatorHostingPackageController::class, 'destroy'])
        ->middleware('permission:'.Permission::CatalogManage->value)
        ->name('catalogue.hosting_packages.withdraw');

    Route::get('infrastructure/templates', [VmTemplateController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.templates.index');

    Route::post('infrastructure/templates', [VmTemplateController::class, 'store'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.templates.store');

    Route::delete('infrastructure/templates/{template}', [VmTemplateController::class, 'destroy'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.templates.withdraw');

    /*
    |--------------------------------------------------------------------------
    | The rest of the estate
    |--------------------------------------------------------------------------
    |
    | Clusters, networks, address pools, subnets, panel servers, dedicated
    | stock and BMC endpoints. All of these could be read and none of them
    | could be written, which is what made a fresh deployment unconfigurable
    | without a SQL client.
    |
    | The permissions follow the domain rather than the URL prefix: addressing
    | is IPAM's, panel servers are shared hosting's, chassis and their
    | controllers are dedicated's. `network-engineer` already holds the IPAM
    | pair and `infrastructure-admin` holds all of them, so these routes give
    | four permissions that had no route their first one.
    |
    | Nothing behind these contacts anything. A row here is an operator saying
    | a thing exists; whether it answers is a reconciler's question.
    */
    Route::get('infrastructure/clusters', [InventoryController::class, 'clusters'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.clusters.index');

    Route::post('infrastructure/clusters', [InventoryController::class, 'storeCluster'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.clusters.store');

    /*
     * Corrections. Every one of these is a PUT that changes only the fields it
     * was sent, carries an optional `version` precondition so that two
     * operators on one row cannot silently overwrite each other, and refuses
     * to take something out of service while a customer is still living on it.
     *
     * There is no DELETE anywhere in this block, deliberately. Each of these
     * rows is pointed at by something a customer paid for — machines,
     * addresses, accounts, chassis — so the lifecycle is the one the models
     * already have: stop accepting new work, drain, retire. Deleting would
     * either orphan those rows or cascade through them.
     */
    Route::put('infrastructure/regions/{region}', [InventoryController::class, 'updateRegion'])
        ->whereUlid('region')
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.regions.update');

    Route::put('infrastructure/clusters/{cluster}', [InventoryController::class, 'updateCluster'])
        ->whereUlid('cluster')
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.clusters.update');

    Route::put('infrastructure/networks/{network}', [InventoryController::class, 'updateNetwork'])
        ->whereUlid('network')
        ->middleware('permission:'.Permission::NetworkManage->value)
        ->name('infrastructure.networks.update');

    Route::put('infrastructure/ip-pools/{pool}', [InventoryController::class, 'updateIpPool'])
        ->whereUlid('pool')
        ->middleware('permission:'.Permission::IpamManage->value)
        ->name('infrastructure.ip_pools.update');

    Route::put('infrastructure/hosting-nodes/{node}', [InventoryController::class, 'updateHostingNode'])
        ->whereUlid('node')
        ->middleware('permission:'.Permission::HostingNodeManage->value)
        ->name('infrastructure.hosting_nodes.update');

    Route::get('infrastructure/networks', [InventoryController::class, 'networks'])
        ->middleware('permission:'.Permission::IpamView->value)
        ->name('infrastructure.networks.index');

    Route::post('infrastructure/networks', [InventoryController::class, 'storeNetwork'])
        ->middleware('permission:'.Permission::NetworkManage->value)
        ->name('infrastructure.networks.store');

    Route::post('infrastructure/ip-pools', [InventoryController::class, 'storeIpPool'])
        ->middleware('permission:'.Permission::IpamManage->value)
        ->name('infrastructure.ip_pools.store');

    Route::get('infrastructure/ip-pools/{pool}/subnets', [InventoryController::class, 'subnets'])
        ->whereUlid('pool')
        ->middleware('permission:'.Permission::IpamView->value)
        ->name('infrastructure.subnets.index');

    Route::post('infrastructure/ip-pools/{pool}/subnets', [InventoryController::class, 'storeSubnet'])
        ->whereUlid('pool')
        ->middleware('permission:'.Permission::IpamManage->value)
        ->name('infrastructure.subnets.store');

    Route::post('infrastructure/hosting-nodes', [InventoryController::class, 'storeHostingNode'])
        ->middleware('permission:'.Permission::HostingNodeManage->value)
        ->name('infrastructure.hosting_nodes.store');

    Route::post('infrastructure/dedicated', [InventoryController::class, 'storeDedicatedServer'])
        ->middleware('permission:'.Permission::DedicatedManage->value)
        ->name('infrastructure.dedicated.store');

    Route::get('infrastructure/dedicated/{server}/bmc', [InventoryController::class, 'bmcEndpoint'])
        ->whereUlid('server')
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.dedicated.bmc.show');

    /*
     * Recording where a machine's controller is, which is configuration, and
     * deliberately not `bmc.access`, which is the authority to use one. The
     * address goes through the same outbound policy the connection testers
     * use, at the moment it is written rather than only when it is dialled.
     */
    Route::post('infrastructure/dedicated/{server}/bmc', [InventoryController::class, 'storeBmcEndpoint'])
        ->whereUlid('server')
        ->middleware('permission:'.Permission::DedicatedManage->value)
        ->name('infrastructure.dedicated.bmc.store');

    Route::get('infrastructure/profiles', [SoftwareProfileController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.profiles.index');

    Route::get('infrastructure/servers/{server}/desired-state', [DesiredStateController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.desired_state');

    Route::put('infrastructure/servers/{server}/desired-state', [DesiredStateController::class, 'assign'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.assign_desired_state');

    Route::delete('infrastructure/servers/{server}/desired-state', [DesiredStateController::class, 'clear'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.clear_desired_state');

    Route::get('infrastructure/servers/{server}/plan', [DeploymentPlanController::class, 'current'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.plan');

    Route::post('infrastructure/servers/{server}/plan', [DeploymentPlanController::class, 'plan'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.compute_plan');

    Route::get('infrastructure/plans/{plan}', [DeploymentPlanController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.plans.show');

    Route::post('infrastructure/plans/{plan}/approve', [DeploymentPlanController::class, 'approve'])
        ->middleware('permission:'.Permission::DeploymentApprove->value)
        ->name('infrastructure.plans.approve');

    Route::delete('infrastructure/plans/{plan}/approval', [DeploymentPlanController::class, 'revokeApproval'])
        ->middleware('permission:'.Permission::DeploymentApprove->value)
        ->name('infrastructure.plans.revoke_approval');

    Route::get('infrastructure/deployments', [DeploymentJobController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.deployments.index');

    Route::get('infrastructure/deployments/{deployment}', [DeploymentJobController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.deployments.show');

    Route::post('infrastructure/servers/{server}/deployments', [DeploymentJobController::class, 'request'])
        ->middleware('permission:'.Permission::DeploymentRun->value)
        ->name('infrastructure.servers.request_deployment');

    Route::post('infrastructure/deployments/{deployment}/resolve', [DeploymentJobController::class, 'resolve'])
        ->middleware('permission:'.Permission::DeploymentRun->value)
        ->name('infrastructure.deployments.resolve');

    Route::post('infrastructure/deployments/{deployment}/cancel', [DeploymentJobController::class, 'cancel'])
        ->middleware('permission:'.Permission::DeploymentRun->value)
        ->name('infrastructure.deployments.cancel');

    /*
     | Providers: the accounts Lynomia holds with other people.
     |
     | The catalogue is behind the view permission because it describes this
     | build rather than any account — it is the list a registration screen is
     | drawn from, and it names no endpoint, no credential and no customer.
     |
     | Enabling and disabling are separate endpoints rather than a state field
     | on an update, so that each is one intention with one audit row. A PATCH
     | that could carry `state: enabled` among other edits would make "who
     | turned on the payment provider" a question about a diff.
     */
    Route::get('providers/catalogue', [ProviderCatalogueController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('providers.catalogue');

    Route::get('providers', [ProviderController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('providers.index');

    Route::get('providers/{provider}', [ProviderController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('providers.show');

    Route::post('providers', [ProviderController::class, 'store'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('providers.store');

    Route::post('providers/{provider}/enable', [ProviderController::class, 'enable'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('providers.enable');

    Route::post('providers/{provider}/disable', [ProviderController::class, 'disable'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('providers.disable');

    // Recomputing what is blocking a provider changes nothing at the provider
    // and contacts nobody, so it sits behind managing rather than anything
    // sharper.
    Route::post('providers/{provider}/assess', [ProviderController::class, 'assess'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('providers.assess');

    Route::post('providers/{provider}/credential', [CredentialController::class, 'attachToProvider'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('providers.attach_credential');

    Route::delete('providers/{provider}/credential', [CredentialController::class, 'detachFromProvider'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('providers.detach_credential');

    /*
     | Credentials: references into the secret store, never values.
     |
     | Reading is the operator-view permission — a credential's row says its
     | name, its state and what uses it, none of which opens anything. Every
     | write is credential.manage, which the support role does not hold.
     */
    Route::get('credentials', [CredentialController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('credentials.index');

    Route::get('credentials/{credential}', [CredentialController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('credentials.show');

    Route::post('credentials', [CredentialController::class, 'store'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('credentials.store');

    Route::post('credentials/{credential}/revoke', [CredentialController::class, 'revoke'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('credentials.revoke');

    Route::post('credentials/{credential}/rotated', [CredentialController::class, 'rotated'])
        ->middleware('permission:'.Permission::CredentialManage->value)
        ->name('credentials.rotated');

    Route::post('providers/{provider}/licence', [LicenceController::class, 'attachToProvider'])
        ->middleware('permission:'.Permission::LicenceManage->value)
        ->name('providers.attach_licence');

    Route::delete('providers/{provider}/licence', [LicenceController::class, 'detachFromProvider'])
        ->middleware('permission:'.Permission::LicenceManage->value)
        ->name('providers.detach_licence');

    /*
     | Licences: what was bought, what it covers, and when it lapses.
     |
     | The state follows the calendar and is recomputed nightly; `refresh`
     | runs the same sweep on demand. An operator's one override is to
     | declare the vendor rejected a licence — never to declare an expired
     | one active.
     */
    Route::get('licences', [LicenceController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('licences.index');

    Route::get('licences/{licence}', [LicenceController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('licences.show');

    Route::post('licences', [LicenceController::class, 'store'])
        ->middleware('permission:'.Permission::LicenceManage->value)
        ->name('licences.store');

    Route::post('licences/refresh', [LicenceController::class, 'refresh'])
        ->middleware('permission:'.Permission::LicenceManage->value)
        ->name('licences.refresh');

    Route::post('licences/{licence}/renew', [LicenceController::class, 'renew'])
        ->middleware('permission:'.Permission::LicenceManage->value)
        ->name('licences.renew');

    Route::post('licences/{licence}/invalidate', [LicenceController::class, 'invalidate'])
        ->middleware('permission:'.Permission::LicenceManage->value)
        ->name('licences.invalidate');

    Route::post('providers/{provider}/connection-test', [ConnectionTestController::class, 'forProvider'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('providers.connection_test');

    /*
     | Product readiness: whether the platform may sell a thing.
     |
     | Reading is the operator-view permission. Reassessing contacts nobody and
     | changes nothing at any provider, so it sits behind provider.manage like
     | a provider reassessment does. Declaring a product sellable is its own
     | permission that no operator role holds by default: it is a commercial
     | decision on top of a technical fact, and the audit row must name the
     | person who made it.
     */
    Route::get('readiness/products', [ProductReadinessController::class, 'index'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('readiness.products.index');

    Route::get('readiness/dependencies', [ProductReadinessController::class, 'dependencies'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('readiness.dependencies');

    Route::post('readiness/products/assess', [ProductReadinessController::class, 'assessAll'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('readiness.products.assess_all');

    Route::get('readiness/products/{product}', [ProductReadinessController::class, 'show'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('readiness.products.show');

    Route::post('readiness/products/{product}/assess', [ProductReadinessController::class, 'assess'])
        ->middleware('permission:'.Permission::ProviderManage->value)
        ->name('readiness.products.assess');

    Route::post('readiness/products/{product}/sellable', [ProductReadinessController::class, 'declareSellable'])
        ->middleware('permission:'.Permission::ReadinessDeclare->value)
        ->name('readiness.products.declare_sellable');

    Route::delete('readiness/products/{product}/sellable', [ProductReadinessController::class, 'withdrawSellability'])
        ->middleware('permission:'.Permission::ReadinessDeclare->value)
        ->name('readiness.products.withdraw_sellability');

    Route::get('drift', [DriftController::class, 'index'])
        ->middleware('permission:'.Permission::DriftView->value)
        ->name('drift.index');

    Route::post('drift/{drift}/review', [DriftController::class, 'review'])
        ->middleware('permission:'.Permission::DriftResolve->value)
        ->name('drift.review');

    // Asking for a fresh comparison changes nothing at the provider, but it
    // does put load on it, so it sits behind managing infrastructure.
    Route::post('infrastructure/clusters/{cluster}/reconcile', [DriftController::class, 'reconcile'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.reconcile');

    // Read-only, permanently. The table is append-only at the model and there
    // is no endpoint here that could amend it.
    /*
     * Putting a suspended account back, by hand. The automated path is the
     * subscription listener — pay and it comes back — and this is the other
     * way in: abuse that has been investigated, or a payment that arrived
     * outside the platform.
     */
    Route::post('hosting-accounts/{account}/unsuspend', [HostingController::class, 'unsuspend'])
        ->middleware('permission:'.Permission::HostingAccountManage->value)
        ->name('hosting_accounts.unsuspend');

    /*
     * Setting a new panel password and handing it back once. Its own
     * permission, not hosting_account.manage: a reset is quiet and hands the
     * holder a live login to a customer's mail, files and databases, and it is
     * the only operator path into a customer's panel. Three a minute per
     * operator, as the credential reset it is — the limiter is keyed on the
     * user, and its prefix is its own.
     */
    Route::post('hosting-accounts/{account}/password-reset', [HostingController::class, 'resetPassword'])
        ->middleware([
            'permission:'.Permission::HostingAccountResetPassword->value,
            'throttle:3,1,hosting-password-reset:',
        ])
        ->name('hosting_accounts.password_reset');

    /*
     * Deleting an account and everything on it. The retention window is
     * enforced by the action; skipping it needs the same permission again
     * inside the controller, because "terminate what has expired" and "delete
     * a live customer's data today" are different decisions.
     */
    Route::delete('hosting-accounts/{account}', [HostingController::class, 'terminate'])
        ->middleware('permission:'.Permission::HostingAccountManage->value)
        ->name('hosting_accounts.terminate');

    /*
     * Ending a service and destroying the machine behind it. The action
     * enforces the retention window; `force` skips it and is checked again
     * inside the controller, because "terminate what has expired" and "delete
     * a live customer's data today" are different decisions.
     */
    /*
     * The service list, and the only place `placement_blocked_reason` can be
     * read without a SQL client. `?blocked=1` narrows it to the customers who
     * have paid for something the platform could not place.
     */
    Route::get('services', [ServiceController::class, 'index'])
        ->middleware('permission:'.Permission::ServiceViewAny->value)
        ->name('services.index');

    Route::delete('services/{service}', [ServiceController::class, 'terminate'])
        ->middleware('permission:'.Permission::ServiceTerminate->value)
        ->name('services.terminate');

    /*
     * Putting a decommissioned machine back into stock. Behind managing
     * dedicated hardware rather than terminating services: this is a statement
     * about a physical machine's disks, not about a customer's subscription.
     */
    Route::post('dedicated/{server}/return-to-stock', [ServiceController::class, 'returnToStock'])
        ->middleware('permission:'.Permission::DedicatedManage->value)
        ->name('dedicated.return_to_stock');

    Route::get('audit', [AuditController::class, 'index'])
        ->middleware('permission:'.Permission::AuditView->value)
        ->name('audit.index');

    /*
    |--------------------------------------------------------------------------
    | Who may operate the platform
    |--------------------------------------------------------------------------
    |
    | `role.manage` was declared in the permission catalogue and referenced by
    | no route, which made it a power nobody could exercise and eleven
    | permissions nobody could be granted. These are that permission's routes.
    |
    | One permission for all of them, deliberately. Reading who has authority
    | and changing who has authority look like the usual view/manage pair, and
    | are not: a list of operators, their roles and which of them is privileged
    | is a map of how to escalate, and the only people who should be reading it
    | are the people who may change it.
    |
    | The refusals — your own account, a role you do not hold, the last
    | administrator — live in the actions, because each needs the actor, the
    | target and the state of everybody else at once.
    */
    Route::get('operators', [OperatorController::class, 'index'])
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('operators.index');

    Route::post('operators', [OperatorController::class, 'store'])
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('operators.store');

    Route::put('operators/{operator}/roles', [OperatorController::class, 'updateRoles'])
        ->whereUlid('operator')
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('operators.roles');

    Route::get('roles', [RoleController::class, 'index'])
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('roles.index');

    Route::get('permissions', [RoleController::class, 'permissions'])
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('permissions.index');

    Route::get('roles/{role}', [RoleController::class, 'show'])
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('roles.show');

    Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
        ->middleware('permission:'.Permission::RoleManage->value)
        ->name('roles.permissions');
});

/*
|--------------------------------------------------------------------------
| Support queue
|--------------------------------------------------------------------------
|
| Three permissions, because they are three different powers. Reading the
| queue is one thing; answering a customer is another; and changing what a
| ticket *is* — its priority, who owns it, whether it counts as solved — is a
| third. A first-line agent should be able to answer without being able to
| quietly downgrade an urgent ticket nobody then looks at.
|
| Every route sits in the same authenticated group as the rest of this file
| and names its permission, which the route test enforces.
*/
Route::middleware(['auth:sanctum', 'verified', 'throttle:api'])->group(function (): void {
    Route::get('support/tickets', [OperatorTicketController::class, 'index'])
        ->middleware('permission:'.Permission::TicketViewAny->value)
        ->name('support.tickets');

    Route::get('support/tickets/{ticket}', [OperatorTicketController::class, 'show'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketViewAny->value)
        ->name('support.ticket');

    Route::get('support/attachments/{attachment}', [OperatorTicketController::class, 'download'])
        ->whereUlid('attachment')
        ->middleware('permission:'.Permission::TicketViewAny->value)
        ->name('support.attachments.download');

    Route::post('support/tickets/{ticket}/replies', [OperatorTicketController::class, 'reply'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketReply->value)
        ->name('support.ticket_reply');

    Route::put('support/tickets/{ticket}/assignee', [OperatorTicketController::class, 'assign'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketManage->value)
        ->name('support.ticket_assign');

    Route::put('support/tickets/{ticket}/priority', [OperatorTicketController::class, 'prioritise'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketManage->value)
        ->name('support.ticket_priority');

    Route::post('support/tickets/{ticket}/resolve', [OperatorTicketController::class, 'resolve'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketManage->value)
        ->name('support.ticket_resolve');

    Route::post('support/tickets/{ticket}/close', [OperatorTicketController::class, 'close'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketManage->value)
        ->name('support.ticket_close');

    Route::post('support/tickets/{ticket}/reopen', [OperatorTicketController::class, 'reopen'])
        ->whereUlid('ticket')
        ->middleware('permission:'.Permission::TicketManage->value)
        ->name('support.ticket_reopen');
});
