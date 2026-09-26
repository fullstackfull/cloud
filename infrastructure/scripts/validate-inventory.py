#!/usr/bin/env python3
"""Keep the inventories honest.

An inventory is a list of machines somebody may be about to change. This script
is what stops a machine getting there without a stated purpose, a named owner
and a deliberate safety class — and what stops a credential being committed
alongside it.

It reads facts out of the YAML directly rather than asking Ansible, so it needs
no Ansible installed and contacts no host. It reads the files Ansible reads,
the way Ansible reads them: an environment is a directory under
`ansible/inventories/`, taken the way `-i <that directory>` takes it
(`inventory_sources`), and each host is judged on the variables Ansible's YAML
inventory plugin gives it (`resolve`). A file or a shape it cannot read that
way is refused, never skipped. The `group_vars/` and `host_vars/` directories
beside an inventory hold variables Ansible also loads, and this does not read
them.

Exit status 0 when every environment's inventory passes, 1 otherwise.
"""

from __future__ import annotations

import os
import sys
import re
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


class Unreadable(Exception):
    """A shape Ansible refuses, or one this cannot read as Ansible would."""


# The three keys a group may carry. Ansible warns about and skips any other.
SECTIONS = ("vars", "children", "hosts")

# A host key Ansible rewrites before using it as a name: `[` begins a range
# (`web[01:03]` is three hosts) or a bracketed address, and a trailing `:NN`
# on an otherwise colon-free key is a port (`db-1:2222` is the host `db-1`).
# These are the two tests ansible-core applies -- `detect_range`, and the
# `hostport` pattern in parsing/utils/addresses.py. Read as written, such a key
# names a host that does not exist and misses merging with the one that does,
# so it is refused rather than guessed at.
REWRITTEN_HOST_KEY = re.compile(r"\[|^[^:\[\]]*:[0-9]+$")


def section(group: str, body: dict, key: str, where: Path) -> dict:
    """One of a group's `vars`, `children` or `hosts`, read as Ansible reads it:
    absent or empty is nothing, a bare string is a one-key mapping (`hosts:
    web-1` is the host web-1), and anything else must be a mapping."""
    value = body.get(key)
    if value is None:
        return {}
    if isinstance(value, str):
        return {value: None}
    if not isinstance(value, dict):
        raise Unreadable(
            f"{where}: group '{group}' has a {key} entry that is a "
            f"{type(value).__name__}, not a mapping, which Ansible refuses"
        )
    return value


