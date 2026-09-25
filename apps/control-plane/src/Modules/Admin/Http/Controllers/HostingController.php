<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\SharedHosting\Application\Actions\ResetHostingAccountPassword;
use Lynomia\Modules\SharedHosting\Application\Actions\TerminateHostingAccount;
use Lynomia\Modules\SharedHosting\Application\Actions\UnsuspendHostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * The hosting-account operations only a person should make.
 *
 * Each action existed and none had a caller. An account could be suspended
 * for non-payment (once the dunning listener was wired) and nothing could put
 * it back by hand; an account could reach the end of its retention window
 * and stay on the node for ever, holding a slot the node's capacity counted
 * as spent; and an account whose create answer was lost held a panel password
 * nobody had, with `changePassword` implemented by every adapter and called
 * by nothing.
 */
final class HostingController
{
    /**
     * Put a suspended account back into service.
     *
     * The automated path is the subscription listener: pay, and the account
     * comes back. This is the other way in — an account suspended for abuse
     * that has been investigated, or a payment that arrived outside the
     * platform. It has its own permission because reinstating somebody who was
     * stopped is not implied by being able to look at hosting.
     */
    public function unsuspend(Request $request, string $account): JsonResponse
    {
        $found = HostingAccount::query()->findOrFail($account);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $restored = app(UnsuspendHostingAccount::class)->execute($found);

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::HostingAccountUnsuspended,
            subject: $restored,
            customerId: $restored->customer_id,
            context: [
                'reason' => $validated['reason'],
                'username' => $restored->username,
                'primary_domain' => $restored->primary_domain,
            ],
        );

        return response()->json([
            'data' => [
                'id' => (string) $restored->getKey(),
                'status' => $restored->status->value,
            ],
        ]);
    }

    /**
     * Set a new panel password on an account and hand it back, once.
     *
     * The password is minted by the action — no password is accepted from
     * this body — and it appears in this response and nowhere else: not on the
     * row, not in the audit entry, not in a log. The route's middleware holds
     * it to three a minute per operator, as the credential reset it is, and
     * `api/*` responses are `Cache-Control: no-store`.
     *
     * Panel first, then the record. Recording first would write down an act
     * that may not have happened, on a table whose rows can be neither updated
     * nor deleted. The cost is the other order's failure: a reset the panel
     * accepted whose audit write then failed is a reset with no record, and
     * the operator is shown an error for a password that was in fact set.
     */
    public function resetPassword(Request $request, string $account): JsonResponse
    {
        $found = HostingAccount::query()->findOrFail($account);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $password = app(ResetHostingAccountPassword::class)->execute($found);

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::HostingAccountPasswordReset,
            subject: $found,
            customerId: $found->customer_id,
            context: [
                'reason' => $validated['reason'],
                'username' => $found->username,
                'primary_domain' => $found->primary_domain,
                'status' => $found->status->value,
            ],
        );

        return response()->json([
            'data' => [
                'id' => (string) $found->getKey(),
                'username' => $found->username,
                'password' => $password,
            ],
        ]);
    }

    /**
     * Delete an account and everything on it.
     *
     * `force` skips the retention window, and it is a real decision rather
     * than a convenience: the window exists so that a customer who is
     * suspended by mistake, or who changes their mind, still has their site.
     * Forcing is for an abuse case or an erasure request, so it needs its own
     * permission on top — an operator who may terminate expired accounts must
     * not thereby be able to delete a live customer's data today.
     *
     * There is deliberately no "force success" here, and no way to mark an
     * account terminated in the platform without the panel having actually
     * removed it: a row that says gone while the site still serves is the one
     * outcome nobody would ever look at again.
     */
    public function terminate(Request $request, string $account): JsonResponse
    {
        $found = HostingAccount::query()->findOrFail($account);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($validated['force'] ?? false);

        /*
         * A genuinely different grant from the one on the route, not the same
         * one asked for twice. service.terminate is the platform's permission
         * for destroying a customer's service, and that is what skipping the
         * window does: an operator who may clear out accounts whose retention
         * has elapsed does not thereby get to delete a live customer's site
         * this afternoon.
         */
        if ($force && $request->user()?->can(Permission::ServiceTerminate->value) !== true) {
            abort(403, 'Skipping the retention window needs permission to terminate a service.');
        }

        $terminated = app(TerminateHostingAccount::class)->execute($found, force: $force);

        app(RecordAuditEntry::class)->execute(
            action: AuditAction::HostingAccountTerminated,
            subject: $terminated,
            customerId: $terminated->customer_id,
            context: [
                'reason' => $validated['reason'],
                'username' => $terminated->username,
                'primary_domain' => $terminated->primary_domain,
                // Recorded distinctly. "The retention window had elapsed" and
                // "a person chose to skip it" are different acts and a reviewer
                // must be able to tell them apart.
                'retention_skipped' => $force,
            ],
        );

        return response()->json([
            'data' => [
                'id' => (string) $terminated->getKey(),
                'status' => $terminated->status->value,
            ],
        ]);
    }
}
