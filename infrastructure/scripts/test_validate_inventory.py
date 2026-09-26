#!/usr/bin/env python3
"""Proof that the inventory validator rejects what it claims to reject.

Run: python3 infra/scripts/test_validate_inventory.py
"""

from __future__ import annotations

import contextlib
import io
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import importlib.util

spec = importlib.util.spec_from_file_location(
    "validate_inventory", Path(__file__).resolve().parent / "validate-inventory.py"
)
validate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(validate)

GOOD = """
all:
  children:
    group:
      hosts:
        host-1:
          ansible_host: 198.51.100.1
          safety_class: DISCOVERY_ONLY
          allow_reimage: false
          credentials_available: false
          purpose: "a purpose"
          owner: "an owner"
"""


PRIVATE_KEY_HEADER = "-----BEGIN" + " OPENSSH PRIVATE KEY" + "-----"

def run(document: str) -> list[str]:
    with tempfile.TemporaryDirectory() as tmp:
        path = Path(tmp) / "hosts.yml"
        path.write_text(document)
        return validate.check_file(path)


CASES = [
    ("a well-formed host passes", GOOD, None),
    (
        "a host with no safety_class is refused",
        GOOD.replace("          safety_class: DISCOVERY_ONLY\n", ""),
        "does not declare safety_class",
    ),
    (
        "an unknown safety_class is refused",
        GOOD.replace("DISCOVERY_ONLY", "PROBABLY_FINE"),
        "is not one of",
    ),
    (
        "allow_reimage on a non-reimageable class is refused",
        GOOD.replace("allow_reimage: false", "allow_reimage: true"),
        "only REIMAGE_ALLOWED may be wiped",
    ),
    (
        "allow_reimage on REIMAGE_ALLOWED is accepted",
        GOOD.replace("DISCOVERY_ONLY", "REIMAGE_ALLOWED").replace(
            "allow_reimage: false", "allow_reimage: true"
        ),
        None,
    ),
    (
        "a host with no ansible_host is refused",
        GOOD.replace("          ansible_host: 198.51.100.1\n", ""),
        "does not declare ansible_host",
    ),
    (
        "purpose and owner are optional, so a host without them passes",
        GOOD.replace('          purpose: "a purpose"\n', "").replace(
            '          owner: "an owner"\n', ""
        ),
        None,
    ),
    (
        "an empty purpose is refused",
        GOOD.replace('purpose: "a purpose"', 'purpose: ""'),
        "empty purpose",
    ),
    (
        "a variable named like a secret is refused",
        GOOD + "          bmc_password: hunter2\n",
        "reads as a secret",
    ),
    (
        "an inlined private key is refused",
        # Assembled rather than written out. A fixture for this test has to look
        # like a private key header, and the repository's committed-secret gate
        # greps tracked files for exactly that shape. Excluding this file from
        # that gate would mean a real key pasted into it went uncaught, which is
        # the one thing the gate exists to prevent — so the fixture is built
        # from fragments instead. No key material exists here either way.
        GOOD + '          fingerprint: "' + PRIVATE_KEY_HEADER + '"\n',
        "looks like a key",
    ),
    (
        "credentials_available holding a credential instead of a boolean is refused",
        GOOD.replace("credentials_available: false", 'credentials_available: "root/hunter2"'),
        "never the credential",
    ),
    (
        "a nested child group is walked, not skipped",
        """
all:
  children:
    outer:
      children:
        inner:
          hosts:
            deep-1:
              ansible_host: 198.51.100.9
""",
        "does not declare safety_class",
    ),
    (
        "a class set once on a group covers the hosts beneath it",
        """
all:
  children:
    outer:
      vars:
        safety_class: DISCOVERY_ONLY
      children:
        inner:
          hosts:
            deep-1:
              ansible_host: 198.51.100.9
""",
        None,
    ),
    (
        "a host may override its group's class, and is judged on its own",
        """
all:
  children:
    outer:
      vars:
        safety_class: DISCOVERY_ONLY
      hosts:
        host-1:
          ansible_host: 198.51.100.9
          safety_class: REIMAGE_ALLOWED
          allow_reimage: true
""",
        None,
    ),
    (
        "an inherited class does not excuse an inconsistent allow_reimage",
        """
all:
  children:
    outer:
      vars:
        safety_class: CONFIGURATION_ALLOWED
      hosts:
        host-1:
          ansible_host: 198.51.100.9
          allow_reimage: true
""",
        "only REIMAGE_ALLOWED may be wiped",
    ),
    # F-38. Ansible puts a host listed directly under `all: hosts:` in the
    # inventory exactly as it puts one under a child group, and applies
    # `all: vars:` to every host. The walk used to start at `all.children`, so
    # such a host -- whatever it carried -- was not in the count and not
    # checked, and the file printed `N host(s), ok` over it.
    (
        "a host declared directly under all: with no safety_class is refused",
        """
all:
  hosts:
    stray-1:
      ansible_host: 198.51.100.20
""",
        "does not declare safety_class",
    ),
    (
        "a host declared directly under all: is checked for secrets too",
        """
all:
  hosts:
    stray-1:
      ansible_host: 198.51.100.20
      safety_class: DISCOVERY_ONLY
      bmc_password: hunter2
""",
        "reads as a secret",
    ),
    (
        "a well-formed host declared directly under all: passes",
        """
all:
  hosts:
    stray-1:
      ansible_host: 198.51.100.20
      safety_class: DISCOVERY_ONLY
""",
        None,
    ),
    (
        "a class set once on all: vars covers every host in the inventory",
        """
all:
  vars:
    safety_class: DISCOVERY_ONLY
  children:
    group:
      hosts:
        host-1:
          ansible_host: 198.51.100.1
""",
        None,
    ),
    (
        "a secret-named variable in all: vars is caught on the hosts it reaches",
        """
all:
  vars:
    vault_token: s.abcdef
  children:
    group:
      hosts:
        host-1:
          ansible_host: 198.51.100.1
          safety_class: DISCOVERY_ONLY
""",
        "reads as a secret",
    ),
    # F-38, round five. Ansible's YAML inventory makes a group of EVERY
    # top-level key, `all` being only one of them, and a group or host is one
    # object however many places declare it. The walk started at `all` and
    # followed the document's nesting, so a host under a group written beside
    # `all:` was neither counted nor checked, and vars declared on one
    # occurrence of a group never reached hosts declared on another. What a
    # case below takes Ansible to do was read off `ansible-inventory --list`
    # (ansible-core 2.18.1) over the same document, not reasoned out; the
    # cases that expect a refusal are shapes Ansible rewrites, skips or fails
    # on, which this refuses rather than guesses at.
    (
        "a host under a top-level group beside all: is checked for secrets",
        """
all:
  vars:
    lynomia_environment: staging
sidecar:
  hosts:
    hidden-1:
      ansible_host: 198.51.100.201
      safety_class: DISCOVERY_ONLY
      bmc_password: hunter2
""",
        "reads as a secret",
    ),
    (
        "a host under a top-level group beside all: with no safety_class is refused",
        """
all:
  vars:
    lynomia_environment: staging
sidecar:
  hosts:
    hidden-1:
      ansible_host: 198.51.100.201
""",
        "does not declare safety_class",
    ),
    (
        "all: vars reaches a host under a top-level group, as in Ansible",
        """
all:
  vars:
    safety_class: DISCOVERY_ONLY
sidecar:
  hosts:
    hidden-1:
      ansible_host: 198.51.100.201
""",
        None,
    ),
    (
        "an inventory whose groups all sit beside all: is read, not emptied",
        """
all:
  vars:
    lynomia_environment: staging
control_plane:
  hosts:
    cp-1:
      ansible_host: 198.51.100.11
      bmc_password: hunter2
""",
        ("does not declare safety_class", "reads as a secret"),
    ),
    (
        "a group declared in two places is one group: vars on one reach hosts on the other",
        """
all:
  children:
    control_plane:
      vars:
        safety_class: CONFIGURATION_ALLOWED
        bmc_password: hunter2
control_plane:
  hosts:
    cp-1:
      ansible_host: 198.51.100.11
""",
        "reads as a secret",
    ),
    (
        "a class set on one declaration of a group covers hosts declared on another",
        """
all:
  children:
    control_plane:
      vars:
        safety_class: CONFIGURATION_ALLOWED
control_plane:
  hosts:
    cp-1:
      ansible_host: 198.51.100.11
""",
        None,
    ),
    (
        "a group's vars reach its hosts under whichever parent each is declared",
        """
all:
  children:
    a:
      children:
        x:
          vars:
            bmc_password: hunter2
    b:
      children:
        x:
          hosts:
            h1:
              ansible_host: 198.51.100.1
              safety_class: DISCOVERY_ONLY
""",
        "reads as a secret",
    ),
    (
        "a host in two groups is judged on the class Ansible resolves for it",
        # Same depth, same priority: the later name wins, so h1 is
        # DISCOVERY_ONLY -- carrying the allow_reimage it was given under `a`.
        """
all:
  children:
    a:
      vars:
        safety_class: REIMAGE_ALLOWED
      hosts:
        h1:
          ansible_host: 198.51.100.1
          allow_reimage: true
    z:
      vars:
        safety_class: DISCOVERY_ONLY
      hosts:
        h1:
          ansible_host: 198.51.100.1
""",
        "only REIMAGE_ALLOWED may be wiped",
    ),
    (
        "ansible_group_priority reorders groups, as in Ansible",
        """
all:
  children:
    a:
      vars:
        safety_class: REIMAGE_ALLOWED
        ansible_group_priority: 5
      hosts:
        h1:
          ansible_host: 198.51.100.1
          allow_reimage: true
    z:
      vars:
        safety_class: DISCOVERY_ONLY
      hosts:
        h1:
          ansible_host: 198.51.100.1
""",
        None,
    ),
    (
        "a deeper group's class wins over a shallower one's, as in Ansible",
        """
all:
  children:
    outer:
      children:
        inner:
          vars:
            safety_class: CONFIGURATION_ALLOWED
          hosts:
            h1:
              ansible_host: 198.51.100.1
    zed:
      vars:
        safety_class: REIMAGE_ALLOWED
      hosts:
        h1:
          ansible_host: 198.51.100.1
          allow_reimage: true
""",
        "only REIMAGE_ALLOWED may be wiped",
    ),
    (
        "a bare string under hosts: is a host, and is checked",
        """
all:
  vars:
    safety_class: DISCOVERY_ONLY
  children:
    g:
      hosts: stray-1
""",
        "does not declare ansible_host",
    ),
    (
        "a host range Ansible expands into other names is refused, not read as one name",
        """
all:
  children:
    g:
      vars:
        safety_class: DISCOVERY_ONLY
      hosts:
        web[01:03].example:
          ansible_host: 198.51.100.1
""",
        "rewrites into other host names",
    ),
    (
        "a host key carrying a port is refused, not read as one name",
        """
all:
  children:
    g:
      vars:
        safety_class: DISCOVERY_ONLY
      hosts:
        "db-1.example:2222":
          ansible_host: 198.51.100.2
""",
        "rewrites into other host names",
    ),
    (
        "an inventory that declares no host at all is refused",
        """
all:
  vars:
    lynomia_environment: staging
""",
        "declares no host",
    ),
    (
        "an `all` that is not a group mapping is refused",
        """
all: [cp-1, cp-2]
""",
        "'all' is not a group mapping",
    ),
    (
        "groups that contain each other are refused, not walked forever",
        """
all:
  children:
    a:
      children:
        b:
          children:
            a:
          hosts:
            h1:
              ansible_host: 198.51.100.1
              safety_class: DISCOVERY_ONLY
""",
        "groups form a loop",
    ),
    (
        "ungrouped's vars reach a host declared in no other group, as in Ansible",
        """
all:
  hosts:
    lone-1:
      ansible_host: 198.51.100.1
  children:
    ungrouped:
      vars:
        safety_class: DISCOVERY_ONLY
""",
        None,
    ),
    (
        "ungrouped's vars do not reach a host that has a group of its own",
        """
all:
  children:
    ungrouped:
      vars:
        safety_class: DISCOVERY_ONLY
    g:
      hosts:
        grouped-1:
          ansible_host: 198.51.100.2
""",
        "does not declare safety_class",
    ),
    (
        "'all' declared as another group's child is refused",
        """
g:
  children:
    all:
  hosts:
    h1:
      ansible_host: 198.51.100.1
      safety_class: DISCOVERY_ONLY
""",
        "'all' is declared as a child of g",
    ),
    (
        "a dynamic inventory plugin's config is refused: its hosts are not in the file",
        """
plugin: community.general.proxmox
url: https://pve.example
""",
        "configures the inventory plugin",
    ),
]

