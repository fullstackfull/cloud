<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where each cluster's backups are written
    |--------------------------------------------------------------------------
    |
    | Keyed by the cluster's credentials reference, or its slug when it has
    | none — the same key compute credentials use, so an operator configures
    | one name per cluster rather than two.
    |
    | There is deliberately no default. A provider asked to back up without a
    | storage will write to whichever it considers default, and on a hypervisor
    | that is frequently the same disks the machine is running on: a copy, not
    | a backup, and lost by exactly the failure a backup exists for. A cluster
    | with nothing here cannot be backed up, and says so.
    |
    | The datastore itself — its disks, its prune schedule, its verification
    | job and its restore test — is infrastructure, declared in the Ansible
    | inventory and applied by the `pbs` role. Nothing here creates or
    | configures one.
    |
    */

    'datastores' => [
        // 'kw-cluster' => 'pbs-kw-01',
    ],

    /*
    |--------------------------------------------------------------------------
    | How long to wait on the backup API
    |--------------------------------------------------------------------------
    |
    | Longer than the compute default. A vzdump is accepted in milliseconds,
    | but listing a datastore holding thousands of archives is not, and a
    | timeout on a read is reported as indeterminate — which quarantines a row
    | and sends an operator to look at a datastore that was merely busy.
    |
    */

    'request_timeout_seconds' => (int) env('BACKUP_REQUEST_TIMEOUT_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | A backup is followed by polling the provider's task. These bound how
    | long the platform keeps asking before it stops and asks a person.
    |
    | `max_poll_hours` is not a timeout on the backup: the provider keeps
    | running whatever it is running. It is the point at which the platform
    | admits it has lost track and moves the row to needs_review, which is the
    | only outcome that gets a human to look.
    |
    */

    'poll_interval_seconds' => (int) env('BACKUP_POLL_INTERVAL_SECONDS', 30),

    'max_poll_hours' => (int) env('BACKUP_MAX_POLL_HOURS', 12),

];
