<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\BmcNotConfiguredException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedProviderException;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PxeAuthorisationRefusedException;
use Lynomia\Modules\Dedicated\Domain\Services\InstallProfileRenderer;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\OsInstallProfile;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;

/**
 * Grants one machine permission to erase itself and install from the network,
 * then arms the controller to do it once.
 *
 * Everything about this action is shaped by what it authorises. A PXE boot
 * destroys whatever is on the disks, so:
 *
 *  - it refuses outright unless the server is in `provisioning`. `active` is
 *    the case that matters — a stale or mis-addressed job reaching a running
 *    customer server — and `available` matters nearly as much, because a
 *    machine nobody has ordered has no install to run;
 *
 *  - a reason and an authoriser are recorded BEFORE the controller is touched.
 *    When a customer's server is found reinstalled, that row is the difference
 *    between an answer and a shrug, and a row written after the fact is a row
 *    that does not exist when the worker dies in between;
 *
 *  - the permission has a short expiry, so it is a decision rather than a
 *    standing configuration;
 *
 *  - the override is ONE-TIME.
 *
 * **Why one-time and not a boot-order change.** Putting PXE first in the boot
 * order would work, and would keep working: the machine would network boot
 * every single time it powers on. The next unplanned reboot — a power cut, a
 * kernel panic, an engineer pressing the wrong button months later — would
 * silently reinstall the customer's server and erase everything on it. A
 * one-time override is consumed by the boot it arms, so a machine that
 * reboots for any other reason comes back up as it was. The failure mode of
 * one-time is "the install did not start and somebody has to look"; the
 * failure mode of persistent is "the customer's data is gone".
 */
final readonly class AuthorisePxeBoot
{
    private const int FALLBACK_TTL_MINUTES = 60;

    public function __construct(
        private DedicatedProviderFactory $providers,
        private InstallProfileRenderer $renderer,
    ) {}

    /**
     * @param  string  $reason  Why this machine is being reinstalled, in an operator's words.
     *                          Required: the column is not nullable and neither is the decision.
     * @param  array<string, scalar|null>  $variables  Values for the install profile's placeholders.
     *
     * @throws PxeAuthorisationRefusedException
     * @throws BmcNotConfiguredException
     * @throws DedicatedProviderException
     */
    public function execute(
        DedicatedServer $server,
        OsInstallProfile $profile,
        string $reason,
        ?string $authorisedByUserId = null,
        ?string $provisioningJobId = null,
        array $variables = [],
        ?int $ttlMinutes = null,
    ): PxeBootAuthorisation {
        if (! $server->status->permitsNetworkInstall()) {
            throw PxeAuthorisationRefusedException::becauseServerIsNotProvisioning(
                (string) $server->getKey(),
                $server->status,
            );
        }

        if (trim($reason) === '') {
            throw PxeAuthorisationRefusedException::becauseNoReasonWasGiven((string) $server->getKey());
        }

        $mac = $server->provisioningMacAddress();

        if ($mac === null) {
            /*
             * Refused rather than defaulted to a wildcard. DHCP and PXE on the
             * provisioning VLAN answer whoever asks; an authorisation with no
             * MAC would be an authorisation for every machine on that VLAN,
             * including ones mid-install for other customers.
             */
            throw PxeAuthorisationRefusedException::becauseNoMacAddressIsKnown((string) $server->getKey());
        }

        $endpoint = $server->preferredBmcEndpoint();

        if ($endpoint === null) {
            throw BmcNotConfiguredException::noEndpoint((string) $server->getKey());
        }

        $rendered = $this->renderer->render($profile, $variables);

        $ttl = $ttlMinutes ?? (int) config('dedicated.pxe.authorisation_ttl_minutes', self::FALLBACK_TTL_MINUTES);

        /*
         * The record is written first, and committed, before the controller is
         * touched.
         *
         * The order is the point. A worker that dies between arming the BMC
         * and writing the row leaves a machine that will network boot with
         * nothing in the platform explaining why — which is precisely the
         * "surprise reinstall" an audit trail exists to answer. Doing it this
         * way can instead leave an authorisation that was never armed, and an
         * authorisation that is never used simply expires.
         */
        $authorisation = DB::transaction(fn (): PxeBootAuthorisation => PxeBootAuthorisation::query()->create([
            'dedicated_server_id' => $server->getKey(),
            'os_install_profile_id' => $profile->getKey(),
            'provisioning_job_id' => $provisioningJobId,
            'mac_address' => $mac,
            'status' => PxeAuthorisationStatus::Pending,
            'expires_at' => now()->addMinutes(max(1, $ttl)),
            'authorised_by_user_id' => $authorisedByUserId,
            'authorisation_reason' => $reason,
            // Redacted on the way into the column by the model's cast: an
            // answer file legitimately carries a password hash, and this table
            // must not become where the platform keeps customers' credentials.
            'rendered_config' => $rendered,
        ]));

        try {
            $operation = $this->providers->for($endpoint)->setOneTimePxeBoot($endpoint);
        } catch (DedicatedProviderException $e) {
            /*
             * The grant stands as a record either way, but its usability
             * depends on what the controller did.
             *
             * A refusal spoken out loud means nothing was armed, so the
             * permission is withdrawn — leaving it pending would let a later
             * unrelated network boot consume it.
             *
             * A timeout means the controller may well have armed the override.
             * The permission is left standing, because revoking it would tell
             * the boot server to refuse a machine that is about to ask to
             * install, and the install would hang half-done instead of
             * completing. The caller quarantines; nothing here retries.
             */
            if (! $e->isIndeterminate()) {
                $authorisation->revoke();
            }

            throw $e;
        }

        $authorisation->forceFill([
            'rendered_config' => [
                ...$rendered,
                'bmc' => [
                    'endpoint_id' => $operation->endpointId,
                    'protocol' => $operation->protocol->value,
                    ...$operation->metadata,
                ],
            ],
        ])->save();

        return $authorisation;
    }
}
