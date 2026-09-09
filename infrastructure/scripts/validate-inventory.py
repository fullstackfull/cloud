#!/usr/bin/env python3
"""Keep the inventories honest.

An inventory is a list of machines somebody may be about to change. This script
is what stops a machine getting there without a stated purpose, a named owner
and a deliberate safety class — and what stops a credential being committed
alongside it.

It reads facts out of the YAML directly rather than asking Ansible, so it runs
in CI without Ansible installed and without contacting a single host.

Exit status 0 when every inventory passes, 1 otherwise.
"""

from __future__ import annotations

import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover - the CI job installs it
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(2)

SAFETY_CLASSES = {
    "DISCOVERY_ONLY",
    "CONFIGURATION_ALLOWED",
    "REIMAGE_ALLOWED",
    "DO_NOT_TOUCH",
}

# What every host must state. Deliberately short: this is the classification
# Phase 30B requires and the inventories did not have. purpose, owner and
# credentials_available are useful and are validated when present, but they are
# not required — the real inventory lives in a private repository, and padding
# the examples here with fictional owners buys nothing.
REQUIRED = ("ansible_host", "safety_class")

# Substrings that suggest a value is the credential rather than a fact about
# whether one is held. credentials_available is exempt because it is the
# boolean that exists precisely so nobody writes the credential itself.
SECRET_HINTS = ("password", "passwd", "secret", "token", "api_key", "apikey",
                "private_key", "ssh_key", "auth_code", "credential")

SECRET_VALUE_PREFIXES = ("-----BEGIN", "ssh-rsa ", "ssh-ed25519 ")


def hosts_in(
    node: dict, path: str = "", inherited: dict | None = None
) -> list[tuple[str, dict, str]]:
    """Walk an Ansible YAML inventory and yield (name, vars, group) per host.

    A group's `vars:` block applies to every host beneath it, so a
    classification set once on a group covers its machines — which is how these
    inventories are meant to read. Host vars win over group vars, as Ansible
    resolves them.
    """
    found: list[tuple[str, dict, str]] = []
    for group, body in (node or {}).items():
        if not isinstance(body, dict):
            continue
        here = f"{path}/{group}" if path else group
        from_here = {**(inherited or {}), **(body.get("vars") or {})}
        for name, hostvars in (body.get("hosts") or {}).items():
            found.append((name, {**from_here, **(hostvars or {})}, here))
        found.extend(hosts_in(body.get("children") or {}, here, from_here))
    return found


def check_host(name: str, hostvars: dict, group: str, where: Path) -> list[str]:
    problems: list[str] = []
    prefix = f"{where}: host '{name}' (group {group})"

    for key in REQUIRED:
        if key not in hostvars:
            problems.append(f"{prefix} does not declare {key}")

    klass = hostvars.get("safety_class")
    if klass is not None and klass not in SAFETY_CLASSES:
        problems.append(
            f"{prefix} has safety_class {klass!r}, which is not one of "
            + ", ".join(sorted(SAFETY_CLASSES))
        )

    reimage = hostvars.get("allow_reimage")
    if reimage is None:
        pass
    elif not isinstance(reimage, bool):
        problems.append(f"{prefix} has a non-boolean allow_reimage ({reimage!r})")
    elif reimage is True and klass != "REIMAGE_ALLOWED":
        problems.append(
            f"{prefix} sets allow_reimage: true but its safety_class is "
            f"{klass!r}; only REIMAGE_ALLOWED may be wiped"
        )

    creds = hostvars.get("credentials_available")
    if creds is not None and not isinstance(creds, bool):
        problems.append(
            f"{prefix} has a non-boolean credentials_available ({creds!r}); "
            "this field records whether a credential is held, never the credential"
        )

    for field in ("purpose", "owner"):
        value = hostvars.get(field)
        if field in hostvars and not (isinstance(value, str) and value.strip()):
            problems.append(f"{prefix} has an empty {field}")

    for key, value in hostvars.items():
        lowered = key.lower()
        if key != "credentials_available" and any(h in lowered for h in SECRET_HINTS):
            problems.append(f"{prefix} has a variable named {key!r}, which reads as a secret")
        if isinstance(value, str) and value.startswith(SECRET_VALUE_PREFIXES):
            problems.append(f"{prefix} has a value under {key!r} that looks like a key")

    return problems


def check_file(path: Path) -> list[str]:
    document = yaml.safe_load(path.read_text()) or {}
    root = document.get("all")
    if root is None:
        return [f"{path}: no 'all' group"]
    hosts = hosts_in(root.get("children") or {})

    problems: list[str] = []
    for name, hostvars, group in hosts:
        problems.extend(check_host(name, hostvars, group, path))
    return problems


def main(argv: list[str]) -> int:
    root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parent.parent
    files = sorted((root / "ansible" / "inventories").glob("*/hosts.yml"))
    if not files:
        print(f"no inventories found under {root}", file=sys.stderr)
        return 1

    failures: list[str] = []
    for path in files:
        problems = check_file(path)
        document = yaml.safe_load(path.read_text()) or {}
        hosts = len(hosts_in((document.get("all") or {}).get("children") or {}))
        status = "ok" if not problems else f"{len(problems)} problem(s)"
        print(f"{path.relative_to(root)}: {hosts} host(s), {status}")
        failures.extend(problems)

    for problem in failures:
        print(f"  FAIL {problem}", file=sys.stderr)

    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
