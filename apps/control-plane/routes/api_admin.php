<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Admin\Http\Controllers\AuditController;
use Lynomia\Modules\Admin\Http\Controllers\BillingController;
use Lynomia\Modules\Admin\Http\Controllers\CustomerController;
use Lynomia\Modules\Admin\Http\Controllers\DomainsController;
use Lynomia\Modules\Admin\Http\Controllers\DriftController;
use Lynomia\Modules\Admin\Http\Controllers\HostingController;
use Lynomia\Modules\Admin\Http\Controllers\InfrastructureController;
use Lynomia\Modules\Admin\Http\Controllers\OperationsController;
use Lynomia\Modules\Admin\Http\Controllers\ProvisioningController;
use Lynomia\Modules\Admin\Http\Controllers\ServiceController;
use Lynomia\Modules\Infrastructure\Http\Controllers\ServerController;
use Lynomia\Modules\ProductReadiness\Http\Controllers\ProductReadinessController;
use Lynomia\Modules\Providers\Http\Controllers\ConnectionTestController;
use Lynomia\Modules\Providers\Http\Controllers\CredentialController;
use Lynomia\Modules\Providers\Http\Controllers\LicenceController;
use Lynomia\Modules\Providers\Http\Controllers\ProviderCatalogueController;
use Lynomia\Modules\Providers\Http\Controllers\ProviderController;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
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

    Route::get('infrastructure/servers/{server}/facts', [ServerController::class, 'facts'])
        ->middleware('permission:'.Permission::InfrastructureView->value)
        ->name('infrastructure.servers.facts');

    Route::post('infrastructure/servers/{server}/connection-test', [ConnectionTestController::class, 'forServer'])
        ->middleware('permission:'.Permission::InfrastructureManage->value)
        ->name('infrastructure.servers.connection_test');

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
