#!/usr/bin/env python3
"""Decide whether this host can be believed about where it just connected.

Phase 30B.0 was blocked twice, and both times for the same reason: the host it
ran on sat behind an egress gateway that answered for destinations that did not
exist. A TCP connect to a documentation address succeeded. A TLS handshake to a
hostname reserved by RFC 2606 so that it can never resolve completed, presented
a certificate naming it, and verified — return code 0 — because the gateway's
certificate authority was in the host's trust store.

Every conclusion drawn from such a host is worthless, and worse than worthless,
because it looks like evidence. "The BMC is reachable" was one connect away from
being written down, and Redfish listens on 443.

So before a runner is trusted with real-infrastructure validation, it has to
fail to reach things that are not there. That is what this checks.

  1. A name that cannot exist. A random label under `.invalid` — reserved by
     RFC 2606 precisely so that it never resolves — must not resolve, must not
     connect, and must certainly not yield a certificate that verifies. Any of
     those happening means something upstream is inventing destinations.

  2. Addresses that belong to nobody. RFC 5737 and RFC 3849 set aside ranges for
     documentation. Nothing may answer there. An interceptor that terminates
     every connection answers on 443 regardless of the address, which is how
     `203.0.113.81:443` came to look like a reachable service processor.

  3. A deliberately wrong name for a real target, when one is given. Connect to
     a host the operator names, presenting an SNI that cannot be valid for it,
     and see whether a trusted certificate comes back anyway. This is the
     sharpest of the three and needs a target to point at, so it is optional.

What this does NOT do, deliberately:

  - It never disables certificate verification. The whole failure it exists to
    catch is verification *succeeding* against the wrong thing, and a script
    that turned verification off to "test" would be measuring nothing.
  - It does not fail a host for having a private certificate authority in its
    trust store. An internal PKI is a normal, correct thing for a management
    network to have (see docs/phase-30b-0e-trusted-runner-bootstrap.md §6). The
    question is never "is there a CA?" — it is "does that CA sign names that
    cannot exist?", which is check 1.
  - It does not judge whether the runner can reach the real estate. A host can
    be perfectly honest and still have no route. That is a different question,
    answered by the preflight once provider rows exist.

Exit status 0 when the host can be believed, 1 when it cannot, 2 when the
invocation was wrong.
"""

from __future__ import annotations

import argparse
import os
import secrets
import socket
import ssl
import sys

# Long enough for a slow link, short enough that a hung probe is not mistaken
# for a clean refusal. A refusal is the passing result here, so a timeout that
# is too short would manufacture one.
TIMEOUT_SECONDS = 8

# RFC 5737 TEST-NET-1/2/3 and RFC 3849. Reserved for documentation, which means
# no production network routes them and nothing may answer on them. Port 443
# because that is the port an interceptor terminates and the port a Redfish
# service processor listens on — the exact collision that produced the false
# positive.
DOCUMENTATION_ADDRESSES = (
    ("192.0.2.1", 443),
    ("198.51.100.1", 443),
    ("203.0.113.1", 443),
    ("203.0.113.81", 443),
)


class Finding:
    """One check, its verdict, and the sentence a person reads."""

    def __init__(self, name: str, ok: bool, detail: str) -> None:
        self.name = name
        self.ok = ok
        self.detail = detail


def impossible_name() -> str:
    """A name under `.invalid` that has never been used before.

    Random rather than fixed: a fixed name could, in principle, be special-cased
    by whatever is being tested, and a check that can be special-cased is a
    check somebody will eventually special-case.
    """
    return f"runner-trust-{secrets.token_hex(8)}.invalid"


def local_networks() -> set[str]:
    """The /24s this host is directly attached to, read without `ip`.

    An on-link address answering is the host's own network and says nothing
    about interception, so those are excluded from check 2. Read from
    /proc/net/route because the minimal images this runs on have no iproute2.
    """
    nets: set[str] = set()
    try:
        with open("/proc/net/route", encoding="ascii") as handle:
            next(handle, None)
            for line in handle:
                fields = line.split()
                if len(fields) < 8 or fields[2] != "00000000":
                    continue
                packed = int(fields[1], 16).to_bytes(4, "little")
                nets.add(".".join(str(b) for b in packed[:3]))
    except OSError:
        pass
    return nets


