<?php

declare(strict_types=1);

/*
 | The deployment chain: how the control plane reaches the IaC tree.
 |
 | `driver` names which DeploymentController is bound. `fake` rehearses the
 | chain against markers on a machine's management address and refuses to be
 | built in production; `ansible` shells out to the reviewed playbooks in
 | infrastructure/ansible from the deployment controller host, and refuses to
 | run anywhere that looks like CI. Nothing here makes CI able to apply.
 */
return [
    'controller' => [
        'driver' => env('INFRASTRUCTURE_CONTROLLER', 'fake'),

        // Absolute path of the checked-out infrastructure/ tree on the
        // deployment controller. Left unset, the ansible driver refuses.
        'iac_path' => env('INFRASTRUCTURE_IAC_PATH'),

        // Bounds one playbook run. Past it the run is INDETERMINATE — the
        // playbook may still be changing the machine — and under the Timeout
        // Rule nothing retries it; a person looks.
        'apply_timeout_seconds' => (int) env('INFRASTRUCTURE_APPLY_TIMEOUT_SECONDS', 1800),
        'verify_timeout_seconds' => (int) env('INFRASTRUCTURE_VERIFY_TIMEOUT_SECONDS', 300),
    ],

    // A job that has been applying or verifying longer than this without
    // finishing is marked indeterminate by the sweep, even if the worker that
    // ran it is gone. Slightly longer than the apply timeout so the worker's
    // own deadline fires first when it can.
    'stale_after_seconds' => (int) env('INFRASTRUCTURE_STALE_AFTER_SECONDS', 2100),
];
