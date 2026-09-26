#!/usr/bin/env python3
"""Proof that `tofu validate` in CI runs where the configuration is (F-38).

Why this exists
---------------
The CI step "OpenTofu is formatted and valid" ran `tofu validate` only in
infrastructure/tofu/environments/*/, which hold no configuration, and OpenTofu
calls an empty directory valid. The step passed on every run and parsed
nothing. It now validates each root module list-tofu-root-modules.py prints.

Two halves are held here. The discovery script is run over synthetic trees
that differ in one respect each. And the CI step itself is taken out of
.github/workflows/ci.yml and run by bash exactly as written, with a stub
`tofu` first on PATH that records the directory and arguments of each call:
over this repository, every directory it validates must hold configuration
and together they must be every root the script finds, each initialised with
-backend=false first; over a tree with no configuration, the step must fail.
The stub stands in for OpenTofu, so what this proves is where the step runs
validate and that it refuses an empty subject -- not that the configuration
is valid, which only the real binary in CI can say.

Run: python3 infrastructure/scripts/test_list_tofu_root_modules.py
Exit 0 when every case behaves, 1 otherwise.
"""

from __future__ import annotations

import contextlib
import importlib.util
import io
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
SCRIPT = HERE / "list-tofu-root-modules.py"
STEP_NAME = "OpenTofu is formatted and valid"

spec = importlib.util.spec_from_file_location("list_tofu_root_modules", SCRIPT)
lister = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lister)

ROOT_MAIN = """
module "vm" {
  source = "./modules/vm"
}

module "dns" {
  source = "./modules/dns"
}
"""

MODULE = 'variable "name" {\n  type = string\n}\n'

# The root calling the vm module only.
ONLY_VM = 'module "vm" {\n  source = "./modules/vm"\n}\n\n'

# This repository's shape: a root with two modules, and environment
# directories holding only templates.
LAYOUT = {
    "infrastructure/tofu/main.tf": ROOT_MAIN,
    "infrastructure/tofu/modules/vm/main.tf": MODULE,
    "infrastructure/tofu/modules/dns/main.tf": MODULE,
    "infrastructure/tofu/environments/staging/backend.hcl.example": 'bucket = "x"\n',
    "infrastructure/tofu/environments/staging/terraform.tfvars.example": 'environment = "staging"\n',
}


def with_files(base: dict[str, str | None], **changes: str | None) -> dict[str, str | None]:
    """`base` with each change applied; a key is a path with `/` spelt `__`."""
    files = dict(base)
    for key, body in changes.items():
        files[key.replace("__", "/")] = body
    return {path: body for path, body in files.items() if body is not None}


# A body starting with this makes the path a symbolic link to the rest.
SYMLINK = "\0symlink:"


def build(root: Path, files: dict[str, str]) -> None:
    for relative, body in files.items():
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        if body.startswith(SYMLINK):
            target.symlink_to(body[len(SYMLINK):], target_is_directory=True)
        else:
            target.write_text(body)


def discover(files: dict[str, str]) -> tuple[int, str, str]:
    with tempfile.TemporaryDirectory() as tmp:
        build(Path(tmp), files)
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = lister.main(["list-tofu-root-modules.py", tmp])
        return code, out.getvalue(), err.getvalue()


