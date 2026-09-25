<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Contracts\Foundation\Application;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Shared\Domain\Exceptions\EndpointRefused;
use Lynomia\Modules\Shared\Domain\Services\EndpointPolicy;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A panel server, written down.
 *
 * Created with nothing claimed about it: no licence state, no usage, no sync
 * time, and an account count of zero. Every one of those is a statement the
 * platform is only entitled to make after it has asked the panel, and the
 * reconciler is what asks. A row that arrives already saying `panel_licensed`
 * would be a row asserting something nobody checked — and the licence state is
 * exactly what decides whether accounts may be placed here.
 */
final readonly class RegisterHostingNode
{
    public function __construct(
        private RecordActAtomically $record,
        private EndpointPolicy $endpoints,
        private Application $app,
    ) {}

    /**
     * @throws EndpointRefused
     */
    public function execute(
        Datacenter $datacenter,
        string $slug,
        string $hostname,
        HostingPanel $panel,
        ?string $apiEndpoint,
        bool $verifyTls,
        ?string $credentialsReference,
        ?int $maxAccounts,
        User $operator,
    ): HostingNode {
        if ($apiEndpoint !== null && trim($apiEndpoint) !== '') {
            $this->endpoints->assertProviderEndpoint(
                $apiEndpoint,
                controlledDriver: $panel === HostingPanel::Fake,
                onOurHardware: true,
                production: $this->app->environment('production'),
            );
        } elseif ($panel !== HostingPanel::Fake) {
            /*
             * No API endpoint means the hostname is what gets dialled: the
             * panel connections build `https://{hostname}:{port}` for a row
             * that names no endpoint, and send the panel's root token to it.
             * So the hostname is asked about as the machine address it then
             * is. The edit road asks the same question
             * (InventoryController::updateHostingNode). A fake panel dials
             * nothing.
             */
            $this->endpoints->assertMachineAddress($hostname, production: $this->app->environment('production'));
        }

        return $this->record->execute(
            act: fn (): HostingNode => HostingNode::query()->create([
                'datacenter_id' => $datacenter->getKey(),
                'slug' => $slug,
                'hostname' => $hostname,
                'panel' => $panel,
                'api_endpoint' => $apiEndpoint,
                'verify_tls' => $verifyTls,
                'credentials_reference' => $credentialsReference,
                'status' => HostingNodeStatus::Active,
                'accepts_new_accounts' => true,
                // Nothing has asked the panel anything yet.
                'panel_licensed' => false,
                'account_count' => 0,
                'max_accounts' => $maxAccounts,
            ]),
            describe: fn (HostingNode $node): AuditedAct => new AuditedAct(
                action: AuditAction::HostingNodeRegistered,
                subject: $node,
                context: [
                    'slug' => $node->slug,
                    'hostname' => $node->hostname,
                    'panel' => $panel->value,
                    'datacenter' => $datacenter->slug,
                    'credentials_reference' => $credentialsReference,
                    'operator' => (string) $operator->getKey(),
                ],
            ),
        );
    }
}
