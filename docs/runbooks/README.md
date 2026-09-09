# Runbooks

One file per thing that goes wrong. Each answers, in order: what you are seeing,
what it means, what to check first, what to do, and what not to do.

They are written for an operator who did not build the system and is reading
this at an unsociable hour. A system only its author can repair is not
production ready.

Twenty-two files. Three predate Phase 30B; the rest arrived with it, because
`infrastructure/scripts/validate-monitoring.py` found seventeen alerts pointing
at runbooks nobody had written.

Two conventions coexist in the alert rules, and both are checked. An alert may
carry a `runbook` — a path in this repository, which must exist — or a
`runbook_url`, which points at the internal documentation site and is taken on
trust. The validator fails the build for an alert with neither, and for a
`runbook` path that does not resolve. The 55 alerts that predate this phase use
`runbook_url`; the newer ones point here.

`infrastructure/scripts/validate-runbooks.py` additionally checks every
`php artisan` line on these pages against the commands the application actually
defines. It found ten invented commands the first time it ran.

## Alerts with no page here

37 of the 55 alerts now name a runbook in this repository. Eighteen do not, and
rely on `runbook_url` alone — an internal documentation site nothing here can
check. They are listed so the gap is a known one rather than a surprise at 4am:

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

## The rule that applies to all of them

An operation whose outcome is unknown is never retried automatically, and never
retried by you until you have looked at the provider. The platform calls this
the Timeout Rule. "It probably failed" has created two VMs, two registrations
and two charges before.
