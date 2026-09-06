"""Generates the provisioned Grafana dashboards.

Written as a generator rather than by hand so that the five dashboards share one
definition of "what a panel looks like": same legend placement, same datasource
uid, same tooltip mode. Hand-written dashboard JSON drifts panel by panel until
two graphs of the same thing disagree about what the axis means.

Run:  python3 gen_dashboards.py <output-dir>
"""

import json
import sys
import pathlib

PROM = {"type": "prometheus", "uid": "lynomia-prometheus"}
LOKI = {"type": "loki", "uid": "lynomia-loki"}

_panel_id = [0]


def _next_id():
    _panel_id[0] += 1
    return _panel_id[0]


def target(expr, legend=None, ds=PROM, instant=False, ref="A"):
    t = {"refId": ref, "expr": expr, "datasource": ds}
    if legend:
        t["legendFormat"] = legend
    if instant:
        t["instant"] = True
        t["range"] = False
    else:
        t["range"] = True
    return t


def targets(*exprs):
    out = []
    for i, item in enumerate(exprs):
        ref = chr(ord("A") + i)
        if isinstance(item, tuple):
            out.append(target(item[0], item[1], ref=ref))
        else:
            out.append(target(item, ref=ref))
    return out


def timeseries(title, description, exprs, gridPos, unit="short", fill=10,
               thresholds=None, stack=False, min_=None, max_=None):
    defaults = {
        "unit": unit,
        "color": {"mode": "palette-classic"},
        "custom": {
            "drawStyle": "line",
            "lineWidth": 1,
            "fillOpacity": fill,
            "gradientMode": "none",
            "showPoints": "never",
            "spanNulls": False,
            "stacking": {"mode": "normal" if stack else "none", "group": "A"},
            "axisPlacement": "auto",
            "axisSoftMin": 0,
        },
        "mappings": [],
    }
    if min_ is not None:
        defaults["min"] = min_
    if max_ is not None:
        defaults["max"] = max_
    if thresholds:
        defaults["thresholds"] = {"mode": "absolute", "steps": thresholds}
    return {
        "id": _next_id(),
        "type": "timeseries",
        "title": title,
        "description": description,
        "datasource": PROM,
        "gridPos": gridPos,
        "targets": targets(*exprs),
        "fieldConfig": {"defaults": defaults, "overrides": []},
        "options": {
            "legend": {"displayMode": "table", "placement": "bottom",
                       "showLegend": True, "calcs": ["lastNotNull", "max"]},
            "tooltip": {"mode": "multi", "sort": "desc"},
        },
    }


def stat(title, description, exprs, gridPos, unit="short", steps=None,
         text_mode="auto", graph_mode="none", decimals=None):
    defaults = {
        "unit": unit,
        "mappings": [],
        "color": {"mode": "thresholds"},
        "thresholds": {"mode": "absolute",
                       "steps": steps or [{"color": "green", "value": None}]},
    }
    if decimals is not None:
        defaults["decimals"] = decimals
    ts = [target(e[0], e[1], instant=True, ref=chr(ord("A") + i)) if isinstance(e, tuple)
          else target(e, instant=True, ref=chr(ord("A") + i))
          for i, e in enumerate(exprs)]
    return {
        "id": _next_id(),
        "type": "stat",
        "title": title,
        "description": description,
        "datasource": PROM,
        "gridPos": gridPos,
        "targets": ts,
        "fieldConfig": {"defaults": defaults, "overrides": []},
        "options": {
            "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": False},
            "orientation": "auto",
            "textMode": text_mode,
            "colorMode": "value",
            "graphMode": graph_mode,
            "justifyMode": "auto",
        },
    }


def table(title, description, exprs, gridPos, unit="short", steps=None):
    ts = [target(e[0], e[1], instant=True, ref=chr(ord("A") + i)) if isinstance(e, tuple)
          else target(e, instant=True, ref=chr(ord("A") + i))
          for i, e in enumerate(exprs)]
    return {
        "id": _next_id(),
        "type": "table",
        "title": title,
        "description": description,
        "datasource": PROM,
        "gridPos": gridPos,
        "targets": ts,
        "transformations": [
            {"id": "labelsToFields", "options": {"mode": "columns"}},
            {"id": "organize", "options": {"excludeByName": {"Time": True, "__name__": True,
                                                             "job": True, "instance": False}}},
        ],
        "fieldConfig": {
            "defaults": {
                "unit": unit,
                "custom": {"align": "auto", "cellOptions": {"type": "auto"}},
                "thresholds": {"mode": "absolute",
                               "steps": steps or [{"color": "text", "value": None}]},
                "mappings": [],
            },
            "overrides": [],
        },
        "options": {"showHeader": True, "footer": {"show": False}},
    }


