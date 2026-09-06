# Disaster recovery

## Objectives

These are commitments, not aspirations. Each is only real once it has been demonstrated by
a rehearsal — an untested recovery procedure is a hypothesis.

| Scope | RPO (data loss) | RTO (time to restore) |
|---|---|---|
| Control-plane database | 5 minutes (WAL archiving) | 1 hour |
| Control-plane application | 0 (stateless, rebuilt from git) | 30 minutes |
| Customer VMs | 24 hours (nightly PBS) | 4 hours per VM |
| Hosting accounts | 24 hours | 4 hours per node |
| Monitoring stack | 24 hours | 2 hours (configuration is in git) |

A dedicated server's operating system is **not** backed up by the platform. Customers own
their own data on dedicated hardware, and that is stated in the service description rather
than assumed.

## What "recovered" means

The platform is recovered when:

1. The database is restored and consistent.
2. The control plane serves the API and the portals.
3. Queue workers are consuming, and no provisioning job is stuck mid-flight.
4. **Reconciliation has been run**, so the database's view of every provider matches
   reality.

Step 4 is the one that gets forgotten. A restore to a point five minutes before an outage
leaves the platform believing in resources that were created in those five minutes and
knowing nothing about them. Reconciliation finds those orphans; skipping it means they are
never billed, never monitored and never destroyed.

## Scenario: control-plane database lost

1. Provision a new PostgreSQL host from the Ansible role.
2. Restore the most recent base backup, then replay WAL to the latest available point.
3. Verify: row counts on `orders`, `invoices`, `services`, `ip_assignments` against the
   last known-good monitoring snapshot.
4. Point the control plane at the restored database and bring it up **with workers
   stopped**.
5. Run reconciliation against every provider before starting workers. Starting workers
   first means jobs act on a stale view.
6. Start workers. Watch the failed-job rate for the first ten minutes.
7. Reconcile payments: any provider capture with no matching transaction is a payment the
   restored database has forgotten. The reconciliation runbook covers this.

## Scenario: control-plane application host lost

The application is stateless. Rebuild from git, restore the environment file from the
secret store, bring it up. Nothing to recover beyond configuration.

The one thing that is not stateless: anything written to local disk that was not also sent
elsewhere. Logs shipped to Loki survive; logs only on that disk do not.

## Scenario: a Proxmox node lost

1. Confirm it is genuinely down and not merely unreachable from the monitoring host. Two
   independent paths before declaring it dead — acting on a network partition as if it
   were a node failure is how split-brain starts.
2. Mark the node `OFFLINE` so the scheduler stops placing new machines on it.
3. For each VM on it: restore from PBS to a healthy node.
4. Reassign IP addresses to the restored machines. The assignments are in the platform
   database, not on the node.
5. Notify affected customers, with an incident linked to the node.

Restore time is dominated by disk throughput. A node holding many large VMs is a multi-hour
recovery, which is why per-node VM density is a capacity decision and not just an
efficiency one.

## Scenario: PBS lost

Customer VMs keep running — PBS is not in the data path. But the platform is running
without recoverability until it is rebuilt, which is a higher-severity state than it looks.

1. Rebuild PBS from the Ansible role.
2. Restore the datastore from off-site copies.
3. Re-run verification jobs across the restored datastore. A datastore that has not been
   verified since restore is not yet a backup.
4. Until verification completes, treat every VM as unbacked and say so on the status page.

## Scenario: an entire site lost

This is the scenario the platform does not currently claim to survive. There is no
multi-site failover, and the documentation must not imply one.

What exists: off-site backup copies, infrastructure defined in git, and a documented
rebuild path. What that means honestly is a rebuild measured in days, not a failover
measured in minutes.

Do not claim high availability anywhere — in documentation, in marketing, or to a
customer — for anything that has not been configured and tested as HA.

## Rehearsal schedule

| Rehearsal | Frequency |
|---|---|
| Database restore into a scratch environment | Monthly |
| Single VM restore from PBS | Monthly |
| Control-plane rebuild from git | Quarterly |
| Full site rebuild, on paper | Annually |

Record the actual elapsed time of every rehearsal. If it exceeds the RTO above, the RTO is
wrong and the table gets corrected — not the other way round.
