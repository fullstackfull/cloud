<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

/**
 * Every driver that exists for rehearsal, and what each one can actually do.
 *
 * ===========================================================================
 * WHY THIS IS AN ENUM AND NOT A LIST IN THE CATALOGUE
 * ===========================================================================
 *
 * Because it used to be a list, and the list was a literal:
 *
 *     public function controlledDrivers(): array
 *     {
 *         return ['fake', 'fake_bmc'];
 *     }
 *
 * Nothing was wrong with those two strings. What was wrong is that nothing
 * derived them. Eight mature stateful simulators existed in this repository —
 * compute, hosting, backup, DNS, reverse DNS, registrar, payment and the
 * WordPress half of hosting — and the provider registry knew about two
 * categories. The reference estate declared five provider dependencies and
 * could satisfy two of them, and the reason was not that the simulators were
 * missing. It was that the catalogue had never been told they were there.
 *
 * So the list is now this enum, the catalogue builds its controlled entries
 * from it, the connection tester reads capabilities from it, and an
 * architecture test requires every case to have a simulator and a consumer.
 * A controlled driver cannot exist here without something behind it, and a
 * simulator cannot be reachable through a factory and invisible to the
 * catalogue at the same time.
 *
 * ===========================================================================
 * WHY EACH CASE DECLARES WHAT IT CANNOT DO
 * ===========================================================================
 *
 * The connection tester used to report every capability a category asks about
 * as Supported. For DNS that is true — the DNS simulator implements all four.
 * For a richer category it would be a lie told to the one engine that must
 * never be lied to: the readiness engine reads capability rows before a
 * product is offered for sale.
 *
 * Four capabilities in this platform are asked about by a product requirement
 * and modelled by no interface method anywhere in the repository. They are
 * named in {@see self::unsupported()} with the reason, which is what makes
 * "this product cannot be rehearsed end to end" an exact statement about a
 * missing contract rather than a shrug. Closing one of them is a contract
 * change, and the day it happens this enum is what has to be edited.
 *
 * ===========================================================================
 * WHAT A CONTROLLED DRIVER IS
 * ===========================================================================
 *
 * Deterministic, local, stateful wherever the real contract is stateful,
 * fault-injectable from the request, refused in production by three separate
 * controls, and reached through the same abstractions a real adapter is.
 *
 * It is not a static array returning success, not an always-OK mock, and not a
 * real adapter pointed at localhost. The simulators behind these cases each
 * refuse to be constructed in production, and none of them opens a socket.
 */
enum ControlledDriver: string
{
    /**
     * The original. Catalogued as DNS since Phase 30B-P because rehearsing a
     * remote account does not also require a classified machine of ours, and
     * the name is kept because provider rows carry it.
     */
    case Dns = 'fake';

    /** The machine half of the original pair: a BMC for a machine that does not exist. */
    case Bmc = 'fake_bmc';

    case Compute = 'fake_compute';

    case Hosting = 'fake_hosting';

    case WordPress = 'fake_wordpress';

    case Backup = 'fake_backup';

    case ReverseDns = 'fake_rdns';

    case Registrar = 'fake_registrar';

    case Payment = 'fake_payment';

    public function category(): ProviderCategory
    {
        return match ($this) {
            self::Dns => ProviderCategory::Dns,
            self::Bmc => ProviderCategory::Bmc,
            self::Compute => ProviderCategory::Compute,
            self::Hosting => ProviderCategory::Hosting,
            self::WordPress => ProviderCategory::WordPressInstaller,
            self::Backup => ProviderCategory::Backup,
            self::ReverseDns => ProviderCategory::ReverseDns,
            self::Registrar => ProviderCategory::Registrar,
            self::Payment => ProviderCategory::Payment,
        };
    }

