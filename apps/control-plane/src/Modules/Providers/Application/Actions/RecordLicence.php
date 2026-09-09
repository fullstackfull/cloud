<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Domain\Exceptions\LicenceRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Record a licence Lynomia holds.
 *
 * ---------------------------------------------------------------------------
 * An inventory, with a calendar
 * ---------------------------------------------------------------------------
 *
 * Nothing here talks to a vendor. What is recorded is what was bought, what it
 * covers, and when it lapses — so that "cPanel stopped working" can be answered
 * with "the licence expired on Tuesday" rather than investigated as an outage.
 * The state is computed from the dates at every point they could change and
 * every night in between, and an operator can override the calendar in one
 * direction only: by declaring the vendor rejected it.
 *
 * A licence KEY that is itself sensitive is not stored here. It is a
 * credential reference, and this row points at it.
 */
final readonly class RecordLicence
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws LicenceRefused
     */
    public function execute(
        string $product,
        DeploymentEnvironment $environment,
        User $operator,
        ?string $licenceType = null,
        ?ManagedServer $server = null,
        ?CredentialReference $credential = null,
        ?CarbonImmutable $startsOn = null,
        ?CarbonImmutable $expiresOn = null,
        ?CarbonImmutable $renewsOn = null,
        ?int $seats = null,
        ?string $externalReference = null,
        ?string $notes = null,
    ): Licence {
        if ($server !== null && ! $server->environment->satisfies($environment)) {
            throw LicenceRefused::wrongEnvironment($server->name, $server->environment, $environment);
        }

        if ($credential !== null && ! $credential->environment->satisfies($environment)) {
            throw LicenceRefused::wrongEnvironment($credential->name, $credential->environment, $environment);
        }

        if ($startsOn !== null && $expiresOn !== null && $expiresOn->isBefore($startsOn)) {
            throw LicenceRefused::expiryBeforeStart($product);
        }

        return $this->record->execute(
            act: function () use ($product, $environment, $operator, $licenceType, $server, $credential, $startsOn, $expiresOn, $renewsOn, $seats, $externalReference, $notes): Licence {
                $licence = new Licence([
                    'product' => $product,
                    'licence_type' => $licenceType,
                    'environment' => $environment,
                    'managed_server_id' => $server?->getKey(),
                    'credential_reference_id' => $credential?->getKey(),
                    'starts_on' => $startsOn,
                    'expires_on' => $expiresOn,
                    'renews_on' => $renewsOn,
                    'seats' => $seats,
                    'external_reference' => $externalReference,
                    'notes' => $notes,
                    'created_by' => $operator->getKey(),
                    // Pending until the calendar says otherwise. A licence
                    // recorded with no dates is one somebody has bought and
                    // not yet had confirmed, and that is what Pending means.
                    'state' => LicenceState::Pending,
                ]);

                $licence->state = $licence->stateFromDates();
                $licence->state_changed_at = CarbonImmutable::now();
                $licence->save();

                return $licence;
            },
            describe: fn (Licence $created): AuditedAct => new AuditedAct(
                action: AuditAction::LicenceRecorded,
                subject: $created,
                context: [
                    'product' => $product,
                    'environment' => $environment->value,
                    'state' => $created->state->value,
                    'expires_on' => $expiresOn?->toDateString(),
                    'server' => $server?->name,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
