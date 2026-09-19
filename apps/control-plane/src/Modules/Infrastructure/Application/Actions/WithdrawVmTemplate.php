<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Stop offering an image, without forgetting it.
 *
 * Deactivated rather than deleted, and the difference matters to machines that
 * already exist: `virtual_machines.template_id` points here, so a delete would
 * either cascade the history away or null the column on every server built
 * from the image — and "which image is this machine running?" is the first
 * question asked when a customer reports something odd after a rebuild.
 *
 * `scopeInstallable()` already filters on `is_active`, so withdrawing an
 * image removes it from placement and from the customer's reinstall list in
 * the same move. Nothing else has to be told.
 */
final readonly class WithdrawVmTemplate
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(VmTemplate $template, User $operator): VmTemplate
    {
        return $this->record->execute(
            act: function () use ($template): VmTemplate {
                $template->forceFill(['is_active' => false])->save();

                return $template->refresh();
            },
            describe: fn (VmTemplate $withdrawn): AuditedAct => new AuditedAct(
                action: AuditAction::VmTemplateWithdrawn,
                subject: $withdrawn,
                context: [
                    'slug' => $withdrawn->slug,
                    'cluster_id' => (string) ($withdrawn->cluster_id ?? ''),
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