# (name, files, expected roots or None for a refusal, text the refusal names)
CASES: list[tuple[str, dict[str, str], list[str] | None, str]] = [
    (
        "this repository's shape gives the one root, not the template directories",
        LAYOUT, ["infrastructure/tofu"], "",
    ),
    (
        "a tree with only .example templates is refused",
        with_files(LAYOUT, **{
            "infrastructure/tofu/main.tf": None,
            "infrastructure/tofu/modules/vm/main.tf": None,
            "infrastructure/tofu/modules/dns/main.tf": None,
        }),
        None, "no configuration file",
    ),
    (
        "a repository with no infrastructure/tofu at all is refused",
        {"README.md": "nothing here\n"}, None, "does not exist",
    ),
    (
        "a module no root calls is validated as a root of its own",
        with_files(LAYOUT, **{"infrastructure/tofu/modules/orphan/main.tf": MODULE}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/orphan"], "",
    ),
    (
        "a call commented out with # leaves its module a root of its own",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ROOT_MAIN.replace(
            '  source = "./modules/dns"', '  # source = "./modules/dns"')}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/dns"], "",
    ),
    # A call that is commented out is not a call, wherever the comment is: the
    # module it names is then a root of its own and validated, not counted as
    # reached through a root that never loads it (B2).
    (
        "a call inside a /* */ block leaves its module a root of its own",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ROOT_MAIN.replace(
            'module "dns" {', '/*\nmodule "dns" {').rstrip() + "\n*/\n"}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/dns"], "",
    ),
    (
        "a call after a trailing # leaves its module a root of its own",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ONLY_VM
                              + 'locals {\n  z = 1 # source = "./modules/dns"\n}\n'}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/dns"], "",
    ),
    (
        "a call after a trailing // leaves its module a root of its own",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ONLY_VM
                              + 'locals {\n  z = 1 // source = "./modules/dns"\n}\n'}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/dns"], "",
    ),
    (
        "a one-line /* */ block holding a call leaves its module a root of its own",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ONLY_VM
                              + '/* module "dns" { source = "./modules/dns" } */\n'}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/dns"], "",
    ),
    # Inside a quoted string none of the three starts a comment: each line
    # below still names ./modules/dns after the marker, so dns stays called.
    (
        "a # inside a quoted string is not a comment",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ONLY_VM
                              + 'locals {\n  m = { note = "a # b", source = "./modules/dns" }\n}\n'}),
        ["infrastructure/tofu"], "",
    ),
    (
        "a // inside a quoted string is not a comment",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ONLY_VM
                              + 'locals {\n  m = { note = "https://x", source = "./modules/dns" }\n}\n'}),
        ["infrastructure/tofu"], "",
    ),
    (
        "a /* inside a quoted string is not a comment",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ONLY_VM
                              + 'locals {\n  m = { note = "a /* b", source = "./modules/dns" }\n}\n'}),
        ["infrastructure/tofu"], "",
    ),
    (
        "an unterminated /* block is refused",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ROOT_MAIN + "/* never closed\n"}),
        None, "unterminated /* comment",
    ),
    (
        # HCL ends a block comment at the first */, so an inner /* is text and
        # the outer */ is left over; reading it any way would be a guess.
        "a nested /* block is refused",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ROOT_MAIN + "/* a /* b */ c */\n"}),
        None, "nested /* comment",
    ),
    (
        "a call to a directory with no configuration is refused",
        with_files(LAYOUT, **{"infrastructure/tofu/modules/dns/main.tf": None}),
        None, "which holds no configuration",
    ),
    (
        "a .tofu file is configuration",
        with_files(LAYOUT, **{
            "infrastructure/tofu/modules/vm/main.tf": None,
            "infrastructure/tofu/modules/vm/main.tofu": MODULE,
        }),
        ["infrastructure/tofu"], "",
    ),
    (
        "a JSON module call is read",
        with_files(LAYOUT, **{
            "infrastructure/tofu/main.tf": None,
            "infrastructure/tofu/main.tf.json": (
                '{"module": {"vm": {"source": "./modules/vm"}, "dns": {"source": "./modules/dns"}}}'
            ),
        }),
        ["infrastructure/tofu"], "",
    ),
    (
        "OpenTofu's own .terraform directory is not a module",
        with_files(LAYOUT, **{"infrastructure/tofu/.terraform/modules/vm/main.tf": MODULE}),
        ["infrastructure/tofu"], "",
    ),
    (
        # A root and its modules validate; a pair that call only each other
        # beside them is reached from no root, so nothing would validate it.
        "modules that call only each other, beside a real root, are refused",
        with_files(LAYOUT, **{
            "infrastructure/tofu/modules/a/main.tf": 'module "b" {\n  source = "../b"\n}\n',
            "infrastructure/tofu/modules/b/main.tf": 'module "a" {\n  source = "../a"\n}\n',
        }),
        None, "reached from no root module",
    ),
    (
        "a source line inside a heredoc is text, not a call",
        with_files(LAYOUT, **{"infrastructure/tofu/main.tf": ROOT_MAIN.replace(
            'module "dns" {\n  source = "./modules/dns"\n}\n',
            'locals {\n  note = <<-EOT\n    source = "./modules/dns"\n  EOT\n}\n')}),
        ["infrastructure/tofu", "infrastructure/tofu/modules/dns"], "",
    ),
    (
        # os.walk does not follow a symbolic link to a directory, so a module
        # behind one would never be found; it is refused rather than followed,
        # so what is validated is not decided by where a link points.
        "a symbolic link to a directory under infrastructure/tofu is refused",
        with_files(LAYOUT, **{"infrastructure/tofu/modules/linked": SYMLINK + "vm"}),
        None, "is a symbolic link to a directory",
    ),
    (
        "modules that only call each other leave no root, and are refused",
        {
            "infrastructure/tofu/a/main.tf": 'module "b" {\n  source = "../b"\n}\n',
            "infrastructure/tofu/b/main.tf": 'module "a" {\n  source = "../a"\n}\n',
        },
        None, "none is a root",
    ),
]

STUB = """#!/usr/bin/env bash
printf '%s\\t%s\\n' "$PWD" "$*" >> "$TOFU_LOG"
"""


