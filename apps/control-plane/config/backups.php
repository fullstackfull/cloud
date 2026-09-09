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

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | `retention_days` is the platform's own floor, used for a service whose
    | plan says nothing about backups. A plan that does say something overrides
    | it — see BackupPolicy — because a retention window is part of what a
    | customer bought, and things a customer bought belong in the catalogue
    | rather than in a deployment's environment.
    |
    | `deletion_attempts` bounds how many times the sweep will ask a provider
    | to remove an archive and find it still there. A row that exhausts it goes
    | to needs-review rather than looping: an archive that will not delete is a
    | datastore filling up, and that is a person's problem, not a retry's.
    |
    | `grace_hours` is the gap between a customer asking for a backup to go and
    | the sweep acting on it. Deleting a backup is irreversible and a mis-click
    | is common; an hour of hesitation costs a little datastore space and has
    | saved a customer's only copy more than once.
    |
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 7),

    'deletion_attempts' => (int) env('BACKUP_DELETION_ATTEMPTS', 5),

    'deletion_grace_hours' => (int) env('BACKUP_DELETION_GRACE_HOURS', 1),

    /*
    |--------------------------------------------------------------------------
    | File-level access
    |--------------------------------------------------------------------------
    |
    | A download link lives for `file_download_ttl_seconds` and is used once:
    | long enough for the browser that asked for it to follow it, and not
    | long enough to be worth forwarding. `file_download_max_bytes` bounds
    | what one link streams; anything larger is restored to the machine,
    | where it belongs, rather than pulled through the control plane.
    |
    | `file_restore_max_paths` bounds one restore request. A directory counts
    | as one path and brings back everything under it.
    |
    */

    'file_download_ttl_seconds' => (int) env('BACKUP_FILE_DOWNLOAD_TTL_SECONDS', 300),

    'file_download_max_bytes' => (int) env('BACKUP_FILE_DOWNLOAD_MAX_BYTES', 64 * 1024 * 1024),

    'file_restore_max_paths' => (int) env('BACKUP_FILE_RESTORE_MAX_PATHS', 50),

];
