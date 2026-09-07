<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use JsonException;
use Lynomia\Modules\SharedHosting\Application\Actions\PreflightHostingNode;
use Lynomia\Modules\SharedHosting\Domain\DTOs\LicenceStatus;
use Lynomia\Modules\SharedHosting\Domain\DTOs\NodePreflightFacts;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;

/**
 * Decides whether a machine may have a control panel installed on it.
 *
 * The check existed and nothing ran it. Every condition it tests produces a
 * node that *looks* installed and is broken in a way discovered later, by
 * customers, on a machine that by then has customers on it — and the fix is a
 * migration rather than a reinstall. A check nobody can run is a check that
 * gets skipped on the day somebody is in a hurry, which is the day it matters.
 *
 * A command rather than an HTTP endpoint, deliberately. The facts it needs are
 * read from the machine itself — /etc/os-release, what is listening, what DNS
 * says about its own name — so this runs where those facts are, as part of the
 * install automation, before anything is installed.
 *
 * Facts arrive as JSON, from the collector that gathers them on the target
 * host. This command does not gather them: a command that both collected and
 * judged would have to run as root on the machine being judged, and would be
 * one place holding both the panel licence key and shell access.
 *
 * The exit code is the answer. 0 to install, 1 to stop — so the automation
 * around it needs no output parsing to do the right thing.
 */
final class PreflightHostingNodeCommand extends Command
{
    protected $signature = 'hosting:preflight
        {panel : cpanel, directadmin, plesk or fake}
        {--facts= : Path to the JSON facts file, or - for standard input}';

    protected $description = 'Check whether a machine may have a hosting panel installed on it';

    public function handle(PreflightHostingNode $preflight): int
    {
        $panel = HostingPanel::tryFrom((string) $this->argument('panel'));

        if ($panel === null) {
            $this->components->error(sprintf(
                'Unknown panel. Expected one of: %s.',
                implode(', ', array_column(HostingPanel::cases(), 'value')),
            ));

            return self::INVALID;
        }

        try {
            $facts = $this->facts();
        } catch (JsonException $e) {
            $this->components->error('The facts could not be read: '.$e->getMessage());

            return self::INVALID;
        }

        $report = $preflight->execute($panel, $facts);

        $this->line((string) json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        if ($report->passed()) {
            $this->components->info(sprintf('%s may be installed on %s.', $panel->value, $facts->hostname));

            return self::SUCCESS;
        }

        foreach ($report->refusals as $refusal) {
            $this->components->error($refusal->summary());
        }

        // Deliberately not SUCCESS-with-warnings. Every refusal this action
        // makes is a refusal; there is no branch that returns a caution.
        return self::FAILURE;
    }

    /**
     * @throws JsonException
     */
    private function facts(): NodePreflightFacts
    {
        $source = (string) ($this->option('facts') ?? '-');

        $raw = $source === '-'
            ? (string) file_get_contents('php://stdin')
            : (string) file_get_contents($source);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return new NodePreflightFacts(
            hostname: (string) ($decoded['hostname'] ?? ''),
            osId: (string) ($decoded['os_id'] ?? ''),
            osVersion: (string) ($decoded['os_version'] ?? ''),
            forwardAddresses: self::strings($decoded['forward_addresses'] ?? []),
            reverseHostnames: self::strings($decoded['reverse_hostnames'] ?? []),
            conflictingServices: self::strings($decoded['conflicting_services'] ?? []),
            boundPorts: array_map(intval(...), self::strings($decoded['bound_ports'] ?? [])),
            licence: self::licence($decoded['licence'] ?? null),
        );
    }

    /**
     * The vendor's answer, or nothing.
     *
     * An absent key means the check could not run, which the action treats
     * differently from "the vendor said no" — a collector that could not reach
     * the licence server must not have its silence read as a valid licence.
     * And `valid` is read strictly: anything that is not exactly true comes
     * back as an unconfirmed licence, because an optimistic default here is a
     * fleet that quietly provisions onto nodes whose licence has lapsed.
     */
    private static function licence(mixed $value): ?LicenceStatus
    {
        if (! is_array($value)) {
            return null;
        }

        $product = is_string($value['product'] ?? null) ? $value['product'] : 'panel';

        if (($value['valid'] ?? null) !== true) {
            return LicenceStatus::unconfirmed(
                $product,
                is_string($value['detail'] ?? null) ? $value['detail'] : 'the vendor did not confirm the licence',
            );
        }

        return new LicenceStatus(
            valid: true,
            product: $product,
            state: is_string($value['state'] ?? null) ? $value['state'] : null,
            expiresAt: is_string($value['expires_at'] ?? null)
                ? CarbonImmutable::parse($value['expires_at'])
                : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), $value));
    }
}
