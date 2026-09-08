<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Admin\Http\Controllers\AuditController;
use Lynomia\Modules\Admin\Http\Controllers\BillingController;
use Lynomia\Modules\Admin\Http\Controllers\CustomerController;
use Lynomia\Modules\Admin\Http\Controllers\DriftController;
use Lynomia\Modules\Admin\Http\Controllers\HostingController;
use Lynomia\Modules\Admin\Http\Controllers\InfrastructureController;
use Lynomia\Modules\Admin\Http\Controllers\OperationsController;
use Lynomia\Modules\Admin\Http\Controllers\ProvisioningController;
use Lynomia\Modules\Admin\Http\Controllers\ServiceController;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;

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