def logs(title, description, expr, gridPos):
    return {
        "id": _next_id(),
        "type": "logs",
        "title": title,
        "description": description,
        "datasource": LOKI,
        "gridPos": gridPos,
        "targets": [{"refId": "A", "expr": expr, "datasource": LOKI, "queryType": "range"}],
        "options": {
            "showTime": True,
            "showLabels": False,
            "wrapLogMessage": True,
            "prettifyLogMessage": False,
            "sortOrder": "Descending",
            "enableLogDetails": True,
            "dedupStrategy": "none",
        },
    }


def row(title, gridPos):
    return {"id": _next_id(), "type": "row", "title": title, "gridPos": gridPos,
            "collapsed": False, "panels": []}


def dashboard(uid, title, description, panels, tags, refresh="30s", time_from="now-6h",
              templating=None):
    return {
        "uid": uid,
        "title": title,
        "description": description,
        "tags": tags,
        "timezone": "browser",
        "editable": False,
        "graphTooltip": 1,
        "schemaVersion": 39,
        "version": 1,
        "refresh": refresh,
        "time": {"from": time_from, "to": "now"},
        "timepicker": {"refresh_intervals": ["10s", "30s", "1m", "5m", "15m", "1h"]},
        "templating": {"list": templating or []},
        "annotations": {"list": [{
            "builtIn": 1,
            "datasource": {"type": "grafana", "uid": "-- Grafana --"},
            "enable": True,
            "hide": True,
            "iconColor": "rgba(0, 211, 255, 1)",
            "name": "Annotations & Alerts",
            "type": "dashboard",
        }]},
        "panels": panels,
    }


UP_STEPS = [{"color": "red", "value": None}, {"color": "green", "value": 1}]
RATIO_STEPS = [{"color": "green", "value": None},
               {"color": "yellow", "value": 0.8},
               {"color": "red", "value": 0.9}]


