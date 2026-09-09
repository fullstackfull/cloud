# Runbooks

One file per thing that goes wrong. Each answers, in order: what you are seeing,
what it means, what to check first, what to do, and what not to do.

They are written for an operator who did not build the system and is reading
this at an unsociable hour. A system only its author can repair is not
production ready.

Every alert in `infrastructure/monitoring/prometheus/rules/` names one of these files in
its `runbook` annotation, and `infrastructure/scripts/validate-monitoring.py` fails the
build if an alert points at a file that does not exist.

## The rule that applies to all of them

An operation whose outcome is unknown is never retried automatically, and never
retried by you until you have looked at the provider. The platform calls this
the Timeout Rule. "It probably failed" has created two VMs, two registrations
and two charges before.