def check_impossible_name() -> Finding:
    """An unresolvable name must stay unresolvable, all the way to the handshake."""
    name = impossible_name()

    try:
        addresses = sorted({info[4][0] for info in socket.getaddrinfo(name, 443)})
    except socket.gaierror:
        return Finding(
            "impossible name",
            True,
            f"{name} does not resolve, which is what a reserved name is for.",
        )
    except OSError as problem:
        return Finding("impossible name", True, f"{name} could not be looked up: {problem}.")

    # It resolved. That alone is a hijacked resolver, but keep going: whether a
    # *trusted certificate* comes back is the difference between a wildcard DNS
    # answer and something that will fabricate a provider's identity.
    context = ssl.create_default_context()

    try:
        with socket.create_connection((name, 443), TIMEOUT_SECONDS) as raw:
            with context.wrap_socket(raw, server_hostname=name) as tls:
                subject = dict(x[0] for x in tls.getpeercert().get("subject", ()))
                common = subject.get("commonName", "<no common name>")
    except ssl.SSLError as refused:
        return Finding(
            "impossible name",
            False,
            f"{name} resolved to {', '.join(addresses)} — a name RFC 2606 reserves so that it "
            f"cannot resolve. The handshake was refused ({refused.reason or 'verification failed'}), "
            "so nothing forged an identity, but the resolver is answering for names that do not "
            "exist and cannot be believed about names that do.",
        )
    except OSError as problem:
        return Finding(
            "impossible name",
            False,
            f"{name} resolved to {', '.join(addresses)} but did not connect ({problem}). A name "
            "that cannot exist must not resolve at all.",
        )

    return Finding(
        "impossible name",
        False,
        f"{name} resolved to {', '.join(addresses)} and presented a certificate for "
        f"'{common}' that THIS HOST VERIFIED. Something upstream is minting trusted identities "
        "for destinations that do not exist, so no reachability or identity result from this "
        "host means anything.",
    )


def forged_identity_at(address: str, port: int, name: str | None = None) -> tuple[bool, str]:
    """Ask an address for a name that cannot be valid for it, and see what it says.

    Returns (forged, sentence). An honest endpoint either refuses the handshake
    or presents its own certificate, which then fails hostname verification —
    both are refusals and both are correct. Only a verified certificate for a
    name that cannot exist is a forgery, and that is the one case this reports
    as true.

    `name` exists so the self-test can ask about a name it has arranged a
    certificate for. Nothing in the tool passes it: every real probe uses a
    fresh {@see impossible_name}, because a name chosen in advance is a name
    that can be special-cased.
    """
    wrong = name or impossible_name()
    context = ssl.create_default_context()

    try:
        with socket.create_connection((address, port), TIMEOUT_SECONDS) as raw:
            with context.wrap_socket(raw, server_hostname=wrong) as tls:
                subject = dict(x[0] for x in tls.getpeercert().get("subject", ()))
                common = subject.get("commonName", "<no common name>")
    except ssl.SSLError as refused:
        return False, (
            f"refused the handshake for '{wrong}' ({refused.reason or 'verification failed'})"
        )
    except OSError as problem:
        return False, f"did not complete a handshake for '{wrong}' ({problem})"

    return True, (
        f"answered a request for '{wrong}' with a certificate for '{common}' that THIS HOST "
        "VERIFIED"
    )


def check_documentation_addresses() -> Finding:
    """Nothing may answer on an address reserved for documentation.

    And when something does, the interesting question is not that it answered
    but whether it will claim to be anything asked of it — so an address that
    answers is immediately asked for a name that cannot exist. That turns a
    suspicious connect into the unambiguous finding, without needing the
    operator to supply a real target first.
    """
    skip = local_networks()
    answered: list[tuple[str, int]] = []
    probed = 0

    for address, port in DOCUMENTATION_ADDRESSES:
        if address.rsplit(".", 1)[0] in skip:
            # On-link: this host's own network, not an interceptor.
            continue

        probed += 1

        try:
            with socket.create_connection((address, port), TIMEOUT_SECONDS):
                answered.append((address, port))
        except OSError:
            continue

    if probed == 0:
        return Finding(
            "documentation addresses",
            True,
            "Every reserved address checked is on this host's own network, so none was probed. "
            "Check 1 is the one that matters here.",
        )

    if not answered:
        return Finding(
            "documentation addresses",
            True,
            f"None of the {probed} reserved address(es) answered, which is correct.",
        )

    forgeries = []
    refusals = []

    for address, port in answered:
        forged, sentence = forged_identity_at(address, port)
        (forgeries if forged else refusals).append(f"{address}:{port} {sentence}")

    if forgeries:
        return Finding(
            "documentation addresses",
            False,
            "These ranges are reserved for documents and route nowhere, so nothing may answer "
            f"there — and {len(forgeries)} of the {len(answered)} that did will claim any identity "
            "asked of it: "
            + "; ".join(forgeries)
            + ". No reachability or identity result from this host means anything. A service "
            "processor on 443 would look reachable whether or not it exists.",
        )

    return Finding(
        "documentation addresses",
        False,
        f"{', '.join(f'{a}:{p}' for a, p in answered)} answered, on ranges reserved for documents "
        "that route nowhere. Nothing forged an identity when asked — "
        + "; ".join(refusals)
        + " — but something is terminating connections regardless of their destination, so a "
        "connect from this host is not evidence that a destination exists.",
    )