def ci_step() -> str:
    workflow = yaml.safe_load((REPO / ".github" / "workflows" / "ci.yml").read_text())
    found = [
        step["run"]
        for job in (workflow.get("jobs") or {}).values()
        for step in job.get("steps") or []
        if step.get("name") == STEP_NAME
    ]
    if len(found) != 1:
        raise AssertionError(f"ci.yml has {len(found)} step(s) named {STEP_NAME!r}, not one")
    return found[0]


def run_step(tree: Path) -> tuple[int, list[tuple[Path, str]], str]:
    """Run the CI step in `tree` as GitHub runs a bash step, with the stub
    tofu. (exit status, [(directory, arguments)], output)."""
    with tempfile.TemporaryDirectory() as tmp:
        stub_dir = Path(tmp) / "bin"
        stub_dir.mkdir()
        stub = stub_dir / "tofu"
        stub.write_text(STUB)
        stub.chmod(0o755)
        log = Path(tmp) / "tofu.log"
        log.touch()
        script = Path(tmp) / "step.sh"
        script.write_text(ci_step())
        env = dict(os.environ, PATH=f"{stub_dir}{os.pathsep}{os.environ['PATH']}", TOFU_LOG=str(log))
        result = subprocess.run(
            ["bash", "--noprofile", "--norc", "-eo", "pipefail", str(script)],
            cwd=tree, env=env, capture_output=True, text=True,
        )
        calls = [
            (Path(directory).resolve(), arguments)
            for directory, _, arguments in (line.partition("\t") for line in log.read_text().splitlines())
        ]
        return result.returncode, calls, result.stdout + result.stderr


def step_problems(tree: Path, expected_roots: list[Path]) -> list[str]:
    code, calls, output = run_step(tree)
    problems: list[str] = []
    if code != 0:
        return [f"the step exited {code}:\n{output}"]
    validated = [directory for directory, arguments in calls if arguments.split()[:1] == ["validate"]]
    if not validated:
        problems.append("the step ran `tofu validate` nowhere")
    for directory in validated:
        if not lister.configuration_files(directory):
            problems.append(f"the step validates {directory}, which holds no configuration")
        before = calls[: next(i for i, (d, a) in enumerate(calls) if d == directory and a.startswith("validate"))]
        if not any(d == directory and a.startswith("init") and "-backend=false" in a.split() for d, a in before):
            problems.append(f"the step validates {directory} without `init -backend=false` there first")
    if sorted(set(validated)) != sorted(root.resolve() for root in expected_roots):
        problems.append(
            f"the step validates {sorted(map(str, set(validated)))}, and the roots are "
            f"{sorted(map(str, expected_roots))}"
        )
    return problems


def main() -> int:
    failures = 0

    def report(name: str, problems: list[str]) -> None:
        nonlocal failures
        print(f"{'PASS' if not problems else 'FAIL'}  {name}")
        for problem in problems:
            print(f"      {problem}")
        failures += bool(problems)

    for name, files, expected, refusal in CASES:
        code, out, err = discover(files)
        if expected is None:
            ok = code == 1 and refusal in err and not out.strip()
        else:
            ok = code == 0 and out.split() == expected and not err.strip()
        report(name, [] if ok else [f"exit {code}, stdout {out.strip()!r}, stderr {err.strip()!r}"])

    # The step over this repository.
    try:
        roots, problems = lister.root_modules(REPO)
        report(
            "the CI step validates every root module here, and only directories with configuration",
            problems or step_problems(REPO, roots),
        )
    except Exception as error:  # noqa: BLE001
        report("the CI step validates every root module here, and only directories with configuration", [repr(error)])

    # The step over trees shaped like this one, and like the one it used to
    # pass over: with the configuration gone, it must fail, not validate
    # the template directories.
    for name, files, expected, must_fail in (
        ("the CI step, over this repository's shape, validates the root", LAYOUT, ["infrastructure/tofu"], False),
        (
            "the CI step fails over a tree with only template directories",
            with_files(LAYOUT, **{
                "infrastructure/tofu/main.tf": None,
                "infrastructure/tofu/modules/vm/main.tf": None,
                "infrastructure/tofu/modules/dns/main.tf": None,
            }),
            [], True,
        ),
    ):
        with tempfile.TemporaryDirectory() as tmp:
            tree = Path(tmp)
            build(tree, files)
            (tree / "infrastructure" / "scripts").mkdir(parents=True, exist_ok=True)
            shutil.copy(SCRIPT, tree / "infrastructure" / "scripts" / SCRIPT.name)
            try:
                if must_fail:
                    code, calls, output = run_step(tree)
                    validated = [d for d, a in calls if a.startswith("validate")]
                    report(name, [] if code != 0 and not validated else [
                        f"exit {code}, validated {[str(d) for d in validated]}:\n{output}"
                    ])
                else:
                    report(name, step_problems(tree, [tree / root for root in expected]))
            except Exception as error:  # noqa: BLE001
                report(name, [repr(error)])

    total = len(CASES) + 3
    print(f"\n{total - failures}/{total} passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
