#!/usr/bin/env python3
"""Proof that CI's scan steps fail when the scan fails, and that the workflow
header names the validators that refuse an empty subject.

Why this exists
---------------
Five steps in .github/workflows/ci.yml scan tracked files with grep and fail
on a match. Each used to be written `if <grep pipeline>; then fail; fi`, and an
`if` reads grep's exit status 2 -- an error: an unreadable file, a bad pattern,
a name that is not a file -- exactly as it reads 1, "no match", and passes.
The fake-provider scan also handed its file list to grep through an unquoted
`$templates` and `xargs`, so a tracked name with a space in it became two
names that do not exist, grep failed on both, xargs reported 123, and the
step passed over the file it was there to read.

Each step is taken out of ci.yml by name and run by bash as GitHub runs a
bash step (`bash --noprofile --norc -eo pipefail`), in a throwaway git
repository built for the case. A case either expects the step to pass, or to
fail with a `::error::` line; the error cases are made by a stub `git` or
`grep` first on PATH that fails the one scan the case is about with status 2
and passes every other call through to the real tool. A step that is renamed
fails here rather than being skipped.

What this does not prove: that the patterns catch every credential or
placeholder shape. The cases pin that a match fails the step, that a clean
tree passes it, and that an error is not read as either.

The second half is described at header_problems.

Run: python3 infrastructure/scripts/test_ci_steps.py
Exit 0 when every case behaves, 1 otherwise.
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent

SECRETS = "Fail if a secret or environment file was committed"
INFRA_CREDENTIALS = "Fail if a credential was committed under infrastructure/"
FAKE_PROVIDER = "Fail if a fake provider is configured outside the development template"
PLACEHOLDERS = "Fail on unresolved placeholders in application source"

# A tree each step passes: the files the steps assert present, a development
# template that may say `fake`, a deployment template that does not, and the
# redactor that may carry a credential shape.
CLEAN: dict[str, str] = {
    "apps/control-plane/src/Providers/ProviderRegistryServiceProvider.php": "<?php\n",
    "apps/control-plane/src/Support/SecretRedactor.php": "<?php // matches sk_live_ABCDEFGHIJKL\n",
    "apps/control-plane/tests/Feature/Security/ProductionGuardTest.php": "<?php\n",
    "apps/control-plane/app/Console/Kernel.php": "<?php\n",
    "apps/control-plane/.env.example": "PAYMENT_PROVIDER=fake\n",
    "apps/web/src/main.ts": "export {};\n",
    "infrastructure/ansible/inventories/staging/hosts.yml": "all: {}\n",
    "infrastructure/templates/app.env.example": "PAYMENT_PROVIDER=stripe\n",
}

GIT_STUB = """#!/usr/bin/env bash
if [ "$1" = grep ] && [ -n "${FAIL_GIT_GREP:-}" ]; then
  echo "fatal: simulated failure of git grep" >&2
  exit 2
fi
exec "$REAL_GIT" "$@"
"""

GREP_STUB = """#!/usr/bin/env bash
if [ -n "${FAIL_GREP_ON:-}" ]; then
  for argument in "$@"; do
    case "$argument" in
      *"$FAIL_GREP_ON"*) echo "grep: simulated failure" >&2; exit 2 ;;
    esac
  done
