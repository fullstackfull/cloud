<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Providers\Domain\Contracts\SecretResolver;
use Lynomia\Modules\Providers\Domain\Enums\CredentialState;
use Lynomia\Modules\Providers\Domain\Exceptions\CredentialRefused;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Record that a credential exists, and where.
 *
 * ---------------------------------------------------------------------------
 * What is and is not stored
 * ---------------------------------------------------------------------------
 *
 * The reference: the name of a variable in the deployment controller's process
 * environment. Never the value. This action cannot store a value because it
 * has no column to put one in, which is a stronger guarantee than a rule.
 *
 * The one check on the reference's shape is there to catch the mistake that
 * matters: somebody pasting the secret into the reference field. A value looks
 * nothing like an identifier, so the refusal is cheap, and its message says to
 * rotate the secret — because by then it has been in a request body, and a
 * request body is logged in more places than anyone remembers.
 *
 * ---------------------------------------------------------------------------
 * Missing versus configured
 * ---------------------------------------------------------------------------
 *
 * The initial state is decided by asking the backend whether it holds anything
 * behind the reference — not what. Recorded and present is Configured;
 * recorded and absent is Missing, which is the honest state of a credential
 * somebody has declared before the controller has been given it. Neither is
 * Valid: that takes a successful connection test.
 */
final readonly class RecordCredentialReference
{
    public const array BACKENDS = ['controller_environment'];

    private const string REFERENCE_SHAPE = '/^[A-Z][A-Z0-9_]{2,127}$/';

    public function __construct(
        private SecretResolver $secrets,
        private RecordActAtomically $record,
    ) {}

    /**
     * @throws CredentialRefused
     */
    public function execute(
        string $name,
        string $purpose,
        DeploymentEnvironment $environment,
        string $backend,
        string $reference,
        User $operator,
        ?string $maskedHint = null,
        ?CarbonImmutable $rotatesAt = null,
        ?string $notes = null,
    ): CredentialReference {
        if (! in_array($backend, self::BACKENDS, true)) {
            throw CredentialRefused::unknownBackend($backend);
        }

        if (preg_match(self::REFERENCE_SHAPE, $reference) !== 1) {
            throw CredentialRefused::referenceLooksLikeAValue();
        }

        $present = $this->secrets->exists($backend, $reference);

        return $this->record->execute(
            act: fn (): CredentialReference => CredentialReference::create([
                'name' => $name,
                'purpose' => $purpose,
                'environment' => $environment,
                'backend' => $backend,
                'backend_reference' => $reference,
                'state' => $present ? CredentialState::Configured : CredentialState::Missing,
                // At most the last four characters of a PUBLIC identifier, and
                // only what the operator chose to give. Never derived from the
                // secret, which this action has never seen.
                'masked_hint' => $maskedHint === null ? null : mb_substr($maskedHint, -4),
                'rotates_at' => $rotatesAt,
                'notes' => $notes,
                'created_by' => $operator->getKey(),
            ]),
            describe: fn (CredentialReference $created): AuditedAct => new AuditedAct(
                action: AuditAction::CredentialRecorded,
                subject: $created,
                context: [
                    'credential' => $created->name,
                    'purpose' => $purpose,
                    'environment' => $environment->value,
                    'backend' => $backend,
                    // The state, not the reference. An audit row is read by
                    // more people than may know where secrets live.
                    'state' => $created->state->value,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