    /**
     * What the catalogue says about this driver, in the catalogue's own voice.
     *
     * Every one of them ends the same way on purpose. An operator reading a
     * list of drivers has to be able to tell at a glance which of them are
     * rehearsal and which are real, without knowing that "fake" is a prefix
     * this codebase happens to use.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Dns => 'A controlled provider for rehearsing the onboarding path. Never available in production.',
            self::Bmc => 'A controlled BMC for rehearsing machine onboarding and discovery. Never available in production.',
            self::Compute => 'A controlled hypervisor for rehearsing the machine lifecycle. Never available in production.',
            self::Hosting => 'A controlled hosting panel for rehearsing the account lifecycle. Never available in production.',
            self::WordPress => 'A controlled WordPress toolkit for rehearsing installs and staging. Never available in production.',
            self::Backup => 'A controlled backup target for rehearsing archives, verification and restores. Never available in production.',
            self::ReverseDns => 'A controlled reverse-DNS account for rehearsing PTR publication. Never available in production.',
            self::Registrar => 'A controlled registrar for rehearsing registration, renewal and transfer. Never available in production.',
            self::Payment => 'A controlled payment gateway for rehearsing authorisation, webhooks and refunds. Never available in production.',
        };
    }

    /**
     * Whether a row for this driver carries an address.
     *
     * True for every case, and not because it is convenient: a controlled row
     * that skipped the endpoint would skip the endpoint policy, which is the
     * control that refuses a rehearsal row pointed at something real. The
     * reference estate's own rows carry `fake://…` addresses for exactly that
     * reason.
     */
    public function needsEndpoint(): bool
    {
        return true;
    }

    /**
     * Whether a row for this driver needs a credential reference.
     *
     * True for every case. Simulation must require zero real credentials, and
     * it does — nothing behind these drivers reads a secret. What the platform
     * still has to rehearse is the credential *architecture*: a provider with
     * no credential reference is blocked on credentials, and that refusal is
     * one of the paths this estate exists to exercise.
     */
    public function needsCredential(): bool
    {
        return true;
    }

    /**
     * Whether using this driver commercially requires a licence.
     *
     * False for every case, including hosting, whose real drivers do need one.
     * A controlled driver is not a licensed product — there is nothing to buy
     * and nobody to buy it from. The licence path is still rehearsed, on the
     * node row: the hosting simulator reads `hosting_nodes.panel_licensed` and
     * reports an expired licence when the row says so.
     */
    public function needsLicence(): bool
    {
        return false;
    }

    /**
     * Capabilities this driver's category asks about that the simulator does
     * not offer, and why.
     *
     * Each of these is a capability named by a product requirement and
     * modelled by no method on any interface in this repository. Not "not
     * written yet in the fake" — not expressible through the contract the fake
     * implements. A simulator that answered Supported would be claiming the
     * platform can do something no code path exists for.
     *
     * @return array<string, string>
     */
    public function unsupported(): array
    {
        return match ($this) {
            self::Compute => [
                'gpu_passthrough' => 'ComputeProvider models no GPU assignment: CreateVmRequest carries no device, '
                    .'ResizeVmRequest cannot add one, and no adapter reads one. The GPU compute product asks for this '
                    .'capability and nothing in the repository can answer it.',
            ],
            default => [],
        };
    }

    /**
     * What a connection test should record for one capability of this driver.
     *
     * Supported unless the case above says otherwise. Never Unknown: a
     * controlled driver has been asked and has answered, and Unknown is
     * reserved for the honest "nobody has looked yet".
     */
    public function stateOf(string $capability): CapabilityState
    {
        return array_key_exists($capability, $this->unsupported())
            ? CapabilityState::Unsupported
            : CapabilityState::Supported;
    }

    /**
     * Every capability of this driver's category that the simulator does
     * offer.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        $unsupported = $this->unsupported();

        return array_values(array_filter(
            $this->category()->capabilities(),
            static fn (string $capability): bool => ! array_key_exists($capability, $unsupported),
        ));
    }

    public static function isControlled(string $driver): bool
    {
        return self::tryFrom($driver) !== null;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $driver): string => $driver->value, self::cases());
    }
}
