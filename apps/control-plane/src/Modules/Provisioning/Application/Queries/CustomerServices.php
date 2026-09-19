<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerServiceState;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * The one place a service query is scoped to a customer.
 *
 * Every read on the customer surface starts here, so what a controller holds
 * is already a relation hanging off the acting customer rather than a global
 * `Service::query()` somebody has to remember to constrain. An unscoped
 * service is therefore never in hand: `->whereKey($id)->firstOrFail()` on this
 * relation 404s for another tenant's id instead of fetching the row and
 * relying on a check that runs afterwards — by which point the row, its plan
 * and its provisioning history have already been read.
 *
 * The events endpoint inherits the same property for free, because it reaches
 * the jobs through `$service->jobs()` on a service that was fetched this way.
 *
 * This belongs on Customer as a `services()` relation. It lives here instead
 * because the Identity module is owned elsewhere; the relation object built by
 * `$customer->hasMany(Service::class)` is exactly what that method would
 * return, so moving it later is a one-line change at this single call site.
 *
 * ---------------------------------------------------------------------------
 * Why the subquery counts jobs in review rather than reading the latest one
 * ---------------------------------------------------------------------------
 *
 * The state a customer is shown depends on the provisioning jobs as well as on
 * the service row, and loading them per service is an N+1 on a list endpoint;
 * a subquery column keeps the page one query and lets `?state=` be answered in
 * SQL rather than after pagination, which would silently return short pages.
 *
 * What that subquery asks is the part that matters. It asks whether ANY job on
 * the service is still waiting on a person — not what the most recent job did.
 * A service accumulates jobs: a build, then a reboot, then a suspension. If a
 * build stops in `needs_review` and anything at all happens to the service
 * afterwards, "the latest job" is no longer the stuck one, and a service that
 * nobody is working on goes back to reporting `provisioning` — which is
 * precisely the lie `under_review` exists to stop telling. A job leaves
 * `needs_review` only by a person deciding something (adopt, give up, or
 * requeue by hand — see ProvisioningJobStateMachine), so the existence of one
 * is exactly the question "is somebody still owed a decision here?".
 */
final class CustomerServices
{
    /**
     * The alias the count of jobs still waiting on a person is selected under.
     *
     * Public because the resource reads it back off the model; there is no
     * relation to hang it on, so the name is the contract between the two.
     */
    public const string REVIEWS_PENDING = 'jobs_awaiting_review_count';

    /**
     * The same question narrowed to the job that delivered the service.
     *
     * A separate column rather than a flag on the first, because the two
     * answers eclipse different states: any stuck job hides a build in
     * progress, and only a stuck *delivery* job is allowed to talk over an
     * active service. See {@see CustomerServiceState::isEclipsedByDeliveryReview()}.
     */
    public const string DELIVERY_REVIEWS_PENDING = 'delivery_jobs_awaiting_review_count';

    /**
     * @return HasMany<Service, Customer>
     */
    public static function of(Customer $customer): HasMany
    {
        /** @var HasMany<Service, Customer> $relation */
        $relation = $customer->hasMany(Service::class)
            ->select('services.*')
            // A count rather than a raw `exists`: it is portable, its bindings
            // are the query builder's rather than a string this class pasted
            // together, and it comes back as a number under every driver
            // instead of a boolean each one spells differently.
            ->withCount([
                'jobs as '.self::REVIEWS_PENDING => self::awaitingReview(...),
                'jobs as '.self::DELIVERY_REVIEWS_PENDING => self::awaitingDeliveryReview(...),
            ]);

        return $relation;
    }

