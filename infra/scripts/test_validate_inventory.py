#!/usr/bin/env python3
"""Proof that the inventory validator rejects what it claims to reject.

Run: python3 infra/scripts/test_validate_inventory.py
"""

from __future__ import annotations

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
        "a host with no owner is refused",
        GOOD.replace('          owner: "an owner"\n', ""),
        "does not declare owner",
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
              safety_class: DO_NOT_TOUCH
""",
        "does not declare owner",
    ),
]


def main() -> int:
    failures = 0
    for name, document, expected in CASES:
        problems = run(document)
        if expected is None:
            ok = not problems
        else:
            ok = any(expected in p for p in problems)
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got {problems}")
    print(f"\n{len(CASES) - failures}/{len(CASES)} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