def split_target(target: str) -> tuple[str, int]:
    """Split HOST[:PORT] into its parts, including for an IPv6 literal.

    An IPv6 address is full of colons, so `partition(":")` turns `fd00::1` into
    the host `fd00` on a port that will not parse. Bracket form is the way a URL
    has always disambiguated it, and a BMC on a unique-local address is an
    ordinary thing to be asked about — `EndpointPolicy` accepts one.

    Raises ValueError with a sentence the operator can act on, rather than
    letting `int()` raise a traceback at them for a typo.
    """
    rest = target.strip()

    if rest.startswith("["):
        closing = rest.find("]")

        if closing == -1:
            raise ValueError(f"{target!r} opens a bracket for an IPv6 address and never closes it.")

        host, remainder = rest[1:closing], rest[closing + 1 :]

        if remainder and not remainder.startswith(":"):
            raise ValueError(f"{target!r} has {remainder!r} after the address, which is not a port.")

        port_text = remainder[1:]
    elif rest.count(":") > 1:
        # An unbracketed IPv6 literal. Accept it with the default port rather
        # than guessing which colon was meant to be the separator.
        host, port_text = rest, ""
    else:
        host, _, port_text = rest.partition(":")

    if host == "":
        raise ValueError(f"{target!r} names no host.")

    if port_text == "":
        return host, 443

    if not port_text.isdigit() or not 1 <= int(port_text) <= 65535:
        raise ValueError(f"{target!r} has {port_text!r} where a port between 1 and 65535 should be.")

    return host, int(port_text)


def check_wrong_name_for(target: str) -> Finding:
    """Present a name that cannot be valid for a real host and see what comes back."""
    host, port = split_target(target)
    forged, sentence = forged_identity_at(host, port)

    if forged:
        return Finding(
            "wrong name for a real target",
            False,
            f"{host}:{port} {sentence}. It is not the real server: it is something in the path "
            "that will present whatever name it is asked for.",
        )

    return Finding(
        "wrong name for a real target",
        True,
        f"{host}:{port} {sentence}, which is what a real server does with a name it holds no "
        "certificate for.",
    )


def proxy_note() -> str | None:
    """Name the usual cause, without treating its presence as the verdict."""
    named = [
        variable
        for variable in ("HTTPS_PROXY", "https_proxy", "HTTP_PROXY", "http_proxy", "ALL_PROXY")
        if os.environ.get(variable)
    ]

    if not named:
        return None

    return (
        f"{', '.join(sorted(set(named)))} is set in this environment. A proxy is not by itself a "
        "failure — a policy proxy that refuses a host is honest — but a proxy that terminates TLS "
        "is how both earlier runs came to verify certificates for destinations that were not "
        "there. If a check below failed, start here."
    )


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(
        description="Decide whether this host can be believed about where it connected.",
    )
    parser.add_argument(
        "--target",
        action="append",
        default=[],
        metavar="HOST[:PORT]",
        help="A real host to probe with a deliberately wrong name. Repeatable. "
        "Read-only: one handshake, no credential, no request.",
    )
    arguments = parser.parse_args(argv[1:])

    # Every target is parsed before anything is dialled, so a typo in the third
    # one does not surface after two probes have already run.
    for target in arguments.target:
        try:
            split_target(target)
        except ValueError as wrong:
            print(f"Cannot read --target: {wrong}", file=sys.stderr)
            return 2

    findings = [check_impossible_name(), check_documentation_addresses()]
    findings.extend(check_wrong_name_for(target) for target in arguments.target)

    print("Runner trust — can this host be believed about its destinations?\n")

    for finding in findings:
        print(f"[{'PASS' if finding.ok else 'FAIL'}] {finding.name}")
        print(f" {finding.detail}\n")

    note = proxy_note()

    if note:
        print(f"  NOTE {note}\n")

    failed = [finding for finding in findings if not finding.ok]

    if failed:
        print(
            f"NOT TRUSTED — {len(failed)} of {len(findings)} check(s) failed. This host must not "
            "be used for real-infrastructure validation, and any reachability or identity result "
            "already taken from it should be discarded rather than re-examined."
        )
        return 1

    if not arguments.target:
        print(
            f"TRUSTED so far — {len(findings)} check(s) passed, and no --target was given. The "
            "sharpest check is the third one; run it again against a real endpoint once the "
            "private inventory names one."
        )
        return 0

    print(
        f"TRUSTED — {len(findings)} check(s) passed, including {len(arguments.target)} against a "
        "named real target. This host does not fabricate destination identity. Whether it can "
        "route to the estate is a separate question."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