# The table above is this self-test's subject; emptied, it would print
# `0/0 passed` and exit 0 under a step named "still rejects what it claims to
# reject". The count is literal source in this file, maintained by whoever
# edits the table, so adding or removing a case is a deliberate edit of this
# number too.
EXPECTED_CASES = 40


def run_tree(files: dict[str, str] | None) -> tuple[int, str]:
    """Lay `files` out under ansible/inventories/ and run the whole validator.

    Paths are relative to the inventories directory, so `dev/hosts.yml` is the
    development environment's hosts file. A file whose text begins with `#!` is
    made executable, as an inventory script would be. None builds no
    inventories directory at all. Returns (exit status, everything printed).
    """
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp) / "infrastructure"
        if files is not None:
            base = root / "ansible" / "inventories"
            base.mkdir(parents=True)
            for relative, body in files.items():
                path = base / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(body)
                if body.startswith("#!"):
                    path.chmod(0o755)
        else:
            root.mkdir()
        out = io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(out):
            code = validate.main(["validate-inventory.py", str(root)])
        return code, out.getvalue()


# Ansible given an inventory DIRECTORY -- which is what `-i inventories/staging`
# is -- reads every file in it that its ignore rules do not skip, and merges
# them into one inventory. The validator used to glob `*/hosts.yml`, so an
# environment whose file was renamed `hosts.yaml`, or which gained a second
# file, lost hosts from the check while Ansible kept them.
TREE_CASES: list[tuple[str, dict[str, str] | None, int, str | tuple[str, ...]]] = [
    ("a tree of well-formed environments passes", {"dev/hosts.yml": GOOD}, 0, "1 host(s)"),
    (
        "an environment whose hosts file is named .yaml is read, not skipped",
        {
            "dev/hosts.yml": GOOD,
            "prod/hosts.yaml": GOOD + "          bmc_password: hunter2\n",
        },
        1,
        "reads as a secret",
    ),
    (
        "a second inventory file in an environment is read too",
        {
            "dev/hosts.yml": GOOD,
            "dev/extra.yml": "sidecar:\n  hosts:\n    hidden-1:\n      ansible_host: 198.51.100.201\n",
        },
        1,
        "does not declare safety_class",
    ),
    (
        "an inventory source in a format this does not read is refused",
        {"dev/hosts.yml": GOOD, "dev/legacy.hosts": "[web]\nweb-1 ansible_host=198.51.100.5\n"},
        1,
        "reads only YAML",
    ),
    (
        "a YAML-named source that is not a YAML mapping of groups is refused",
        {"dev/hosts.yml": GOOD, "dev/legacy": "web-1 ansible_host=198.51.100.5\n"},
        1,
        "not a YAML mapping of groups",
    ),
    (
        "a source Ansible would run as an inventory script is refused",
        {"dev/hosts.yml": GOOD, "dev/dynamic.yml": "#!/bin/sh\necho '{\"sidecar\": {\"hosts\": [\"h\"]}}'\n"},
        1,
        "runs it as an inventory script",
    ),
    (
        "a source that does not parse as YAML is refused, not crashed on",
        {"dev/hosts.yml": GOOD, "dev/vaulted.yml": "all:\n  vars:\n    x: !vault |\n      $ANSIBLE_VAULT;1.1;AES256\n"},
        1,
        "does not parse as YAML",
    ),
    (
        "an environment directory with no inventory source in it is refused",
        {"dev/hosts.yml": GOOD, "prod/group_vars/all.yml": "lynomia_environment: production\n"},
        1,
        "no inventory source",
    ),
    (
        "group_vars, hidden files and ignored extensions are not read as inventory",
        {
            "dev/hosts.yml": GOOD,
            "dev/group_vars/all.yml": "safety_class: DISCOVERY_ONLY\n",
            "dev/host_vars/host-1.yml": "allow_reimage: false\n",
            "dev/.editor.yml": "not: [an, inventory\n",
            "dev/README.md": "# notes\n",
            "dev/hosts.yml.orig": "garbage: [\n",
        },
        0,
        "1 host(s)",
    ),
    ("no inventories directory at all is refused", None, 1, "no inventories found"),
]

