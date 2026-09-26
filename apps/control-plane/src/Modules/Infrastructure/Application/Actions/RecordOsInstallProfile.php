<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Dedicated\Domain\Enums\InstallerKind;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\InstallProfileRefused;

/**
 * An operator records a recipe a Dedicated build installs from.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 *
 * ProvisionDedicatedHandler resolves an OsInstallProfile before it arms a
 * boot, and until this action nothing but a factory wrote one: no route,
 * command, seeder or migration. An operator could rack a machine, record its
 * controller, sell it and take the money, and the build had nothing to
 * install (F-02). This is that row's production writer.
 *
 * ---------------------------------------------------------------------------
 * Create, never rewrite
 * ---------------------------------------------------------------------------
 *
 * The template is the answer file that decides how a customer's disks are
 * partitioned, and a change to it has to be reviewable and attributable. So a
 * slug is written once. A corrected recipe is a new profile under a new slug,
 * and the old one is withdrawn (WithdrawOsInstallProfile) rather than edited:
 * a machine installed from the old one keeps pointing at what it was actually
 * built with. The audit entry carries a SHA-256 of the template, so which text
 * a profile held is answerable from the trail without copying the text into
 * it.
 *
 * ---------------------------------------------------------------------------
 * A profile a build cannot render is refused here
 * ---------------------------------------------------------------------------
 *
 * The renderer refuses a template with an unfilled placeholder, which is
 * right at render time and too late: the build has reserved a machine by then.
 * A build supplies {@see InstallProfileRenderer::PLATFORM_VARIABLES}, and an
 * order names nothing else, so every other placeholder must have a default
 * here. The same renderer's pattern is what finds them.
 */
final readonly class RecordOsInstallProfile
{
    public function __construct(
        private RecordActAtomically $record,
        private InstallProfileRenderer $renderer,
    ) {}

    /**
     * @param  array<string, string>  $name
     * @param  array<string, scalar|null>  $defaults
     *
     * @throws InstallProfileRefused
     */
    public function execute(
        string $slug,
        array $name,
        string $osFamily,
        string $osVersion,
        InstallerKind $installer,
        string $template,
        array $defaults,
        User $operator,
    ): OsInstallProfile {
        $supplied = [
            ...InstallProfileRenderer::PLATFORM_VARIABLES,
            ...array_keys(array_filter($defaults, static fn (mixed $value): bool => $value !== null)),
        ];

        $uncovered = array_values(array_diff($this->renderer->placeholdersIn($template), $supplied));

        if ($uncovered !== []) {
            throw InstallProfileRefused::becauseNothingSupplies($slug, $uncovered, InstallProfileRenderer::PLATFORM_VARIABLES);
        }

        return $this->record->execute(
            act: fn (): OsInstallProfile => OsInstallProfile::query()->create([
                'slug' => $slug,
                'name' => $name,
                'os_family' => $osFamily,
                'os_version' => $osVersion,
                'installer' => $installer,
                'template' => $template,
                'defaults' => $defaults === [] ? null : $defaults,
                'is_active' => true,
            ]),
            describe: fn (OsInstallProfile $profile): AuditedAct => new AuditedAct(
                action: AuditAction::OsInstallProfileRecorded,
                subject: $profile,
                context: [
                    'slug' => $profile->slug,
                    'installer' => $installer->value,
                    'os' => $osFamily.' '.$osVersion,
                    'template_sha256' => hash('sha256', $template),
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
