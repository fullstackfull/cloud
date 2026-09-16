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

    /*
     | The zones this platform's own hosts live under.
     |
     | Both start unset, and that is the decision rather than an omission. A
     | real internal zone is a fact about an operator's network, resolver and
     | certificate authority; a default here would become the naming authority
     | for every deployment that inherited it. Until one is set, the platform
     | stores the hostnames it is given and declines to compose new ones —
     | which it reports, with a next action, rather than guessing.
     |
     | `internal` is for management names the platform reaches (hypervisors,
     | hosting nodes, its own hosts). `public` is for names customers resolve.
     | They are separate because they usually are: the first is often a private
     | zone with a private certificate authority, the second is registered and
     | publicly resolvable, and one value serving both would make every
     | management host a public name or every customer-facing name unroutable.
     |
     | A reference suffix (.example, .test, .invalid, .localhost, or a reserved
     | example domain) is refused for production by
     | Infrastructure\Domain\Naming\DnsSuffix, so a value copied out of the
     | reference topology cannot quietly become the production scheme.
     */
    'naming' => [
        'internal_dns_suffix' => env('INFRASTRUCTURE_INTERNAL_DNS_SUFFIX'),
        'public_dns_suffix' => env('INFRASTRUCTURE_PUBLIC_DNS_SUFFIX'),
    ],

    // A job that has been applying or verifying longer than this without
    // finishing is marked indeterminate by the sweep, even if the worker that
    // ran it is gone. Slightly longer than the apply timeout so the worker's
    // own deadline fires first when it can.
    'stale_after_seconds' => (int) env('INFRASTRUCTURE_STALE_AFTER_SECONDS', 2100),
];
