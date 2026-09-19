#!/usr/bin/env python3
"""Proof that the runner trust check calls a forgery a forgery, and nothing else.

The tool's whole value is one distinction: a server that refuses a name it has
no certificate for is honest, and a server that hands back a *verified*
certificate for a name that cannot exist is not. Get that backwards in either
direction and the tool is worse than nothing — it would either clear an
intercepted host or condemn a sound one.

So both directions are exercised against real TLS servers on the loopback
interface. The forging server is genuinely forging: a certificate authority is
generated for the test, placed in this process's trust store, and used to issue
a certificate for the exact name the probe asks about. That is the shape of the
thing the tool exists to catch, and if the tool stops catching it this fails.

Run: python3 infrastructure/scripts/test_check_runner_trust.py
"""

from __future__ import annotations

import importlib.util
import os
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
from pathlib import Path

HERE = Path(__file__).resolve().parent

spec = importlib.util.spec_from_file_location("check_runner_trust", HERE / "check-runner-trust.py")
trust = importlib.util.module_from_spec(spec)
spec.loader.exec_module(trust)

PASSED = 0
FAILED = 0


def check(description: str, condition: bool, detail: str = "") -> None:
    global PASSED, FAILED

    if condition:
        PASSED += 1
        print(f"PASS  {description}")
    else:
        FAILED += 1
        print(f"FAIL  {description}{(' — ' + detail) if detail else ''}")


def openssl(*arguments: str) -> None:
    subprocess.run(["openssl", *arguments], check=True, capture_output=True)


def authority(directory: Path) -> tuple[Path, Path]:
    """A throwaway certificate authority. Lives for one test run."""
    key = directory / "ca.key"
    certificate = directory / "ca.crt"
    openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes",
            "-keyout", str(key), "-out", str(certificate),
            "-days", "1", "-subj", "/CN=runner-trust-test-ca")
    return key, certificate


def issued_for(directory: Path, name: str, ca_key: Path, ca_certificate: Path) -> tuple[Path, Path]:
    """A certificate for `name`, signed by the throwaway authority."""
    key = directory / f"{name}.key"
    request = directory / f"{name}.csr"
    certificate = directory / f"{name}.crt"
    extensions = directory / f"{name}.ext"
    extensions.write_text(f"subjectAltName=DNS:{name}\n")

    openssl("req", "-newkey", "rsa:2048", "-nodes",
            "-keyout", str(key), "-out", str(request), "-subj", f"/CN={name}")
    openssl("x509", "-req", "-in", str(request),
            "-CA", str(ca_certificate), "-CAkey", str(ca_key), "-CAcreateserial",
            "-out", str(certificate), "-days", "1", "-extfile", str(extensions))
    return key, certificate


class Server:
    """A TLS server on the loopback interface, for the length of one `with`."""

    def __init__(self, certificate: Path, key: Path) -> None:
        self._context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        self._context.load_cert_chain(certificate, key)
        self._socket = socket.socket()
        self._socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._socket.bind(("127.0.0.1", 0))
        self._socket.listen(8)
        self.port = self._socket.getsockname()[1]
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._serve, daemon=True)

    def _serve(self) -> None:
        while not self._stop.is_set():
            try:
                raw, _ = self._socket.accept()
            except OSError:
                return

            try:
                with self._context.wrap_socket(raw, server_side=True):
                    pass
            except OSError:
                pass

    def __enter__(self) -> "Server":
        self._thread.start()
        return self

    def __exit__(self, *_: object) -> None:
        self._stop.set()
        self._socket.close()


def main() -> int:
    print("Runner trust check — does it reject what it claims to reject?\n")

    # --- the name it invents ------------------------------------------------
    first, second = trust.impossible_name(), trust.impossible_name()
    check("the probe name is under .invalid, which can never resolve",
          first.endswith(".invalid"), first)
    check("two probe names differ, so the name cannot be special-cased",
          first != second)

    # --- nothing listening --------------------------------------------------
    spare = socket.socket()
    spare.bind(("127.0.0.1", 0))
    closed_port = spare.getsockname()[1]
    spare.close()

    forged, sentence = trust.forged_identity_at("127.0.0.1", closed_port)
    check("a port with nothing behind it is not a forgery", not forged, sentence)

    with tempfile.TemporaryDirectory() as workspace:
        directory = Path(workspace)
        ca_key, ca_certificate = authority(directory)
        name = "runner-trust-fixture.invalid"
        leaf_key, leaf_certificate = issued_for(directory, name, ca_key, ca_certificate)

        # --- an honest server: correct certificate, wrong name asked --------
        # Trusted authority, but the certificate is not for the name the probe
        # asks about, so verification must fail and that must read as honest.
        os.environ["SSL_CERT_FILE"] = str(ca_certificate)

        with Server(leaf_certificate, leaf_key) as server:
            forged, sentence = trust.forged_identity_at("127.0.0.1", server.port)
            check("a server that will not answer to a name it has no certificate for is honest",
                  not forged, sentence)

            # --- a forging server: verified certificate for the impossible name
            forged, sentence = trust.forged_identity_at("127.0.0.1", server.port, name=name)
            check("a VERIFIED certificate for a name that cannot exist is a forgery",
                  forged, sentence)
            check("and the forgery is reported with the name it claimed",
                  name in sentence and "VERIFIED" in sentence, sentence)

        os.environ.pop("SSL_CERT_FILE", None)

        # --- a self-signed server: untrusted, therefore not a forgery -------
        self_signed_key = directory / "self.key"
        self_signed = directory / "self.crt"
        openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes",
                "-keyout", str(self_signed_key), "-out", str(self_signed),
                "-days", "1", "-subj", f"/CN={name}",
                "-addext", f"subjectAltName=DNS:{name}")

        with Server(self_signed, self_signed_key) as server:
            forged, sentence = trust.forged_identity_at("127.0.0.1", server.port, name=name)
            check("an untrusted certificate for that same name is refused, not a forgery",
                  not forged, sentence)

    # --- on-link networks ---------------------------------------------------
    networks = trust.local_networks()
    check("the on-link networks read without iproute2 are /24 prefixes",
          all(prefix.count(".") == 2 for prefix in networks), str(networks))

    print(f"\n{PASSED}/{PASSED + FAILED} passed")
    return 1 if FAILED else 0


if __name__ == "__main__":
    raise SystemExit(main())
