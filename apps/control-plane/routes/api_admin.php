<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Admin\Http\Controllers\BillingController;
use Lynomia\Modules\Admin\Http\Controllers\CustomerController;
use Lynomia\Modules\Admin\Http\Controllers\InfrastructureController;
use Lynomia\Modules\Admin\Http\Controllers\ProvisioningController;
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
});
