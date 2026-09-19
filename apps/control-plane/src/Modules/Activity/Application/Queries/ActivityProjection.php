<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\Queries;

use Lynomia\Modules\Activity\Domain\Enums\ActivityCategory;
use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;

/**
 * Source rows, translated into the customer's vocabulary.
 *
 * Every mapping below is a `match` with no default arm. That is the whole
 * design: a state this class has never seen throws rather than falling through
 * to something plausible, because the plausible answer for an unknown state is
 * always either "succeeded" or "failed" and both are lies. A product that adds
 * an execution state gets a test failure here, in a file whose job is to
 * decide what the customer is told, rather than a silent misreport in a feed.
 *
 * The three states that must survive the translation intact — `needs_review`,
 * `indeterminate`, `cancelled` — are mapped one-to-one wherever the source has
 * them, and never folded into `failed`.
 */
final readonly class ActivityProjection
{
    /**
     * Which part of the account a row belongs to.
     *
     * Only the provisioning branch needs asking: one table holds machine work,
     * hosting work and WordPress installs, so the category comes from the kind.
     * Every other branch knows its own category and states it in SQL.
     */
    public function category(string $source, string $sourceKind): ActivityCategory
    {
        if ($source !== 'provisioning_job') {
            throw new \LogicException(sprintf('The %s branch states its own category.', $source));
        }

        return match ($sourceKind) {
            'create_vps', 'destroy_vps', 'start', 'stop', 'restart',
            'reinstall_vps', 'reinstall_dedicated', 'resize', 'provision_dedicated' => ActivityCategory::Cloud,

            'create_hosting_account', 'change_hosting_package', 'install_wordpress' => ActivityCategory::Hosting,

            default => throw new \LogicException(sprintf('Unmapped provisioning kind "%s".', $sourceKind)),
        };
    }

    /**
     * The translation key the portal renders.
     *
     * A code, not a sentence: the server says what happened and the portal says
     * it in the reader's language. Composing English here would put English in
     * an Arabic feed, which is the finding Wave 1 closed.
     */
    public function messageCode(string $source, string $sourceKind): string
    {
        return match ($source) {
            'provisioning_job' => match ($sourceKind) {
                'create_vps' => 'activity.vps.created',
                'destroy_vps' => 'activity.vps.destroyed',
                'start' => 'activity.vps.started',
                'stop' => 'activity.vps.stopped',
                'restart' => 'activity.vps.restarted',
                'reinstall_vps' => 'activity.vps.reinstalled',
                'resize' => 'activity.vps.resized',
                'provision_dedicated' => 'activity.dedicated.provisioned',
                'reinstall_dedicated' => 'activity.dedicated.reinstalled',
                'create_hosting_account' => 'activity.hosting.created',
                'change_hosting_package' => 'activity.hosting.planChanged',
                'install_wordpress' => 'activity.wordpress.installed',
                default => throw new \LogicException(sprintf('Unmapped provisioning kind "%s".', $sourceKind)),
            },

            'domain_operation' => match ($sourceKind) {
                'register' => 'activity.domain.registered',
                'renew' => 'activity.domain.renewed',
                'transfer' => 'activity.domain.transferred',
                'redeem' => 'activity.domain.redeemed',
                default => throw new \LogicException(sprintf('Unmapped domain operation "%s".', $sourceKind)),
            },

            'wordpress_operation' => match ($sourceKind) {
                'create_staging' => 'activity.wordpress.stagingCreated',
                'clone' => 'activity.wordpress.cloned',
                'push_to_production' => 'activity.wordpress.pushed',
                default => throw new \LogicException(sprintf('Unmapped WordPress operation "%s".', $sourceKind)),
            },

            'backup' => 'activity.backup.taken',
            'file_restore' => 'activity.backup.filesRestored',
            'zone_import' => 'activity.dns.zoneImported',
            'order_transition' => 'activity.order.changed',
            'invoice_issued' => 'activity.invoice.issued',
            'invoice_paid' => 'activity.invoice.paid',
            'payment_failed' => 'activity.payment.failed',
            'support_ticket' => 'activity.support.opened',

            default => throw new \LogicException(sprintf('Unmapped activity source "%s".', $source)),
        };
    }

    /**
     * The source's own state, in the seven words a customer is told.
     */
    public function state(string $source, string $sourceState): CustomerOperationState
    {
        return match ($source) {
            /*
             * A provisioning job. `needs_review` is the engine's own word for
             * "stopped, and a person must look", and it survives as itself.
             */
            'provisioning_job' => match ($sourceState) {
                'queued' => CustomerOperationState::Queued,
                'running' => CustomerOperationState::Processing,
                'succeeded' => CustomerOperationState::Succeeded,
                'failed' => CustomerOperationState::Failed,
                'needs_review' => CustomerOperationState::NeedsReview,
                'cancelled' => CustomerOperationState::Cancelled,
                default => throw new \LogicException(sprintf('Unmapped job status "%s".', $sourceState)),
            },

            /*
             * A registrar operation, and the only branch that can be
             * genuinely unknown. `awaiting_registry` is still in progress —
             * the platform has asked and is waiting — while `indeterminate`
             * means it asked and never heard.
             */
            'domain_operation' => match ($sourceState) {
                'requested', 'queued' => CustomerOperationState::Queued,
                'running', 'awaiting_registry' => CustomerOperationState::Processing,
                'completed' => CustomerOperationState::Succeeded,
                'failed' => CustomerOperationState::Failed,
                'needs_review' => CustomerOperationState::NeedsReview,
                'indeterminate' => CustomerOperationState::Indeterminate,
                default => throw new \LogicException(sprintf('Unmapped domain operation state "%s".', $sourceState)),
            },

            'wordpress_operation' => match ($sourceState) {
                'requested' => CustomerOperationState::Queued,
                'running' => CustomerOperationState::Processing,
                'succeeded' => CustomerOperationState::Succeeded,
                'failed' => CustomerOperationState::Failed,
                'indeterminate' => CustomerOperationState::Indeterminate,
                default => throw new \LogicException(sprintf('Unmapped WordPress operation state "%s".', $sourceState)),
            },

            /*
             * A backup's lifecycle is longer than an operation's, because the
             * row outlives the work: it is taken, verified, possibly restored
             * from, possibly deleted. Only the states that mean "the work is
             * still happening" poll.
             *
             * `verified` and `restored` are both successes, of different
             * things. `deleted` is a success too — the customer asked for it —
             * and `delete_requested` is the grace window, which is processing.
             */
            'backup' => match ($sourceState) {
                'requested' => CustomerOperationState::Queued,
                'running', 'verifying', 'restoring', 'delete_requested', 'deleting' => CustomerOperationState::Processing,
                'succeeded', 'verified', 'restored', 'deleted' => CustomerOperationState::Succeeded,
                'failed' => CustomerOperationState::Failed,
                'needs_review' => CustomerOperationState::NeedsReview,
                default => throw new \LogicException(sprintf('Unmapped backup state "%s".', $sourceState)),
            },

            'file_restore' => match ($sourceState) {
                'requested' => CustomerOperationState::Queued,
                'running' => CustomerOperationState::Processing,
                'succeeded' => CustomerOperationState::Succeeded,
                'failed' => CustomerOperationState::Failed,
                'needs_review' => CustomerOperationState::NeedsReview,
                default => throw new \LogicException(sprintf('Unmapped file restore state "%s".', $sourceState)),
            },

            /*
             * A zone import is synchronous: by the time the row exists the
             * work is over, so the outcome is the state.
             */
            'zone_import' => match ($sourceState) {
                'applied' => CustomerOperationState::Succeeded,
                /*
                 * Both refusals, and both the customer's to act on rather than
                 * support's: `refused` is a file the zone would not accept,
                 * and `plan_changed` is a plan that no longer matched the zone
                 * by the time it was applied — which is the guard against
                 * applying a diff computed against a zone somebody else has
                 * since edited. Re-planning is safe, so neither is a review.
                 */
                'refused', 'plan_changed' => CustomerOperationState::Failed,
                default => throw new \LogicException(sprintf('Unmapped zone import outcome "%s".', $sourceState)),
            },

            /*
             * An order's status after the transition. `manual_review` is a
             * person's queue, not a failure; `refunded` and `terminated` are
             * finished business rather than broken work.
             */
            'order_transition' => match ($sourceState) {
                'draft', 'pending_payment', 'queued_for_provisioning' => CustomerOperationState::Queued,
                'paid', 'provisioning' => CustomerOperationState::Processing,
                'active' => CustomerOperationState::Succeeded,
                'payment_failed', 'provisioning_failed' => CustomerOperationState::Failed,
                'manual_review' => CustomerOperationState::NeedsReview,
                'cancelled' => CustomerOperationState::Cancelled,
                'suspended', 'refunded', 'terminated' => CustomerOperationState::Succeeded,
                default => throw new \LogicException(sprintf('Unmapped order status "%s".', $sourceState)),
            },

            // Both are facts rather than work in progress.
            'invoice_issued', 'invoice_paid' => CustomerOperationState::Succeeded,

            /*
             * A payment that did not go through. `abandoned` is the customer
             * leaving the gateway page rather than a refusal, and both are
             * safe to try again from the invoice — which is what the state
             * says, and why neither is a review.
             */
            'payment_failed' => match ($sourceState) {
                'failed', 'abandoned' => CustomerOperationState::Failed,
                default => throw new \LogicException(sprintf('Unmapped payment attempt status "%s".', $sourceState)),
            },

            /*
             * A support request's status, and the one case where "waiting for
             * the customer" is the whole point: it is the state the dashboard
             * puts in the attention list.
             */
            'support_ticket' => match ($sourceState) {
                'open', 'waiting_for_support' => CustomerOperationState::Processing,
                'waiting_for_customer' => CustomerOperationState::NeedsReview,
                'resolved', 'closed' => CustomerOperationState::Succeeded,
                default => throw new \LogicException(sprintf('Unmapped ticket status "%s".', $sourceState)),
            },

            default => throw new \LogicException(sprintf('Unmapped activity source "%s".', $source)),
        };
    }
}