    /**
     * Narrows a scoped query to one customer-facing state.
     *
     * Two predicates, because the published state is not the status column.
     * A service with a job waiting on a person reads `under_review` whatever
     * its status says, so `?state=provisioning` has to exclude those rows or
     * the list would disagree with every row it printed.
     *
     * `whereDoesntHave` is what makes a service with no jobs at all behave:
     * `not exists (...)` is true for it, so a service nothing has ever been
     * done to still appears under its own state.
     *
     * @param  HasMany<Service, Customer>  $query
     * @return HasMany<Service, Customer>
     */
    public static function inState(HasMany $query, CustomerServiceState $state): HasMany
    {
        $query->whereIn('services.status', array_map(
            static fn (ServiceStatus $status): string => $status->value,
            $state->underlyingStatuses(),
        ));

        if ($state === CustomerServiceState::UnderReview) {
            /*
             * Either kind of stuck job, but only where that kind is allowed to
             * eclipse the row's status: an active service is `under_review`
             * for a stuck delivery and not for a stuck reboot, so asking for
             * "any stuck job" here would return active machines whose only
             * problem was a failed restart — rows the response prints as
             * `active`.
             */
            $query->where(function (Builder $scoped): void {
                $scoped
                    ->where(function (Builder $anyReview): void {
                        $anyReview
                            ->whereIn('services.status', self::statusesEclipsedBy(
                                static fn (CustomerServiceState $state): bool => $state->isEclipsedByReview(),
                            ))
                            ->whereHas('jobs', self::awaitingReview(...));
                    })
                    ->orWhere(function (Builder $deliveryReview): void {
                        $deliveryReview
                            ->whereIn('services.status', self::statusesEclipsedBy(
                                static fn (CustomerServiceState $state): bool => $state->isEclipsedByDeliveryReview(),
                            ))
                            ->whereHas('jobs', self::awaitingDeliveryReview(...));
                    });
            });
        } elseif ($state->isEclipsedByDeliveryReview()) {
            /*
             * The wider exclusion, for every state a delivery review can hide.
             * `?state=active` must not return a machine the response prints as
             * `under_review`, and for the states that any review eclipses the
             * delivery subset is covered by the same clause.
             */
            $query->whereDoesntHave('jobs', self::awaitingDeliveryReview(...));

            if ($state->isEclipsedByReview()) {
                $query->whereDoesntHave('jobs', self::awaitingReview(...));
            }
        }

        return $query;
    }

    /**
     * Jobs no worker will touch again until a person has decided something.
     *
     * One definition, used by the counted column and by both branches of the
     * filter, so the word the list prints and the word it filters on cannot
     * come to mean different things.
     *
     * @param  Builder<ProvisioningJob>  $query
     */
    private static function awaitingReview(Builder $query): void
    {
        $query->where('provisioning_jobs.status', ProvisioningJobStatus::NeedsReview->value);
    }

    /**
     * Stuck jobs that were delivering the service rather than operating it.
     *
     * "Delivering" is not a new idea here: `createsResource()` already names
     * the three kinds that bring a service into existence, and it is the same
     * property the engine uses to decide whether an unclassified failure might
     * have left something behind at the provider.
     *
     * @param  Builder<ProvisioningJob>  $query
     */
    private static function awaitingDeliveryReview(Builder $query): void
    {
        self::awaitingReview($query);

        $query->whereIn('provisioning_jobs.kind', array_map(
            static fn (ProvisioningJobKind $kind): string => $kind->value,
            array_values(array_filter(
                ProvisioningJobKind::cases(),
                static fn (ProvisioningJobKind $kind): bool => $kind->createsResource(),
            )),
        ));
    }

    /**
     * The service statuses whose customer-facing word a review may replace.
     *
     * Derived from the enum rather than listed again, so the filter and the
     * serialiser cannot come to disagree about which states a review hides.
     *
     * @param  callable(CustomerServiceState): bool  $eclipses
     * @return list<string>
     */
    private static function statusesEclipsedBy(callable $eclipses): array
    {
        $statuses = [];

        foreach (ServiceStatus::cases() as $status) {
            if ($eclipses(CustomerServiceState::for($status))) {
                $statuses[] = $status->value;
            }
        }

        return $statuses;
    }
}