def resolve(
    documents: list[tuple[Path, dict]],
) -> tuple[dict[str, tuple[dict, list[str], Path]], set[str]]:
    """Every host the documents declare, with the variables Ansible gives it.

    This follows ansible-core's YAML inventory plugin and its reconciliation
    (plugins/inventory/yaml.py, inventory/data.py, inventory/helpers.py), and
    each rule below is one of theirs:

    - Every top-level key of a document is a group, `all` only one of them.
    - A group, or a host, is one object however many places declare it, in
      however many documents: its `vars`, `children` and `hosts` accumulate by
      name, and where two declarations set the same variable the later one in
      reading order wins.
    - A group no other group claims as a child is a child of `all`, so `all:
      vars:` reaches every host; a host in no group but `all` is in
      `ungrouped`.
    - A host's variables are those of every group it is in and each of their
      ancestors, applied in ascending (depth below `all`,
      `ansible_group_priority`, name) -- so the deeper group wins -- and then
      its own, which win over all of them.

    Returns {host: (variables, groups it was declared in, first file that
    declared it)} and the set of group names defined. Raises Unreadable for a
    shape Ansible refuses or skips, or a host key it would rewrite.

    The walk used to start at `all` and follow the document's nesting, so a
    host under a group written beside `all:` was neither counted nor checked,
    and vars declared on one occurrence of a group never reached the hosts
    declared on another (F-38).
    """
    group_vars: dict[str, dict] = {}
    priority: dict[str, int] = {}
    parents: dict[str, list[str]] = {}
    host_vars: dict[str, dict] = {}
    declared_in: dict[str, list[str]] = {}
    first_file: dict[str, Path] = {}

    def declare(name: str) -> None:
        group_vars.setdefault(name, {})
        priority.setdefault(name, 1)
        parents.setdefault(name, [])

    def adopt(parent: str, child: str, where: Path) -> None:
        if parent == child:
            raise Unreadable(f"{where}: group '{child}' is declared as its own child")
        if parent not in parents[child]:
            parents[child].append(parent)

    def parse(name: str, body: object, where: Path) -> None:
        declare(name)
        if body is None:
            return
        if not isinstance(body, dict):
            raise Unreadable(
                f"{where}: '{name}' is not a group mapping, so no host in it can be read"
            )
        # In the order the document gives, which is the order Ansible applies.
        for key in [k for k in body if k in SECTIONS]:
            entries = section(name, body, key, where)
            if key == "vars":
                for var, value in entries.items():
                    if var == "ansible_group_priority":
                        # Ansible takes this out of the vars and orders by it.
                        try:
                            priority[name] = int(value)
                        except (TypeError, ValueError):
                            raise Unreadable(
                                f"{where}: group '{name}' has an ansible_group_priority "
                                f"of {value!r}, which Ansible cannot read as a number"
                            ) from None
                    else:
                        group_vars[name][var] = value
            elif key == "children":
                for child, child_body in entries.items():
                    parse(str(child), child_body, where)
                    adopt(name, str(child), where)
            else:
                for host, own in entries.items():
                    if not isinstance(host, str) or REWRITTEN_HOST_KEY.search(host):
                        raise Unreadable(
                            f"{where}: group '{name}' lists the host key {host!r}, which "
                            "Ansible rewrites into other host names (a range or a port); "
                            "list each host by its plain name"
                        )
                    own = own or {}  # Ansible reads any empty value as no vars
                    if not isinstance(own, dict):
                        raise Unreadable(
                            f"{where}: host '{host}' in group '{name}' has vars that "
                            f"are a {type(own).__name__}, not a mapping, which Ansible refuses"
                        )
                    host_vars.setdefault(host, {}).update(own)
                    first_file.setdefault(host, where)
                    groups = declared_in.setdefault(host, [])
                    if name not in groups:
                        groups.append(name)

    declare("all")
    declare("ungrouped")
    parents["ungrouped"].append("all")
    for where, document in documents:
        for name, body in document.items():
            parse(str(name), body, where)

    read = ", ".join(str(where) for where, _ in documents) or "<no document>"
    if parents["all"]:
        raise Unreadable(
            f"{read}: 'all' is declared as a child of {', '.join(parents['all'])}; "
            "Ansible refuses the loop that makes"
        )
    for name, above in parents.items():
        if name != "all" and not above:
            above.append("all")

    depth: dict[str, int] = {"all": 0}

    def depth_of(name: str, trail: tuple[str, ...] = ()) -> int:
        if name in depth:
            return depth[name]
        if name in trail:
            loop = " -> ".join((*trail[trail.index(name):], name))
            raise Unreadable(f"{read}: groups form a loop ({loop}), which Ansible refuses")
        depth[name] = 1 + max(depth_of(p, (*trail, name)) for p in parents[name])
        return depth[name]

    for name in list(parents):
        depth_of(name)

    resolved: dict[str, tuple[dict, list[str], Path]] = {}
    for host, groups in declared_in.items():
        member_of = list(groups)
        if not [g for g in member_of if g not in ("all", "ungrouped")]:
            member_of.append("ungrouped")
        lineage: set[str] = set()
        pending = list(member_of)
        while pending:
            group = pending.pop()
            if group not in lineage:
                lineage.add(group)
                pending.extend(parents[group])
        effective: dict = {}
        for group in sorted(lineage, key=lambda g: (depth[g], priority[g], g)):
            effective.update(group_vars[group])
        effective.update(host_vars[host])
        resolved[host] = (effective, groups, first_file[host])

    return resolved, set(parents) - {"all", "ungrouped"}


def check_host(name: str, hostvars: dict, groups: list[str], where: Path) -> list[str]:
    problems: list[str] = []
    label = "group" if len(groups) == 1 else "groups"
    prefix = f"{where}: host '{name}' ({label} {', '.join(groups)})"

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


def documented_roles(repo_root: Path) -> list[str]:
    """Machine roles docs/deployment.md's topology block names."""
    doc = repo_root / "docs" / "deployment.md"
    if not doc.exists():
        return []
    text = doc.read_text()
    start = text.find("## Topology")
    if start < 0:
        return []
    block = re.search(r"```text\n(.*?)```", text[start:], re.DOTALL)
    if not block:
        return []
    return sorted({
        m.group(1)
        for line in block.group(1).splitlines()
        if (m := re.match(r"^([a-z_]+)\s+\S", line))
    })


# How ansible-core reads a directory given as an inventory source, with its
# default settings, which this repository's ansible.cfg does not change: every
# entry these do not skip is a source, and a subdirectory is read the same way
# (IGNORED in inventory/manager.py; INVENTORY_IGNORE_EXTS in config/base.yml).
IGNORED_NAME = re.compile(r"^\.|^host_vars$|^group_vars$|^vars_plugins$")
IGNORED_ENDINGS = (".pyc", ".pyo", ".swp", ".bak", "~", ".rpm", ".md", ".txt",
                   ".rst", ".orig", ".ini", ".cfg", ".retry")

# What the YAML plugin accepts. Any other source goes to a plugin (ini, toml,
# script) whose format this does not read.
YAML_SUFFIXES = ("", ".yml", ".yaml", ".json")


def inventory_sources(directory: Path) -> list[Path]:
    """Every file Ansible reads as inventory when given this directory."""
    found: list[Path] = []
    for entry in sorted(directory.iterdir(), key=lambda p: p.name):
        if IGNORED_NAME.search(entry.name) or entry.name.endswith(IGNORED_ENDINGS):
            continue
        if entry.is_dir():
            found.extend(inventory_sources(entry))
        else:
            found.append(entry)
    return found


