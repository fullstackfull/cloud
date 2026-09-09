# Dashboards

Dashboard JSON is provisioned from this directory. There are none committed yet:
a dashboard is a view onto a live series, and building one against a Prometheus
that has never scraped a real target produces a picture of nothing.

The panels that matter, in the order an operator needs them, are recorded in
`docs/monitoring.md`. They become JSON here once a real Prometheus has scraped
a real control plane — see `docs/phase-30b-real-infrastructure-inventory.md`
for why that has not happened yet.