# Pinned for the same reason, and maintained the same way, as EXPECTED_CASES.
EXPECTED_TREE_CASES = 10


def main() -> int:
    for table, expected_count, label in (
        (CASES, EXPECTED_CASES, "case table"),
        (TREE_CASES, EXPECTED_TREE_CASES, "tree case table"),
    ):
        if len(table) != expected_count:
            print(
                f"the {label} holds {len(table)} case(s) and this file says "
                f"{expected_count}; change both together or neither"
            )
            return 1
    failures = 0
    for name, document, expected in CASES:
        try:
            problems = run(document)
        except Exception as crash:  # a crash fails its case; it does not end the run
            problems = [f"CRASHED: {crash!r}"]
            ok = False
        else:
            if expected is None:
                ok = not problems
            else:
                wanted = (expected,) if isinstance(expected, str) else expected
                ok = all(any(each in p for p in problems) for each in wanted)
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got {problems}")
    for name, files, expected_code, expected in TREE_CASES:
        try:
            code, output = run_tree(files)
        except Exception as crash:  # a crash fails its case; it does not end the run
            code, output = None, f"CRASHED: {crash!r}"
        wanted = (expected,) if isinstance(expected, str) else expected
        ok = code == expected_code and all(each in output for each in wanted)
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected exit {expected_code} and {expected!r}, got exit {code}:\n{output}")
    total = len(CASES) + len(TREE_CASES)
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