def build_platform():
    p = []
    p.append(row("Availability", {"h": 1, "w": 24, "x": 0, "y": 0}))
    p.append(stat("Control plane", "Scrape health of the control plane's own metrics endpoint. When this is red every business panel below is stale rather than zero.",
                  [("min(up{job=\"control-plane\"})", "up")],
                  {"h": 4, "w": 4, "x": 0, "y": 1}, steps=UP_STEPS))
    p.append(stat("PostgreSQL", "The exporter's own connection attempt. Red here is a total outage: nothing in the platform works without it.",
                  [("min(pg_up)", "up")], {"h": 4, "w": 4, "x": 4, "y": 1}, steps=UP_STEPS))
    p.append(stat("Redis", "Queue backend. Red means no provisioning job is being dispatched, so paid orders sit untouched.",
                  [("min(redis_up)", "up")], {"h": 4, "w": 4, "x": 8, "y": 1}, steps=UP_STEPS))
    p.append(stat("Nodes reporting", "Machines currently answering scrapes, out of the number configured.",
                  [("sum(up{job=\"node\"})", "up"), ("count(up{job=\"node\"})", "configured")],
                  {"h": 4, "w": 4, "x": 12, "y": 1}))
    p.append(stat("Provisioning success (30m)", "Succeeded as a share of everything that reached a terminal state. needs_review counts as a failure here: from the customer's side it is one.",
                  [("platform:provisioning_success:ratio30m", "success")],
                  {"h": 4, "w": 4, "x": 16, "y": 1}, unit="percentunit",
                  steps=[{"color": "red", "value": None}, {"color": "yellow", "value": 0.85},
                         {"color": "green", "value": 0.95}]))
    p.append(stat("Queue depth", "Every queue combined. The per-queue breakdown below is the one to act on.",
                  [("platform:queue_depth:sum", "jobs")], {"h": 4, "w": 4, "x": 20, "y": 1},
                  graph_mode="area",
                  steps=[{"color": "green", "value": None}, {"color": "yellow", "value": 250},
                         {"color": "red", "value": 2000}]))

    p.append(row("Work in flight", {"h": 1, "w": 24, "x": 0, "y": 5}))
    p.append(timeseries("Queue depth by queue",
                        "Pending jobs per queue. Depth alone is not a problem; depth that keeps climbing is. Compare the slope against the worker count, not against a threshold.",
                        [("lynomia_queue_depth", "{{queue}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 6}))
    p.append(timeseries("Provisioning outcomes (30m delta)",
                        "Jobs reaching a terminal state, by kind and outcome. A failure concentrated in one kind names the broken subsystem: a template, a token, an exhausted pool.",
                        [("platform:provisioning_outcomes:delta30m", "{{kind}} / {{status}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 6}, stack=True))
    p.append(timeseries("Jobs entering failed_jobs",
                        "Jobs that exhausted their retries in the last hour. Read one exception before retrying the batch — a job that will fail identically on retry only starves the healthy ones behind it.",
                        [("increase(lynomia_failed_jobs_total[1h])", "failed jobs/h")],
                        {"h": 8, "w": 12, "x": 0, "y": 14}))
    p.append(timeseries("Provisioning jobs by state",
                        "A snapshot, not a rate. needs_review is the one to watch: nothing retries it and nothing releases its resources, by design.",
                        [("sum by (status) (lynomia_provisioning_jobs_total)", "{{status}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 14}))

    p.append(row("Data stores", {"h": 1, "w": 24, "x": 0, "y": 22}))
    p.append(timeseries("PostgreSQL connections",
                        "Backends per database against max_connections. A pool at its limit is indistinguishable from an outage from the application's side, and restarting makes it worse.",
                        [("sum by (datname) (pg_stat_database_numbackends)", "{{datname}}"),
                         ("pg_settings_max_connections", "max_connections")],
                        {"h": 8, "w": 8, "x": 0, "y": 23}))
    p.append(timeseries("Longest running transaction",
                        "An open transaction holds locks and blocks autovacuum. A long one during provisioning is usually a lockForUpdate that never committed.",
                        [("max(pg_stat_activity_max_tx_duration)", "seconds")],
                        {"h": 8, "w": 8, "x": 8, "y": 23}, unit="s"))
    p.append(timeseries("Redis memory and evictions",
                        "Eviction on the queue instance is a data-loss event, not a tuning matter: an evicted job payload is a paid order that will never be provisioned and never reported as failed.",
                        [("redis_memory_used_bytes", "used"),
                         ("redis_memory_max_bytes", "max"),
                         ("rate(redis_evicted_keys_total[5m]) * 300", "evicted / 5m")],
                        {"h": 8, "w": 8, "x": 16, "y": 23}, unit="bytes"))

    p.append(row("Edge", {"h": 1, "w": 24, "x": 0, "y": 31}))
    p.append(timeseries("External probe duration",
                        "Measured from outside the application, through DNS, the load balancer and TLS — the path a customer actually takes.",
                        [("probe_duration_seconds{job=\"blackbox-http\"}", "{{service}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 32}, unit="s"))
    p.append(timeseries("Payment webhook failure ratio (1h)",
                        "Each failure is a payment the platform has not recorded: a customer charged, with an order still showing unpaid.",
                        [("business:webhook_failure:ratio1h", "{{provider}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 32}, unit="percentunit", max_=1))

    p.append(row("The monitoring itself", {"h": 1, "w": 24, "x": 0, "y": 40}))
    p.append(timeseries("Metrics collection time",
                        "How long each collector took. Cheapness is a claim that has to be measurable: a collector creeping from milliseconds to seconds is invisible until the scrape times out, and by then the endpoint installed to detect outages is causing one.",
                        [("lynomia_metrics_collect_duration_seconds", "{{collector}}")],
                        {"h": 7, "w": 16, "x": 0, "y": 41}, unit="s"))
    p.append(stat("Collectors healthy",
                  "A collector that throws does not fail the scrape; its metrics are absent instead, and absence cannot be alerted on. This is the series that can be.",
                  [("sum(lynomia_metrics_collector_up)", "up"),
                   ("count(lynomia_metrics_collector_up)", "total")],
                  {"h": 7, "w": 8, "x": 16, "y": 41}))

    p.append(row("Logs", {"h": 1, "w": 24, "x": 0, "y": 48}))
    p.append(logs("Control plane errors",
                  "Errors and worse from the structured JSON log. Correlation IDs are structured metadata, so clicking request_id in a line pulls up every other line from the same request.",
                  '{service="control-plane", level=~"ERROR|CRITICAL|ALERT|EMERGENCY"}',
                  {"h": 10, "w": 24, "x": 0, "y": 49}))
    return dashboard("lynomia-platform", "Platform overview",
                     "Control plane health: queues, provisioning outcomes, data stores, edge latency and errors.",
                     p, ["lynomia", "platform"])


def build_proxmox():
    p = []
    p.append(row("Cluster", {"h": 1, "w": 24, "x": 0, "y": 0}))
    p.append(stat("Nodes online", "PVE's own view. A node the cluster has fenced cannot run a guest even if its node_exporter is answering.",
                  [("sum(pve_up{id=~\"node/.*\"})", "online"),
                   ("count(pve_up{id=~\"node/.*\"})", "total")],
                  {"h": 4, "w": 5, "x": 0, "y": 1}))
    p.append(stat("Storages online", "A storage PVE cannot reach stalls every guest with a disk on it and fails every backup to it.",
                  [("sum(pve_up{id=~\"storage/.*\"})", "online"),
                   ("count(pve_up{id=~\"storage/.*\"})", "total")],
                  {"h": 4, "w": 5, "x": 5, "y": 1}))
    p.append(stat("Guests", "Every VM and container the cluster knows about.",
                  [("count(pve_guest_info)", "guests")], {"h": 4, "w": 4, "x": 10, "y": 1}))
    p.append(stat("Worst CPU allocation", "The most committed node in the cluster on CPU. When this reaches 1, the scheduler has nowhere left to place.",
                  [("max(lynomia_node_capacity_ratio{dimension=\"cpu\"})", "ratio")],
                  {"h": 4, "w": 5, "x": 14, "y": 1}, unit="percentunit", steps=RATIO_STEPS))
    p.append(stat("Worst memory allocation", "Memory is the dimension that runs out first on a VPS platform, because it cannot be oversubscribed the way CPU can.",
                  [("max(lynomia_node_capacity_ratio{dimension=\"memory\"})", "ratio")],
                  {"h": 4, "w": 5, "x": 19, "y": 1}, unit="percentunit", steps=RATIO_STEPS))

    p.append(row("Per node", {"h": 1, "w": 24, "x": 0, "y": 5}))
    p.append(timeseries("CPU utilisation",
                        "Reported by the hypervisor, across all cores. Sustained saturation reaches customers as steal time long before anything errors.",
                        [("instance:node_cpu_utilisation:ratio5m{role=\"hypervisor\"}", "{{instance}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 6}, unit="percentunit", max_=1))
    p.append(timeseries("Memory utilisation",
                        "MemAvailable-based, not MemFree: free memory on a busy host is near zero by design because the page cache uses it.",
                        [("instance:node_memory_utilisation:ratio{role=\"hypervisor\"}", "{{instance}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 6}, unit="percentunit", max_=1))
    p.append(timeseries("Filesystem usage",
                        "Hypervisors alert at 88% used rather than the usual 90: below that, thin volumes stop growing and running guests pause rather than error.",
                        [("instance:node_filesystem_usage:ratio{role=\"hypervisor\"}", "{{instance}} {{mountpoint}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 14}, unit="percentunit", max_=1))
    p.append(timeseries("Network throughput",
                        "Per-node traffic. A single guest saturating an uplink degrades every other guest on the node.",
                        [("sum by (instance) (rate(node_network_receive_bytes_total{role=\"hypervisor\", device!~\"lo|veth.*|fw.*|tap.*\"}[5m]))", "{{instance}} rx"),
                         ("sum by (instance) (rate(node_network_transmit_bytes_total{role=\"hypervisor\", device!~\"lo|veth.*|fw.*|tap.*\"}[5m]))", "{{instance}} tx")],
                        {"h": 8, "w": 12, "x": 12, "y": 14}, unit="Bps"))

    p.append(row("Allocation versus reality", {"h": 1, "w": 24, "x": 0, "y": 22}))
    p.append(table("Allocated capacity by node",
                   "What the control plane has committed, not what the hypervisor is using. The scheduler places against this number, so this is the one that decides whether the next order can be filled.",
                   [("lynomia_node_capacity_ratio", "{{node}} / {{dimension}}")],
                   {"h": 9, "w": 12, "x": 0, "y": 23}, unit="percentunit", steps=RATIO_STEPS))
    p.append(timeseries("Allocated versus used memory",
                        "A persistent gap between the two means the plans are sold larger than customers use — safe to oversubscribe further. The two converging means the opposite, urgently.",
                        [("sum(lynomia_node_capacity_ratio{dimension=\"memory\"}) / count(lynomia_node_capacity_ratio{dimension=\"memory\"})", "mean allocated"),
                         ("avg(instance:node_memory_utilisation:ratio{role=\"hypervisor\"})", "mean used")],
                        {"h": 9, "w": 12, "x": 12, "y": 23}, unit="percentunit", max_=1))
    return dashboard("lynomia-proxmox", "Proxmox",
                     "Hypervisor cluster: node and storage availability, saturation, and allocated versus installed capacity.",
                     p, ["lynomia", "compute"])


def build_hosting():
    p = []
    p.append(row("Fleet", {"h": 1, "w": 24, "x": 0, "y": 0}))
    p.append(stat("Nodes reachable", "Every account on an unreachable node is offline — websites, mailboxes and databases together.",
                  [("sum(up{job=\"node\", role=\"hosting\"})", "up"),
                   ("count(up{job=\"node\", role=\"hosting\"})", "total")],
                  {"h": 4, "w": 6, "x": 0, "y": 1}))
    p.append(stat("Active hosting services", "Billed shared-hosting services, from the control plane rather than from the panel.",
                  [("sum(lynomia_services_active{kind=\"shared_hosting\"})", "services")],
                  {"h": 4, "w": 6, "x": 6, "y": 1}))
    p.append(stat("Fullest node", "The platform stops placing new accounts at 80%. Past that, one runaway mailbox breaks every site on the box.",
                  [("max(lynomia_hosting_node_disk_ratio)", "ratio")],
                  {"h": 4, "w": 6, "x": 12, "y": 1}, unit="percentunit",
                  steps=[{"color": "green", "value": None}, {"color": "yellow", "value": 0.75},
                         {"color": "red", "value": 0.85}]))
    p.append(stat("Highest load average", "5-minute load. On a shared node this is the number customers feel as a slow site.",
                  [("max(node_load5{role=\"hosting\"})", "load5")],
                  {"h": 4, "w": 6, "x": 18, "y": 1},
                  steps=[{"color": "green", "value": None}, {"color": "yellow", "value": 8},
                         {"color": "red", "value": 16}]))

    p.append(row("Per node", {"h": 1, "w": 24, "x": 0, "y": 5}))
    p.append(timeseries("Disk usage — control plane's view",
                        "What placement decides on. If this disagrees with the node_exporter panel beside it, the node's sync into the control plane has stalled and placement is working from stale data.",
                        [("lynomia_hosting_node_disk_ratio", "{{node}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 6}, unit="percentunit", max_=1))
    p.append(timeseries("Disk usage — node_exporter's view",
                        "Ground truth from the machine itself, per filesystem.",
                        [("instance:node_filesystem_usage:ratio{role=\"hosting\"}", "{{instance}} {{mountpoint}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 6}, unit="percentunit", max_=1))
    p.append(timeseries("Load average",
                        "1, 5 and 15 minute load. A node whose 15-minute load never comes down is oversubscribed, not busy.",
                        [("node_load1{role=\"hosting\"}", "{{instance}} 1m"),
                         ("node_load5{role=\"hosting\"}", "{{instance}} 5m"),
                         ("node_load15{role=\"hosting\"}", "{{instance}} 15m")],
                        {"h": 8, "w": 12, "x": 0, "y": 14}))
    p.append(timeseries("Memory utilisation",
                        "Shared nodes swap before they OOM, and swapping is what customers experience as a site that intermittently times out.",
                        [("instance:node_memory_utilisation:ratio{role=\"hosting\"}", "{{instance}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 14}, unit="percentunit", max_=1))

    p.append(row("Provisioning", {"h": 1, "w": 24, "x": 0, "y": 22}))
    p.append(timeseries("Hosting account provisioning outcomes",
                        "Failures here are usually a panel licence, a full node, or a DNS zone that already exists — all of which look identical from the order.",
                        [("sum by (status) (delta(lynomia_provisioning_jobs_total{kind=\"create_hosting_account\"}[30m]))", "{{status}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 23}, stack=True))
    p.append(logs("Shared hosting log",
                  "Control plane lines from the shared-hosting channel.",
                  '{service="control-plane"} |= "hosting"',
                  {"h": 8, "w": 12, "x": 12, "y": 23}))
    return dashboard("lynomia-hosting", "Shared hosting",
                     "Shared hosting fleet: node health, disk, load and account provisioning.",
                     p, ["lynomia", "hosting"])


def build_dedicated():
    p = []
    p.append(row("Reachability", {"h": 1, "w": 24, "x": 0, "y": 0}))
    p.append(stat("Servers responding", "ICMP is all the platform has: the customer owns the operating system and there is no agent to ask.",
                  [("sum(probe_success{job=\"blackbox-icmp\"})", "up"),
                   ("count(probe_success{job=\"blackbox-icmp\"})", "total")],
                  {"h": 4, "w": 6, "x": 0, "y": 1}))
    p.append(stat("Active dedicated services", "Billed dedicated services, from the control plane.",
                  [("sum(lynomia_services_active{kind=\"dedicated\"})", "services")],
                  {"h": 4, "w": 6, "x": 6, "y": 1}))
    p.append(stat("Awaiting provisioning", "Dedicated provisioning is measured in hours, not seconds — racking, PXE, an OS install. A number here is normal; a number that never moves is not.",
                  [("sum(lynomia_provisioning_jobs_total{kind=\"provision_dedicated\", status=~\"queued|running\"})", "jobs")],
                  {"h": 4, "w": 6, "x": 12, "y": 1}))
    p.append(stat("Awaiting review", "Where a provisioning timeout lands. Nothing retries these and nothing releases their hardware until a human decides.",
                  [("sum(lynomia_provisioning_jobs_total{kind=\"provision_dedicated\", status=\"needs_review\"})", "jobs")],
                  {"h": 4, "w": 6, "x": 18, "y": 1},
                  steps=[{"color": "green", "value": None}, {"color": "yellow", "value": 1}]))

    p.append(row("Per server", {"h": 1, "w": 24, "x": 0, "y": 5}))
    p.append(timeseries("ICMP reachability",
                        "A customer rebooting or reinstalling their own machine produces exactly this shape, which is why the alert waits fifteen minutes before saying anything.",
                        [("probe_success{job=\"blackbox-icmp\"}", "{{instance}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 6}, max_=1))
    p.append(timeseries("Round-trip time",
                        "Latency creeping up across every server at once is a network problem; on one server it is that server.",
                        [("probe_duration_seconds{job=\"blackbox-icmp\"}", "{{instance}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 6}, unit="s"))

    p.append(row("Hardware", {"h": 1, "w": 24, "x": 0, "y": 14}))
    p.append(table("SMART health",
                   "From the smartmon textfile collector, where the BMC and the OS expose it. The only storage signal that arrives before the data is at risk rather than after.",
                   [("smartmon_device_smart_healthy", "{{instance}} {{disk}}")],
                   {"h": 8, "w": 12, "x": 0, "y": 15},
                   steps=[{"color": "red", "value": None}, {"color": "green", "value": 1}]))
    p.append(timeseries("Dedicated provisioning outcomes",
                        "A spike of failures across several machines at once is a PXE, DHCP or install-image problem, not a hardware one.",
                        [("sum by (status) (delta(lynomia_provisioning_jobs_total{kind=\"provision_dedicated\"}[6h]))", "{{status}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 15}, stack=True))
    return dashboard("lynomia-dedicated", "Dedicated servers",
                     "Dedicated fleet: reachability, hardware health where the BMC exposes it, and provisioning state.",
                     p, ["lynomia", "dedicated"], time_from="now-24h")


def build_business():
    p = []
    p.append(row("Revenue", {"h": 1, "w": 24, "x": 0, "y": 0}))
    p.append(stat("MRR", "Recurring revenue from active subscriptions, converted from minor units once in a recording rule. KWD has three decimal places, which is exactly the kind of thing a per-panel conversion gets wrong.",
                  [("business:mrr_major:sum", "{{currency}}")],
                  {"h": 5, "w": 6, "x": 0, "y": 1}, decimals=3, graph_mode="area"))
    p.append(stat("Active services", "Everything currently billable, across every product.",
                  [("sum(business:services_active:sum)", "services")],
                  {"h": 5, "w": 6, "x": 6, "y": 1}, graph_mode="area"))
    p.append(stat("Failed payments (24h)", "Distinguish a gateway problem from genuine declines: a spike concentrated in one method is ours, a spread across all of them usually is not.",
                  [("increase(lynomia_failed_payments_total[24h])", "failures")],
                  {"h": 5, "w": 6, "x": 12, "y": 1},
                  steps=[{"color": "green", "value": None}, {"color": "yellow", "value": 10},
                         {"color": "red", "value": 25}]))
    p.append(stat("Provisioning failure rate (30m)", "The number that climbs from 1% to 8% hours before support tickets say anything.",
                  [("1 - platform:provisioning_success:ratio30m", "failure rate")],
                  {"h": 5, "w": 6, "x": 18, "y": 1}, unit="percentunit",
                  steps=[{"color": "green", "value": None}, {"color": "yellow", "value": 0.05},
                         {"color": "red", "value": 0.15}]))

    p.append(row("Demand", {"h": 1, "w": 24, "x": 0, "y": 6}))
    p.append(timeseries("Active services by product",
                        "Where growth actually is. Divergence between products is the earliest signal that a plan is mispriced or a product is quietly dying.",
                        [("business:services_active:sum", "{{kind}}")],
                        {"h": 8, "w": 12, "x": 0, "y": 7}, stack=True))
    p.append(timeseries("Orders by state",
                        "queued_for_provisioning is the one that matters: those customers have paid and have nothing yet.",
                        [("sum by (status) (lynomia_orders_total{status=~\"pending_payment|paid|queued_for_provisioning|provisioning|manual_review|provisioning_failed\"})", "{{status}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 7}))
    p.append(timeseries("New orders (1h delta)",
                        "Orders reaching a terminal state per hour. Compare the shape against the provisioning failure rate above — demand falling while failures rise is customers giving up, not demand falling.",
                        [("sum(delta(lynomia_orders_total{status=~\"active|cancelled|refunded\"}[1h]))", "orders/h")],
                        {"h": 8, "w": 12, "x": 0, "y": 15}))
    p.append(timeseries("Payment webhook events",
                        "Failures here are money moving without being recorded. Total silence for hours is equally worth investigating: either nobody is buying, or the endpoint is unreachable.",
                        [("clamp_min(sum by (status) (delta(lynomia_webhook_events_total[1h])), 0)", "{{status}}")],
                        {"h": 8, "w": 12, "x": 12, "y": 15}, stack=True))

    p.append(row("Capacity as a business constraint", {"h": 1, "w": 24, "x": 0, "y": 23}))
    p.append(table("IP pool runway",
                   "Days of address space left at the rate addresses are actually being consumed. Acquiring more is an RIR justification measured in weeks, so days — not percent free — is the unit the decision is made in. A pool with no measured consumption reports +Inf.",
                   [("lynomia_ip_pool_runway_days", "{{pool}}")],
                   {"h": 8, "w": 8, "x": 0, "y": 24}, unit="d",
                   steps=[{"color": "red", "value": None}, {"color": "yellow", "value": 30},
                          {"color": "green", "value": 90}]))
    p.append(timeseries("IP addresses available",
                        "The absolute floor, as a companion to runway: a pool that was quiet for a fortnight has infinite runway and eleven addresses left.",
                        [("lynomia_ip_pool_available", "{{pool}}")],
                        {"h": 8, "w": 8, "x": 8, "y": 24}))
    p.append(timeseries("Compute capacity committed",
                        "How much of the fleet is already sold. When every node is near 1, orders start failing after the customer has paid — the capacity check happens during provisioning, not at checkout.",
                        [("max by (dimension) (lynomia_node_capacity_ratio)", "worst node — {{dimension}}"),
                         ("avg by (dimension) (lynomia_node_capacity_ratio)", "fleet mean — {{dimension}}")],
                        {"h": 8, "w": 8, "x": 16, "y": 24}, unit="percentunit", max_=1))
    return dashboard("lynomia-business", "Business",
                     "Revenue, demand, and the capacity limits that turn into lost orders.",
                     p, ["lynomia", "business"], refresh="1m", time_from="now-7d")


def main():
    out = pathlib.Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    for name, builder in [
        ("platform", build_platform),
        ("proxmox", build_proxmox),
        ("hosting", build_hosting),
        ("dedicated", build_dedicated),
        ("business", build_business),
    ]:
        _panel_id[0] = 0
        d = builder()
        path = out / f"{name}.json"
        path.write_text(json.dumps(d, indent=2) + "\n")
        print(f"{path}  panels={len([p for p in d['panels'] if p['type'] != 'row'])}"
              f"  rows={len([p for p in d['panels'] if p['type'] == 'row'])}")


if __name__ == "__main__":
    main()