def load(path: Path) -> dict:
    """One inventory source as a mapping of groups, or Unreadable saying why not."""
    text = path.read_text()
    # Ansible tries its script plugin before YAML, on any file that is
    # executable or begins with `#!`, and takes what it prints when it runs.
    if text.startswith("#!") or os.access(path, os.X_OK):
        raise Unreadable(
            f"{path}: is executable or begins with #!, so Ansible first runs it as "
            "an inventory script and takes what it prints; this validator cannot "
            "see that"
        )
    if path.suffix not in YAML_SUFFIXES:
        raise Unreadable(
            f"{path}: Ansible reads this file as inventory through a plugin other "
            "than YAML, and this validator reads only YAML, so its hosts would go "
            "unchecked"
        )
    try:
        document = yaml.safe_load(text)
    except yaml.YAMLError as error:
        detail = str(error).splitlines()[0]
        raise Unreadable(
            f"{path}: does not parse as YAML ({detail}), so Ansible would try "
            "another plugin on it, and this validator cannot"
        ) from None
    if document is None:
        return {}
    if not isinstance(document, dict):
        raise Unreadable(
            f"{path}: not a YAML mapping of groups, so Ansible would try another "
            "plugin on it (INI reads almost anything), and this validator cannot"
        )
    if document.get("plugin"):
        raise Unreadable(
            f"{path}: configures the inventory plugin {document['plugin']!r}; the "
            "hosts it produces are not in this file, so none of them is checked"
        )
    return document


def check_inventory(sources: list[Path], where: Path) -> tuple[list[str], int, set[str]]:
    """Check one inventory made of these sources, read together as Ansible
    reads them. Returns (problems, hosts resolved, groups defined)."""
    problems: list[str] = []
    documents: list[tuple[Path, dict]] = []
    for path in sources:
        try:
            document = load(path)
        except Unreadable as refusal:
            problems.append(str(refusal))
            continue
        if document.get("all") is None:
            problems.append(f"{path}: no 'all' group")
        documents.append((path, document))

    try:
        resolved, groups = resolve(documents)
    except Unreadable as refusal:
        return [*problems, str(refusal)], 0, set()

    # The inventory's own empty subject. A document that has stopped declaring
    # hosts in any shape this reads would otherwise print `0 host(s), ok`,
    # which in review is a clean run.
    if not resolved:
        problems.append(f"{where}: declares no host, so nothing in it was checked")

    for name, (hostvars, declared, path) in resolved.items():
        problems.extend(check_host(name, hostvars, declared, path))
    return problems, len(resolved), groups


def check_file(path: Path) -> list[str]:
    """Check one file as if it were a whole inventory."""
    return check_inventory([path], path)[0]


def main(argv: list[str]) -> int:
    root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parent.parent
    inventories = root / "ansible" / "inventories"
    environments = sorted(
        entry for entry in (inventories.iterdir() if inventories.is_dir() else [])
        if entry.is_dir() and not IGNORED_NAME.search(entry.name)
    )
    if not environments:
        print(f"no inventories found under {root}", file=sys.stderr)
        return 1

    failures: list[str] = []
    defined: set[str] = set()
    for environment in environments:
        sources = inventory_sources(environment)
        if sources:
            problems, hosts, groups = check_inventory(sources, environment)
        else:
            problems, hosts, groups = [
                f"{environment}: no inventory source in it, so this environment "
                "was not checked"
            ], 0, set()
        defined |= groups
        named = ", ".join(str(path.relative_to(environment)) for path in sources) or "nothing"
        status = "ok" if not problems else f"{len(problems)} problem(s)"
        print(f"{environment.relative_to(root)}: {hosts} host(s) in {named}, {status}")
        failures.extend(problems)

    # A machine role the deployment document describes and no inventory has is
    # a component with nowhere to be deployed. The gap may stand — but not
    # silently: docs/deployment.md has to say so beside the role.
    repo_root = root.parent
    doc = (repo_root / "docs" / "deployment.md")
    doc_text = doc.read_text() if doc.exists() else ""
    for role in documented_roles(repo_root):
        if role in defined:
            continue
        if re.search(rf"NOT DEPLOYABLE[^\n]*`?{role}`?|`?{role}`?[^\n]*NOT DEPLOYABLE", doc_text):
            print(
                f"  NOTE docs/deployment.md names the machine role '{role}', which no "
                f"inventory defines; it has no deployment path"
            )
            continue
        failures.append(
            f"docs/deployment.md names the machine role '{role}', which no "
            f"inventory defines and nothing can deploy. Mark it NOT DEPLOYABLE "
            f"there, with the reason, or give it a group."
        )

    for problem in failures:
        print(f"  FAIL {problem}", file=sys.stderr)

    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
