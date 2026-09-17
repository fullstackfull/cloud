<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * An operator records which image on a cluster this platform may install.
 *
 * ===========================================================================
 * THE GAP THIS CLOSES, FOUND BY AN ONBOARDING DRY RUN
 * ===========================================================================
 *
 * Gap 8 gave every VPS build an OS image or a refusal: a plan may declare a
 * `template_slug`, the placement resolves it against the cluster, and a build
 * that names no image is refused instead of asking the hypervisor to clone
 * nothing. All of that reads `vm_templates`.
 *
 * Nothing could write `vm_templates`. There was no admin endpoint, no
 * discovery path and no command — only a factory and the browser seeder. So a
 * real cluster onboarded through the Control Center would have had zero
 * images, every VPS order would have been refused with
 * `vps.create_image_unavailable`, and the only ways forward would have been
 * raw SQL or a code change. "Onboarding real infrastructure must require zero
 * code changes for infrastructure this platform already models" is the rule
 * that makes that a blocker rather than a nicety.
 *
 * ===========================================================================
 * WHY THIS IS RECORDED AND NOT DISCOVERED
 * ===========================================================================
 *
 * The hypervisor knows which of its guests are templates. It does not know
 * which of them this platform may sell, what to call them in two languages,
 * or whether the operating system on them needs a licence — and a discovery
 * sweep that answered those questions would be guessing about a product.
 * Somebody who can be asked is the right authority, exactly as they are for a
 * rack or a datacenter.
 *
 * What the platform does check is the half it can: an image with no
 * `provider_reference` is not installable, and `scopeInstallable()` already
 * refuses to place one, so a catalogue entry recorded before the image is
 * staged is a commercial intention rather than a promise.
 *
 * ===========================================================================
 * RECORDING THE SAME SLUG TWICE
 * ===========================================================================
 *
 * `(cluster_id, slug)` is unique, and a second call for the same pair is an
 * operator correcting the row rather than an error: the provider reference
 * changes when an image is rebuilt, and the version changes when it is
 * upgraded in place. So this is an upsert inside a transaction with the row
 * locked, and the audit entry says which of the two happened. An action that
 * answered 409 would leave "fix the reference" with no route through the API
 * at all, which is the gap this exists to close, one level down.
 */
final readonly class RecordVmTemplate
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  array<string, string>  $name  Display names by locale.
     */
    public function execute(
        ComputeCluster $cluster,
        string $slug,
        array $name,
        OsFamily $osFamily,
        string $osVersion,
        CpuArchitecture $architecture,
        ?string $providerReference,
        bool $cloudInit,
        bool $guestAgent,
        bool $requiresLicence,
        ?string $licenceNote,
        User $operator,
    ): VmTemplate {
        return $this->record->execute(
            act: fn (): VmTemplate => DB::transaction(function () use (
                $cluster,
                $slug,
                $name,
                $osFamily,
                $osVersion,
                $architecture,
                $providerReference,
                $cloudInit,
                $guestAgent,
                $requiresLicence,
                $licenceNote,
            ): VmTemplate {
                /*
                 * Locked rather than read: two operators correcting the same
                 * reference in the same minute would otherwise both read
                 * "absent" and race the unique index, and one of them would
                 * get a constraint violation for doing the thing this action
                 * is for.
                 */
                $existing = VmTemplate::query()
                    ->where('cluster_id', $cluster->getKey())
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                $attributes = [
                    'cluster_id' => $cluster->getKey(),
                    'slug' => $slug,
                    'name' => $name,
                    'os_family' => $osFamily,
                    'os_version' => $osVersion,
                    'architecture' => $architecture,
                    'provider_reference' => $providerReference,
                    'cloud_init' => $cloudInit,
                    'guest_agent' => $guestAgent,
                    'requires_licence' => $requiresLicence,
                    'licence_note' => $licenceNote,
                    /*
                     * Recording an image is also how a withdrawn one comes
                     * back. The alternative is an operator who deactivated a
                     * template having no way to offer it again, which is the
                     * same shape of hole as not being able to record one.
                     */
                    'is_active' => true,
                ];

                if ($existing === null) {
                    return VmTemplate::query()->create($attributes);
                }

                $existing->forceFill($attributes)->save();

                return $existing->refresh();
            }),
            describe: fn (VmTemplate $template): AuditedAct => new AuditedAct(
                action: AuditAction::VmTemplateRecorded,
                subject: $template,
                context: [
                    'cluster' => $cluster->slug,
                    'slug' => $slug,
                    'os' => $osFamily->value.' '.$osVersion,
                    'architecture' => $architecture->value,
                    // Whether the image can actually be built from, which is
                    // not the same question as whether the row exists.
                    'installable' => $providerReference !== null && $providerReference !== '',
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