fi
exec "$REAL_GREP" "$@"
"""


def steps() -> dict[str, str]:
    workflow = yaml.safe_load((REPO / ".github" / "workflows" / "ci.yml").read_text())
    found: dict[str, list[str]] = {}
    for job in (workflow.get("jobs") or {}).values():
        for step in job.get("steps") or []:
            if step.get("name") in (SECRETS, INFRA_CREDENTIALS, FAKE_PROVIDER, PLACEHOLDERS):
                found.setdefault(step["name"], []).append(step["run"])
    return {name: runs[0] for name, runs in found.items() if len(runs) == 1}


def run_step(
    body: str, files: dict[str, str], env_extra: dict[str, str] | None = None
) -> tuple[int, str]:
    real_git, real_grep = shutil.which("git"), shutil.which("grep")
    with tempfile.TemporaryDirectory() as tmp:
        tree = Path(tmp) / "repo"
        for relative, text in files.items():
            target = tree / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(text)
        subprocess.run([real_git, "init", "-q", str(tree)], check=True)
        subprocess.run([real_git, "-C", str(tree), "add", "-A"], check=True)
        stubs = Path(tmp) / "bin"
        stubs.mkdir()
        for name, text in (("git", GIT_STUB), ("grep", GREP_STUB)):
            (stubs / name).write_text(text)
            (stubs / name).chmod(0o755)
        script = Path(tmp) / "step.sh"
        script.write_text(body)
        env = dict(
            os.environ,
            PATH=f"{stubs}{os.pathsep}{os.environ['PATH']}",
            REAL_GIT=real_git,
            REAL_GREP=real_grep,
            **(env_extra or {}),
        )
        result = subprocess.run(
            ["bash", "--noprofile", "--norc", "-eo", "pipefail", str(script)],
            cwd=tree, env=env, capture_output=True, text=True,
        )
        return result.returncode, result.stdout + result.stderr


def with_files(**changes: str) -> dict[str, str]:
    """CLEAN plus files; a key is a path with `/` spelt `__` and `.` spelt `_dot_`."""
    files = dict(CLEAN)
    for key, text in changes.items():
        files[key.replace("__", "/").replace("_dot_", ".")] = text
    return files


# (step, case, files, extra environment, expected: None to pass, else a
# string the output must contain; every failure must also carry ::error::)
CASES: list[tuple[str, str, dict[str, str], dict[str, str], str | None]] = [
    (SECRETS, "a clean tree passes", CLEAN, {}, None),
    (SECRETS, "a tracked .env is refused", with_files(apps__web___dot_env="X=1\n"), {}, "A .env file"),
    (SECRETS, "a tracked private key file is refused", with_files(deploy_dot_pem="x\n"), {}, "private key"),
    (
        SECRETS, "a live key in source is refused",
        with_files(apps__web__src__pay_dot_ts="const k = 'sk_live_ABCDEFGHIJKL';\n"), {}, "live credential",
    ),
    (SECRETS, "a git grep that fails is not a clean scan", CLEAN, {"FAIL_GIT_GREP": "1"}, "exited 2"),
    (SECRETS, "a grep over the .env names that fails is not a clean scan", CLEAN, {"FAIL_GREP_ON": "env($"}, "exited 2"),
    (SECRETS, "a grep over the key names that fails is not a clean scan", CLEAN, {"FAIL_GREP_ON": "p12"}, "exited 2"),
    (INFRA_CREDENTIALS, "a clean tree passes", CLEAN, {}, None),
    (
        INFRA_CREDENTIALS, "a tracked state file is refused",
        with_files(infrastructure__tofu__terraform_dot_tfstate="{}\n"), {}, "key or state file",
    ),
    (INFRA_CREDENTIALS, "a grep that fails is not a clean scan", CLEAN, {"FAIL_GREP_ON": "tfstate"}, "exited 2"),
    (FAKE_PROVIDER, "a clean tree, with fake only in the development template, passes", CLEAN, {}, None),
    (
        FAKE_PROVIDER, "fake in a deployment template is refused",
        with_files(infrastructure__templates__app_dot_env_dot_example="PAYMENT_PROVIDER=fake\n"), {},
        "A fake provider is configured",
    ),
    (
        FAKE_PROVIDER, "fake in a template whose name has a space is refused",
        with_files(**{"infrastructure__deploy templates__prod_dot_env": "PAYMENT_PROVIDER=fake\n"}), {},
        "A fake provider is configured",
    ),
    (FAKE_PROVIDER, "a grep that fails is not a clean scan", CLEAN, {"FAIL_GREP_ON": "_PROVIDER"}, "exited 2"),
    (PLACEHOLDERS, "a clean tree passes", CLEAN, {}, None),
    (
        PLACEHOLDERS, "a placeholder in app/ is refused",
        {**CLEAN, "apps/control-plane/app/Jobs/Retry.php": "<?php // TODO: implement backoff\n"}, {},
        "Unresolved implementation placeholder",
    ),
    (PLACEHOLDERS, "a git grep that fails is not a clean scan", CLEAN, {"FAIL_GIT_GREP": "1"}, "exited 2"),
]

# -- The workflow header's list of validators (I-5) -----------------------------
#
# The header at the top of ci.yml lists the validators the Infrastructure job
# runs that refuse an empty subject. It was typed, and when F-39 added
# validate-runbook-alerts.py -- which refuses one -- the list and its count
# were not revisited. So the list is compared here with what the job runs:
# each validator a `run:` step of the infrastructure job calls (a script in
# infrastructure/scripts not named test_*) is run over an empty tree, with the
# argument its step gives it pointed there, and one that exits 1 with a
# message and no traceback refuses its empty subject and must be listed; one
# that does not must not be.
HEADER_LIST = re.compile(
    r"validators the Infrastructure job runs, each refusing its own\s+empty subject:(.*?);",
    re.DOTALL,
)
VALIDATOR_RUN = re.compile(r"python3 infrastructure/scripts/((?!test_)[\w-]+\.py)\s+([\w./-]+)")


def header_problems() -> list[str]:
    text = (REPO / ".github" / "workflows" / "ci.yml").read_text()
    header = "\n".join(
        line.lstrip("#").strip() for line in text.split("\njobs:", 1)[0].splitlines()
        if line.startswith("#")
    )
    listed_match = HEADER_LIST.search(header)
    if not listed_match:
        return ["the ci.yml header no longer lists the validators refusing an empty subject"]
    listed = set(re.findall(r"[\w-]+\.py", listed_match.group(1)))

    workflow = yaml.safe_load(text)
    runs = [
        found
        for step in workflow["jobs"]["infrastructure"]["steps"]
        for found in VALIDATOR_RUN.findall(step.get("run") or "")
    ]
    if not runs:
        return ["the infrastructure job runs no validator this can find"]
    refusing: set[str] = set()
    problems: list[str] = []
    for script, argument in runs:
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp) / "repo"
            (repo / "infrastructure").mkdir(parents=True)
            target = repo / argument
            if not target.is_dir():
                problems.append(f"{script} is given {argument!r}, which this cannot point at an empty tree")
                continue
            result = subprocess.run(
                ["python3", str(HERE / script), str(target)],
                cwd=repo, capture_output=True, text=True,
            )
        if "Traceback" in result.stderr:
            problems.append(f"{script} crashed on an empty tree:\n{result.stderr}")
        elif result.returncode == 1 and result.stderr.strip():
            refusing.add(script)
    if listed != refusing:
        problems.append(
            f"the ci.yml header lists {sorted(listed)} as refusing an empty subject; over an "
            f"empty tree the infrastructure job's validators that refuse are {sorted(refusing)}"
        )
    return problems


def main() -> int:
    found = steps()
    failures = 0
    for name in (SECRETS, INFRA_CREDENTIALS, FAKE_PROVIDER, PLACEHOLDERS):
        if name not in found:
            print(f"FAIL  ci.yml has no single step named {name!r}")
            failures += 1
    for step_name, case, files, env_extra, expected in CASES:
        if step_name not in found:
            continue
        code, output = run_step(found[step_name], files, env_extra)
        if expected is None:
            ok = code == 0 and "::error::" not in output
        else:
            ok = code != 0 and "::error::" in output and expected in output
        print(f"{'PASS' if ok else 'FAIL'}  {step_name}: {case}")
        if not ok:
            failures += 1
            print(f"      expected {expected!r}, got exit {code}:\n" + "\n".join(
                f"        {line}" for line in output.splitlines()[-8:]))
    problems = header_problems()
    print(f"{'PASS' if not problems else 'FAIL'}  the header lists exactly the validators that refuse an empty subject")
    for problem in problems:
        print(f"      {problem}")
    failures += bool(problems)
    total = len(CASES) + 5
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
