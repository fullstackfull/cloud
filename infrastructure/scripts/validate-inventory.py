#!/usr/bin/env python3
"""Keep the inventories honest.

An inventory is a list of machines somebody may be about to change. This script
is what stops a machine getting there without a stated purpose, a named owner
and a deliberate safety class — and what stops a credential being committed
alongside it.

It reads the YAML itself rather than asking Ansible, so it needs no Ansible
installed and contacts no host. That makes it a reimplementation of part of
Ansible's inventory resolution, and what follows says which part.

What it reads. An environment is a directory under `ansible/inventories/`. Its
sources are the files `inventory_sources` lists -- those in the directory and
its subdirectories that Ansible's default ignore rules do not skip -- and each
must be a non-empty YAML mapping of groups (`load`). A host's variables are
resolved from the groups, children, hosts and vars those mappings declare and
from the `group_vars/` and `host_vars/` files in the directory, by the rules
`resolve` and `vars_files` list. Those rules were taken from ansible-core
2.18's source with default settings, which ansible.cfg here does not change
(`grep -cE 'inventory_ignore|yaml_valid_extensions|enable_plugins|vars_plugins'
infrastructure/ansible/ansible.cfg` is 0). Each host is judged on the result.

What it refuses. A source or shape it has been shown to read differently from
Ansible is refused rather than read: a source that is not YAML, is executable,
configures a plugin or holds an empty document; a variables file that is
Vault-encrypted, unparseable or not a mapping; a group key Ansible skips; a
host range or port; a group loop; an `ungrouped` with a parent or children; a
file directly under `ansible/inventories/`; an environment with no source or
that declares no host. That list is what attack on this validator has found so
far, not a boundary: a shape nobody has tried may be resolved differently from
Ansible and not refused. The measurable quantity is the occupancy, and today it
is nil. `python3 infrastructure/scripts/validate-inventory.py` refuses nothing
(3 environments, 32 hosts, exit 0), and for each of the 32 hosts `resolve`
gives the variables `ansible-inventory -i <environment> --list` (ansible-core
2.18.1, its own magic variables aside) gives, run from a directory with no
`group_vars/` or `host_vars/` of its own.

What it does not read. Anything a play adds at run time, which is not part of
the inventory: the `group_vars/` and `host_vars/` beside a playbook, or beside
the working directory -- so `ansible-inventory` run from
`infrastructure/ansible/`, where ansible.cfg is, prints variables from
`infrastructure/ansible/group_vars/` that are not this validator's subject --
and `group_by`, `add_host`, play, role and extra vars. Measured today: that
`group_vars/` (which `playbooks/group_vars` links to) holds 11 files, and of
the variables judged here they set only `allow_reimage: false`, plus two whose
names carry a secret hint and hold none (`hardening_password_authentication:
false`, `proxmox_api_token_name: control-plane`); `grep -rlE
'\\b(group_by|add_host)\\b' infrastructure/ansible` finds no file.

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
    """One of a group's `vars`, `children` or `hosts`, read by Ansible's rule
    for it: absent or empty is nothing, a bare string is a one-key mapping
    (`hosts: web-1` is the host web-1), and anything else must be a mapping."""
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
    directory: Path | None = None,
) -> tuple[dict[str, tuple[dict, dict[str, Path], list[str], Path]], set[str]]:
    """Every host the documents declare, with the variables these rules give it.

    The rules are taken from ansible-core's YAML inventory plugin, its
    reconciliation, and the host_group_vars plugin that reads the directories
    beside an inventory (plugins/inventory/yaml.py, inventory/data.py,
    inventory/helpers.py, plugins/vars/host_group_vars.py, vars/manager.py).
    Each rule below is one of theirs; the list is not all of theirs:

    - Every top-level key of a document is a group, `all` only one of them.
    - A group, or a host, is one object however many places declare it, in
      however many documents: its `vars`, `children` and `hosts` accumulate by
      name, and where two declarations set the same variable the later one in
      reading order wins.
    - A group no other group claims as a child is a child of `all`, so `all:
      vars:` reaches every host. A host in no group but `all` is put in
      `ungrouped`, and a host in `ungrouped` and in any other group is taken
      out of `ungrouped`, so `ungrouped`'s vars reach only a host in no other
      group.
    - A host's groups are those it is in and each of their ancestors, ordered
      ascending by (depth below `all`, `ansible_group_priority`, name), so the
      deeper group wins. Its variables are, each layer winning over the ones
      before it: its groups' `vars` in that order; then the files
      `group_vars/` in `directory` holds for `all` and for each of its groups,
      in the same order; its own vars; the files `host_vars/` in `directory`
      holds for it (`vars_files`).

    `directory` is the inventory directory, the one `-i` names; None reads no
    `group_vars/` or `host_vars/`.

    Returns {host: (variables, the file each variable came from, groups it was
    declared in, first file that declared it)} and the set of group names
    defined. Raises Unreadable for these shapes, each found to be one Ansible
    refuses, skips, or resolves by rules not listed above: a group or host key
    that is empty or not a string, a group, section or host's vars that is not
    a mapping, a group key other than vars, children and hosts, a host key
    Ansible would rewrite, an ansible_group_priority that is not a number, a
    group loop or `all` as a child, a variables file this cannot read
    (`load_vars`), and an `ungrouped` with a parent other than `all` or with
    children of its own. These are the shapes attack has found, not every
    shape on which these rules and Ansible's part.

    The walk used to start at `all` and follow the document's nesting, so a
    host under a group written beside `all:` was neither counted nor checked,
    and vars declared on one occurrence of a group never reached the hosts
    declared on another. It then read neither `group_vars/` nor `host_vars/`,
    kept a host in `ungrouped` that Ansible had taken out of it, and dropped a
    misspelled group key without a word (F-38).
    """
    group_vars: dict[str, dict] = {}
    group_origin: dict[str, dict[str, Path]] = {}
    priority: dict[str, int] = {}
    parents: dict[str, list[str]] = {}
    host_vars: dict[str, dict] = {}
    host_origin: dict[str, dict[str, Path]] = {}
    declared_in: dict[str, list[str]] = {}
    first_file: dict[str, Path] = {}

    def declare(name: str) -> None:
        group_vars.setdefault(name, {})
        group_origin.setdefault(name, {})
        priority.setdefault(name, 1)
        parents.setdefault(name, [])

    def adopt(parent: str, child: str, where: Path) -> None:
        if parent == child:
            raise Unreadable(f"{where}: group '{child}' is declared as its own child")
        if parent not in parents[child]:
            parents[child].append(parent)

    def parse(name: object, body: object, where: Path) -> str:
        if not isinstance(name, str) or not name:
            # add_group() refuses it, and the YAML plugin then fails the whole
            # file -- every group and host in it, not just this one.
            raise Unreadable(
                f"{where}: the group name {name!r} is "
                f"{'empty' if name == '' else 'not a string'}, which Ansible "
                "refuses, failing the whole file"
            )
        declare(name)
        if body is None:
            return name
        if not isinstance(body, dict):
            raise Unreadable(
                f"{where}: '{name}' is not a group mapping, so no host in it can be read"
            )
        # Ansible skips any other key -- with a warning, unless it is empty --
        # and whatever is under it: a misspelled `host:` is hosts, and their
        # vars, that nothing checks.
        skipped = [key for key in body if key not in SECTIONS]
        if skipped:
            raise Unreadable(
                f"{where}: group '{name}' has the key(s) {skipped}, which Ansible "
                "skips with everything under them; a group holds only vars, "
                "children and hosts"
            )
        # In the order the document gives, which is the order Ansible applies.
        for key in body:
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
                        group_origin[name][var] = where
            elif key == "children":
                for child, child_body in entries.items():
                    adopt(name, parse(child, child_body, where), where)
            else:
                for host, own in entries.items():
                    if not isinstance(host, str) or not host:
                        raise Unreadable(
                            f"{where}: group '{name}' lists the host key {host!r}, "
                            f"which is {'empty' if host == '' else 'not a string'}; "
                            "Ansible refuses it, failing the whole file"
                        )
                    if REWRITTEN_HOST_KEY.search(host):
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
                    host_origin.setdefault(host, {}).update(dict.fromkeys(own, where))
                    first_file.setdefault(host, where)
                    groups = declared_in.setdefault(host, [])
                    if name not in groups:
                        groups.append(name)
        return name

    declare("all")
    declare("ungrouped")
    parents["ungrouped"].append("all")
    for where, document in documents:
        for name, body in document.items():
            parse(name, body, where)

    read = ", ".join(str(where) for where, _ in documents) or "<no document>"
    if parents["all"]:
        raise Unreadable(
            f"{read}: 'all' is declared as a child of {', '.join(parents['all'])}; "
            "Ansible refuses the loop that makes"
        )
    # Ansible takes a host out of `ungrouped` by removing that one group from
    # the host's list, and with it any ancestor no other group of the host's
    # still needs. Measured on ansible-core 2.18.1: under a parent `p`, a host
    # listed only in `ungrouped` keeps neither group's vars; with a child `g`,
    # a host listed in both loses `ungrouped`'s vars that one listed only in
    # `g` keeps. Neither follows from the rules above, so neither is guessed.
    adopted_by = [p for p in parents["ungrouped"] if p != "all"]
    adopting = sorted(c for c, above in parents.items() if "ungrouped" in above)
    if adopted_by or adopting:
        relation = (
            f"is declared a child of {', '.join(adopted_by)}" if adopted_by
            else f"has the child group(s) {', '.join(adopting)}"
        )
        raise Unreadable(
            f"{read}: 'ungrouped' {relation}, and Ansible's handling of which "
            "hosts stay in it then departs from the rules this reads by; put "
            "those hosts in a group of their own"
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

    beside: dict[tuple[str, str], tuple[dict, dict[str, Path]]] = {}

    def adjacent(kind: str, name: str) -> tuple[dict, dict[str, Path]]:
        """What `kind` (group_vars or host_vars) in `directory` sets for `name`."""
        if directory is None:
            return {}, {}
        if (kind, name) not in beside:
            values: dict = {}
            origin: dict[str, Path] = {}
            for path in vars_files(directory / kind, name):
                data = load_vars(path)
                values.update(data)
                origin.update(dict.fromkeys(data, path))
            beside[(kind, name)] = values, origin
        return beside[(kind, name)]

    if directory is not None:
        for kind in ("group_vars", "host_vars"):
            place = directory / kind
            if place.exists() and not place.is_dir():
                raise Unreadable(
                    f"{place}: is not a directory, which Ansible warns "
                    "about and skips; this validator refuses it rather than guess "
                    "what it was meant to set"
                )

    resolved: dict[str, tuple[dict, dict[str, Path], list[str], Path]] = {}
    for host, groups in declared_in.items():
        member_of = list(groups)
        if [g for g in member_of if g not in ("all", "ungrouped")]:
            member_of = [g for g in member_of if g != "ungrouped"]
        else:
            member_of.append("ungrouped")
        lineage: set[str] = set()
        pending = list(member_of)
        while pending:
            group = pending.pop()
            if group not in lineage:
                lineage.add(group)
                pending.extend(parents[group])
        ordered = sorted(lineage, key=lambda g: (depth[g], priority[g], g))
        layers = [
            *((group_vars[g], group_origin[g]) for g in ordered),
            *(adjacent("group_vars", g) for g in ordered),
            (host_vars[host], host_origin[host]),
            adjacent("host_vars", host),
        ]
        effective: dict = {}
        origins: dict[str, Path] = {}
        for values, origin in layers:
            effective.update(values)
            origins.update(origin)
        resolved[host] = (effective, origins, groups, first_file[host])

    return resolved, set(parents) - {"all", "ungrouped"}


# What Ansible's host_group_vars plugin reads from a `group_vars/` or
# `host_vars/` directory for a host or group named N, with default settings
# (DataLoader.find_vars_files; YAML_FILENAME_EXTENSIONS in config/base.yml): the
# first of N, N.yml, N.yaml and N.json that exists -- and if that is a
# directory, every file beneath it whose name has one of those extensions or
# none, in sorted order, skipping hidden names and `~` backups and not entering
# a subdirectory whose name has an extension.
VARS_EXTENSIONS = (".yml", ".yaml", ".json")


def vars_files(directory: Path, name: str) -> list[Path]:
    """The files Ansible loads, in order, for `name` from `directory`."""
    if name.startswith(os.sep):
        return []  # a host named like a path; Ansible looks nothing up for it
    for candidate in (directory / (name + ext) for ext in ("", *VARS_EXTENSIONS)):
        if candidate.exists():
            return files_beneath(candidate) if candidate.is_dir() else [candidate]
    return []


def files_beneath(directory: Path) -> list[Path]:
    found: list[Path] = []
    for entry in sorted(os.listdir(directory)):
        if entry.startswith(".") or entry.endswith("~"):
            continue
        path = directory / entry
        extension = os.path.splitext(entry)[1]
        if path.is_dir() and not extension:
            found.extend(files_beneath(path))
        elif path.is_file() and (not extension or extension in VARS_EXTENSIONS):
            found.append(path)
    return found


def load_vars(path: Path) -> dict:
    """One file of variables, as the mapping Ansible reads from it, or
    Unreadable saying why this cannot read it that way. Ansible ignores a file
    whose content is empty or false (null, `{}`, `[]`, 0), and fails on any
    other that is not a mapping."""
    try:
        text = path.read_text()
    except (OSError, UnicodeDecodeError) as error:
        raise Unreadable(f"{path}: cannot be read as text ({error})") from None
    if text.startswith("$ANSIBLE_VAULT;"):
        raise Unreadable(
            f"{path}: is encrypted with Ansible Vault, and Ansible loads it as "
            "variables for an inventory entry; this validator cannot read what "
            "it sets"
        )
    try:
        data = yaml.safe_load(text)
    except yaml.YAMLError as error:
        detail = str(error).splitlines()[0]
        raise Unreadable(
            f"{path}: does not parse as YAML ({detail}), and Ansible loads it as "
            "variables for an inventory entry"
        ) from None
    if not data:
        return {}
    if not isinstance(data, dict):
        raise Unreadable(
            f"{path}: holds a {type(data).__name__}, not a mapping of variables, "
            "which Ansible fails on"
        )
    return data


def check_host(
    name: str,
    hostvars: dict,
    groups: list[str],
    where: Path,
    origins: dict[str, Path] | None = None,
) -> list[str]:
    """What is wrong with one host's resolved variables. `where` is the file
    that declares the host; a problem with a variable another file set names
    that file too, from `origins`."""
    problems: list[str] = []
    label = "group" if len(groups) == 1 else "groups"
    prefix = f"{where}: host '{name}' ({label} {', '.join(groups)})"

    def at(key: object) -> str:
        origin = (origins or {}).get(key, where)
        return "" if origin == where else f" (set in {origin})"

    for key in REQUIRED:
        if key not in hostvars:
            problems.append(f"{prefix} does not declare {key}")

    klass = hostvars.get("safety_class")
    if klass is not None and klass not in SAFETY_CLASSES:
        problems.append(
            f"{prefix} has safety_class {klass!r}{at('safety_class')}, which is not one of "
            + ", ".join(sorted(SAFETY_CLASSES))
        )

    reimage = hostvars.get("allow_reimage")
    if reimage is None:
        pass
    elif not isinstance(reimage, bool):
        problems.append(
            f"{prefix} has a non-boolean allow_reimage ({reimage!r}){at('allow_reimage')}"
        )
    elif reimage is True and klass != "REIMAGE_ALLOWED":
        problems.append(
            f"{prefix} sets allow_reimage: true{at('allow_reimage')} but its "
            f"safety_class is {klass!r}; only REIMAGE_ALLOWED may be wiped"
        )

    creds = hostvars.get("credentials_available")
    if creds is not None and not isinstance(creds, bool):
        problems.append(
            f"{prefix} has a non-boolean credentials_available ({creds!r})"
            f"{at('credentials_available')}; this field records whether a "
            "credential is held, never the credential"
        )

    for field in ("purpose", "owner"):
        value = hostvars.get(field)
        if field in hostvars and not (isinstance(value, str) and value.strip()):
            problems.append(f"{prefix} has an empty {field}{at(field)}")

    for key, value in hostvars.items():
        lowered = str(key).lower()
        if key != "credentials_available" and any(h in lowered for h in SECRET_HINTS):
            problems.append(
                f"{prefix} has a variable named {key!r}{at(key)}, which reads as a secret"
            )
        if isinstance(value, str) and value.startswith(SECRET_VALUE_PREFIXES):
            problems.append(f"{prefix} has a value under {key!r}{at(key)} that looks like a key")

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
    """The files Ansible reads as inventory when given this directory, under
    the default ignore settings above."""
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
    # The YAML plugin refuses an empty document -- `{}`, `null`, `~`, a file
    # of comments -- with "Parsed empty YAML file", and Ansible hands the file
    # to the next plugin. INI takes `{}`, `null` or `~` as a host in
    # `ungrouped`. This used to read such a file as no groups and print `ok`
    # over that host (F-38). A file of comments alone, which INI reads as
    # nothing, is refused with the rest: a false red, the safe direction.
    if not document:
        raise Unreadable(
            f"{path}: holds an empty YAML document, which Ansible's YAML plugin "
            "refuses; Ansible then tries another plugin on the file (INI takes "
            "a line like `{}` or `null` as a host), and this validator cannot "
            "read it that way"
        )
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


def check_inventory(
    sources: list[Path], where: Path, directory: Path | None = None,
) -> tuple[list[str], int, set[str]]:
    """Check one inventory made of these sources, read together by the rules
    in `resolve`, with the `group_vars/` and `host_vars/` in `directory`.
    Returns (problems, hosts resolved, groups defined).

    A source need not have an `all:` key: Ansible reads every top-level key as
    a group, and one that only declares groups beside `all` is a whole,
    valid inventory file."""
    problems: list[str] = []
    documents: list[tuple[Path, dict]] = []
    for path in sources:
        try:
            documents.append((path, load(path)))
        except Unreadable as refusal:
            problems.append(str(refusal))

    try:
        resolved, groups = resolve(documents, directory)
    except Unreadable as refusal:
        return [*problems, str(refusal)], 0, set()

    # The inventory's own empty subject. A document that has stopped declaring
    # hosts in any shape this reads would otherwise print `0 host(s), ok`,
    # which in review is a clean run.
    if not resolved:
        problems.append(f"{where}: declares no host, so nothing in it was checked")

    for name, (hostvars, origins, declared, path) in resolved.items():
        problems.extend(check_host(name, hostvars, declared, path, origins))
    return problems, len(resolved), groups


def check_file(path: Path) -> list[str]:
    """Check one file as `-i <that file>` reads it: as a whole inventory, with
    the `group_vars/` and `host_vars/` beside it."""
    return check_inventory([path], path, path.parent)[0]


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
    # A file beside the environment directories belongs to none of them, so no
    # environment's check reads it. Ansible reads it when `-i` names it, or the
    # whole inventories directory; either way, what it declares goes unchecked.
    for entry in sorted(inventories.iterdir(), key=lambda p: p.name):
        if not entry.is_dir() and not (
            IGNORED_NAME.search(entry.name) or entry.name.endswith(IGNORED_ENDINGS)
        ):
            failures.append(
                f"{entry}: sits directly under ansible/inventories/, in no "
                "environment directory, so nothing in it is checked; move it into "
                "the environment it belongs to"
            )
    defined: set[str] = set()
    for environment in environments:
        sources = inventory_sources(environment)
        if sources:
            problems, hosts, groups = check_inventory(sources, environment, environment)
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
