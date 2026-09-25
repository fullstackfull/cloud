<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Domain\Naming\DnsName;
use Lynomia\Modules\SharedHosting\Application\Handlers\CreateHostingAccountHandler;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingDomainConflictException;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingJobDomainRefusedException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Correct the domain a stopped hosting build will serve.
 *
 * ---------------------------------------------------------------------------
 * Why this is not retry
 * ---------------------------------------------------------------------------
 *
 * A build is refused over its name in two ways: the job names no domain at
 * all (`hosting.domain_missing` — every order placed before checkout asked for
 * one), or it names one another live account already serves
 * (`hosting.domain_in_use`). A retry runs the same job again and is refused
 * the same way, because a retry is not a repair. So the correction is its own
 * act, recorded as such, and the retry that follows it is the ordinary one
 * with its ordinary guards.
 *
 * It writes the job's payload and nothing else: not the status, not the
 * attempts, not the account row. The account row is the reservation's to
 * write, under the node's lock, in the same write as the check that justifies
 * it — see ReserveHostingNodeCapacity.
 *
 * ---------------------------------------------------------------------------
 * The same question the build will ask, asked first
 * ---------------------------------------------------------------------------
 *
 * The name is validated with the platform's host-name rule, folded exactly as
 * the account row requires, and refused if another live account serves it —
 * so an operator is told now, rather than by the next failure. It is a
 * courtesy and not the guarantee: nothing is reserved here, and the build
 * asks again under the lock.
 *
 * The job's OWN rows are excluded from "another account serves it". Without
 * that, correcting a name forward and then back — the sequence the
 * reservation's own refusal (`hosting.account_serves_another_domain`) sends an
 * operator down — was refused on the way back by the job's own pending row,
 * which the build would have accepted. This runs before placement, so it
 * cannot know the node: the job's own rows are every row of this customer's
 * under a username this job derives, by the build's own definition
 * ({@see CreateHostingAccountHandler::usernameFor()}), on any panel.
 *
 * What it does not cover, and says so: a row of this job's that the panel
 * renamed on creation carries the panel's name, not the derived one, and is
 * then counted as another account. The surface refuses; the build would too.
 */
final readonly class NameTheDomainAHostingJobWillServe
{
    /**
     * @return array{job: ProvisioningJob, previous: string|null, domain: string}
     *
     * @throws HostingJobDomainRefusedException
     * @throws HostingDomainConflictException
     */
    public function execute(ProvisioningJob $job, string $domain): array
    {
        $submitted = trim($domain);
        $problem = DnsName::problemWith($submitted);

        if ($problem !== null) {
            throw HostingJobDomainRefusedException::becauseItIsNotAHostName($submitted, $problem);
        }

        $folded = DnsName::canonicalAsSubmitted($submitted);

        return DB::transaction(function () use ($job, $folded): array {
            /** @var ProvisioningJob $locked */
            $locked = ProvisioningJob::query()->lockForUpdate()->findOrFail($job->getKey());

            if ($locked->kind !== ProvisioningJobKind::CreateHostingAccount) {
                throw HostingJobDomainRefusedException::becauseItIsNotAHostingBuild(
                    (string) $locked->getKey(),
                    $locked->kind->value,
                );
            }

            if (! in_array($locked->status, [ProvisioningJobStatus::Failed, ProvisioningJobStatus::NeedsReview], true)) {
                throw HostingJobDomainRefusedException::becauseItHasNotStopped(
                    (string) $locked->getKey(),
                    $locked->status->value,
                );
            }

            $served = HostingAccount::query()
                ->where('primary_domain', $folded)
                ->whereIn('status', ReserveHostingNodeCapacity::statusesThePanelHolds())
                ->whereKeyNot($this->rowsThisJobAlreadyHolds($locked))
                ->exists();

            if ($served) {
                throw HostingDomainConflictException::forDomain($folded);
            }

            /** @var array<string, mixed> $payload */
            $payload = $locked->payload;
            $previous = isset($payload['primary_domain']) ? (string) $payload['primary_domain'] : null;

            $locked->payload = [...$payload, 'primary_domain' => $folded];
            $locked->save();

            return ['job' => $locked, 'previous' => $previous, 'domain' => $folded];
        });
    }

    /**
     * The account rows this job's own earlier attempts left, on any node.
     *
     * @return list<string>
     */
    private function rowsThisJobAlreadyHolds(ProvisioningJob $job): array
    {
        if ($job->customer_id === null) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        $payload = $job->payload;

        $usernames = array_values(array_unique(array_map(
            static fn (HostingPanel $panel): string => CreateHostingAccountHandler::usernameFor(
                $payload,
                (string) $job->getKey(),
                $panel,
            ),
            HostingPanel::cases(),
        )));

        /** @var list<string> $ids */
        $ids = HostingAccount::query()
            ->where('customer_id', $job->customer_id)
            ->whereIn('username', $usernames)
            ->pluck('id')
            ->all();

        return $ids;
    }
}
