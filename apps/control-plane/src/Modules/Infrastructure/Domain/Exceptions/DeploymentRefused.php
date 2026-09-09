<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Exceptions;

use RuntimeException;

final class DeploymentRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $code_)
    {
        parent::__construct($message);
    }

    public static function unknownProfile(string $key): self
    {
        return new self(sprintf('There is no %s profile in this build. Profiles are in source and reviewed like code.', $key), 'profile_unknown');
    }

    public static function inactiveProfile(string $key): self
    {
        return new self(sprintf('The %s profile is retired and cannot be assigned.', $key), 'profile_inactive');
    }

    /**
     * @param  list<string>  $offending
     */
    public static function overridesNotAccepted(array $offending): self
    {
        return new self(sprintf(
            'These overrides are not declared by any component in the profile and are refused: %s. '
            .'A playbook takes only the variables its components declare; there is no free-text path to it.',
            implode(', ', $offending),
        ), 'override_refused');
    }

    public static function overrideValueRefused(string $key): self
    {
        return new self(sprintf(
            'The value for %s is refused: overrides are short, printable, and free of shell and template syntax.',
            $key,
        ), 'override_refused');
    }

    public static function noDesiredState(string $server): self
    {
        return new self(sprintf('%s has no desired state. Assign a profile before planning.', $server), 'no_desired_state');
    }

    public static function noPlan(string $server): self
    {
        return new self(sprintf('%s has no plan. Plan before approving or deploying.', $server), 'no_plan');
    }

    public static function planNotApplicable(string $server): self
    {
        return new self(sprintf('The current plan for %s has blockers or no changes, so there is nothing to approve.', $server), 'plan_not_applicable');
    }

    public static function planSuperseded(): self
    {
        return new self('This plan is no longer the current plan for its machine. Plan again and approve what is current.', 'plan_superseded');
    }

    public static function approvedByPlanner(): self
    {
        return new self('A plan is not approved by the person who planned it. Two people, or none.', 'four_eyes');
    }

    public static function notApproved(string $server, string $fingerprint): self
    {
        return new self(sprintf(
            '%s has no standing approval for its current plan (%s). Any earlier approval was for a plan that has since changed.',
            $server,
            substr($fingerprint, 0, 12),
        ), 'not_approved');
    }

    public static function fingerprintMismatch(): self
    {
        return new self('The approval covers a different plan from the one about to run. Refused; nothing was started.', 'fingerprint_mismatch');
    }

    public static function alreadyInFlight(string $server): self
    {
        return new self(sprintf('%s already has a deployment in flight. One at a time, per machine.', $server), 'in_flight');
    }

    public static function unresolved(string $server): self
    {
        return new self(sprintf(
            '%s has a deployment waiting for a person (indeterminate or needs review). Nothing runs on it until that one is resolved; '
            .'a playbook that may be half-way through a machine is not retried by starting another.',
            $server,
        ), 'unresolved');
    }

    public static function notWaiting(string $state): self
    {
        return new self(sprintf('Only a deployment that is waiting for a person can be resolved; this one is %s.', $state), 'not_waiting');
    }

    public static function notCancellable(string $state): self
    {
        return new self(sprintf('A deployment that is %s cannot be cancelled.', $state), 'not_cancellable');
    }

    public static function controllerRefused(string $reason): self
    {
        return new self($reason, 'controller_refused');
    }
}
