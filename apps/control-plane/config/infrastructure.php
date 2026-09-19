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
     | hosting nodes, its own hosts).
     |
     | There was a second key, `public_dns_suffix`, for names customers
     | resolve. Nothing ever read it. No approved product composes a
     | customer-facing hostname from a platform suffix — a VPS is named for its
     | own service id, and a hosting account, a WordPress site and a DNS zone
     | all carry the customer's own domain — so the key was a setting an
     | operator could fill in and change nothing by. It is gone rather than
     | documented as prepared: a control plane whose settings do not all do
     | something teaches operators that some of them might not, and the day a
     | product does compose such a name, the key comes back with the consumer
     | that needs it.
     |
     | A reference suffix (.example, .test, .invalid, .localhost, or a reserved
     | example domain) is refused for production by
     | Infrastructure\Domain\Naming\DnsSuffix, so a value copied out of the
     | reference topology cannot quietly become the production scheme.
     */
    'naming' => [
        'internal_dns_suffix' => env('INFRASTRUCTURE_INTERNAL_DNS_SUFFIX'),
    ],

    // A job that has been applying or verifying longer than this without
    // finishing is marked indeterminate by the sweep, even if the worker that
    // ran it is gone. Slightly longer than the apply timeout so the worker's
    // own deadline fires first when it can.
    'stale_after_seconds' => (int) env('INFRASTRUCTURE_STALE_AFTER_SECONDS', 2100),
];
