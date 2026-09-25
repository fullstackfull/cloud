# Runbooks

One file per thing that goes wrong. Each answers, in order: what you are seeing,
what it means, what to check first, what to do, and what not to do.

They are written for an operator who did not build the system and is reading
this at an unsociable hour. A system only its author can repair is not
production ready.

<!-- counts:begin -->
<!-- Written by `validate-runbook-alerts.py --write` from the tree, and compared on every run. Do not edit by hand. -->
- **31** files here: **30** pages and this README.
- **66** alerts are defined in `infrastructure/monitoring/prometheus/rules/`.
- **48** of them name a page here with `runbook:`; **18** name no page here.
- **8** of the 30 pages are named by no alert, and are listed under *Pages with no alert here* with the reason.
<!-- counts:end -->

Those numbers are derived from the tree by
`infrastructure/scripts/validate-runbook-alerts.py` and compared on every run;
`--write` rewrites them. They used to be typed by hand, and the hand-typed count
said 55 alerts when the rules defined 64 — internally consistent, which is why
nobody noticed. The first batch of these pages was written in Phase 30B, because
`infrastructure/scripts/validate-monitoring.py` found seventeen alerts pointing
at runbooks nobody had written.

Two conventions coexist in the alert rules, and both are checked. An alert may
carry a `runbook` — a path in this repository, which must exist — or a
`runbook_url`, which points at the internal documentation site and is taken on
trust. `validate-monitoring.py` fails the build for an alert
with neither, and for a `runbook` path that does not resolve.

`infrastructure/scripts/validate-runbooks.py` additionally checks every
`php artisan` line on these pages against the commands the application actually
defines. It found ten invented commands the first time it ran.

## Naming an alert on these pages

A backticked UpperCamelCase word of eight or more characters, with at least two
capitalised segments, is read as the name of an alert, and
`validate-runbook-alerts.py` fails the build unless a rule file defines it. That
is the check in the other direction from `validate-monitoring.py`'s, and it
exists because fifteen alert names had been written on these pages that no rule
defined: pages describing warnings that could never arrive.

When no alert sends anybody to a page, the page says so in words — nothing will
page you, which series exists, and that no rule reads it — rather than naming
an alert that would be convenient.

A word of that shape that is genuinely not an alert, such as a test class
mentioned in prose, is declared on the page that writes it, next to where it is
written: `<!-- not-an-alert: Word - what it actually is -->`, with a reason of at
least twelve characters. The declaration is refused if the page does not cite
the word, if the word is a defined alert, or if nothing outside `docs/` names it
— an invented alert name exists only in prose, and calling it something else
does not make it real.

## Alerts with no page here

These are the alerts counted above as naming no page here. They rely on
`runbook_url` alone — an internal documentation site nothing here can check.
They are listed so the gap is a known one rather than a surprise at 4am:

| Alerts | Subject | Why there is no page yet |
| --- | --- | --- |
| `DatabaseUnavailable`, `DatabaseExporterMissing` | PostgreSQL is down | `database-restore.md` covers a restore, which is a different and far more destructive thing. A database that is merely down needs its own page, and writing one against a deployment that does not exist would be guesswork |
| `NodeCpuSaturated`, `NodeMemoryHigh`, `NodeMemoryCritical` | Host saturation | Generic host trouble; the response depends on what the host runs |
| `NodeDiskAlmostFull`, `NodeDiskWillFillIn4Hours` | Disk pressure on a platform host | `hosting-panel-unavailable.md` covers the hosting-node case, which is the one with a customer-data trap in it |
| `FilesystemReadOnly`, `RaidArrayDegraded`, `SmartHealthFailing` | Failing storage hardware | Hardware replacement, and no hardware exists to write it against |
| `EndpointDown`, `EndpointSlow`, `ProbeFailingWithHttpError` | Blackbox probes | |
| `CertificateExpiringSoon`, `CertificateExpiringCritical`, `CertificateExpired` | TLS expiry | |
| `RevenueRecognitionStalled` | The billing run | |
| `ComputeNodeCapacityExhausted` | Capacity planning | A purchasing signal rather than an incident; `node-unavailable.md` says why |

## Pages with no alert here

The other direction: pages that no alert names with `runbook:`, so nothing will
ever send you to them. `validate-runbook-alerts.py` derives this set from the
rule files and compares it with the list below, so a new page with no alert
fails the build until it has a row saying why, and a row whose page an alert
has started to name fails until the row goes.

A **Gap.** is a condition the platform exports a series for and no rule reads.
No rule was written for these on purpose: a threshold nobody has ever watched
fire is a guess, and a guessed threshold with a guessed duration reads as
coverage while being unfired YAML. Writing one is a decision for somebody who
can watch it fire against a real deployment. A **Procedure.** is something a
person starts, not something that happens to them.

- `database-restore.md` — **Procedure.** A restore is a decision taken after data loss; no series says "restore now".
- `deploy-lynomia.md` — **Procedure.** A deploy is started by a person, on purpose.
- `rollback-lynomia.md` — **Procedure.** Started by a person, on a judgement about a release.
- `dns-outage.md` — **Gap.** No rule reads the `lynomia_dns_*` series the control plane exports.
- `pbs-unavailable.md` — **Gap.** Nothing alerts on the backup server as such. Its failure reaches you as backup alerts, which send you to `backup-failure.md`; that page sends you here when many services fail at once.
- `registrar-timeout.md` — **Gap.** `lynomia_domain_operations_total` is exported and no rule reads it.
- `scheduler-stale.md` — **Gap.** `lynomia_scheduled_command_last_success_timestamp_seconds` and `lynomia_scheduled_command_consecutive_failures` are exported and no rule reads either.
- `wordpress-provisioning-stuck.md` — **Gap.** `lynomia_wordpress_sites_total` is exported and no rule reads it.

## The rule that applies to all of them

An operation whose outcome is unknown is never retried automatically, and never
retried by you until you have looked at the provider. The platform calls this
the Timeout Rule. "It probably failed" has created two VMs, two registrations
and two charges before.
