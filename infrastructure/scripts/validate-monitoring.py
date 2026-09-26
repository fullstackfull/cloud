#!/usr/bin/env python3
"""Keep the alert rules pointed at metrics that exist.

An alert on a metric nobody emits never fires. It looks like coverage in a
review and is silence in an incident, which is the worst of both.

A lynomia_* metric legitimately comes from one of two places, so this checks
both before calling a rule dead:

  1. The control plane's own /metrics endpoint, discovered by reading the
     metric names out of the PHP source.
  2. A textfile collector on a machine that has no Prometheus endpoint of its
     own — PBS, for instance. Those have no code in this repository at all, so
     their contract is declared in infrastructure/monitoring/README.md and
     parsed from there.

It also checks that every rule says where the operator should look — a
`runbook` path inside this repository, which must exist, or a `runbook_url`
pointing outside it, which is taken on trust — because "page somebody at 4am
with no instructions" is not monitoring.

It walks Alertmanager's route tree for every alert and requires each walk to
end at a receiver the file defines. A few alerts are pinned further, in
PINNED_ROUTES: their destination is itself the fix for a finding, so the
receiver the route tree selects for them, the series they read and how long
they wait are asserted rather than merely resolved. A series in NEVER_PAGES
may not be read by a critical rule. This is here, over PyYAML, rather than in
a PHP test over a hand-written YAML reader: a second model of a file can be
wrong in ways the file never is, and Alertmanager's config is parsed by a real
YAML parser.

A walk of the route tree is itself a model of Alertmanager, and it is only
worth what that model is. So it is bounded, and each bound is enforced rather
than assumed. Given the YAML as PyYAML parses it -- a text go-yaml's parser
reads differently is outside this bound; what Alertmanager then decodes from
the parsed document is not, and is ported or refused below -- and for an alert
carrying exactly the labels the walk is given, the receivers the walk returns
are the ones Alertmanager v0.28's route tree selects (dispatch.Route.Match),
for every file this accepts, because everything the walk would otherwise have
to guess is refused instead:

  * Matchers are read by a line-for-line port of Alertmanager's classic parser
    (pkg/labels/parse.go). With no --enable-feature flag, v0.28 runs both of
    its parsers and uses the classic one's result whenever that parser accepts
    the line (matcher/compat/parse.go). A line only the newer UTF-8 parser
    accepts is refused, not modelled.
  * A regex matcher is compiled by Go's RE2 and walked by Python's re. It is
    refused unless it stays inside the subset on which the two read the same
    language (portable_regex_problem).
  * A key written twice, and a non-string where Alertmanager reads a string or
    a non-boolean where it reads a boolean, are refused: go-yaml refuses the
    first and types the others differently from PyYAML.
  * A YAML merge key (`<<`) is refused. go-yaml parses it as PyYAML does, but
    Alertmanager decodes a route field by field, applying the merge where it
    stands and adding an explicit `matchers:` or `match:` to the merged one,
    where PyYAML's flattened mapping lets the explicit key replace it
    (MergeKey).
  * The compose file must run the Alertmanager this models, without the flag
    that changes which parser wins (MODELLED_ALERTMANAGER).
  * A pinned alert is walked with only the labels its rule fixes. A route
    whose match depends on any other label -- one the series or Prometheus's
    external labels supply -- is refused for it, and so is alert relabelling
    in prometheus.yml, which would change the labels Alertmanager is given.

What the walk does not decide is whether a notification is sent at a given
moment: inhibition, silences and time intervals act after the route tree has
chosen a receiver, and none of them is modelled here.

It refuses a Loki ruler wired to an Alertmanager with no rule files mounted
where its local store reads them: in a tenant directory under its rules
directory. A ruler pointed at an empty directory looks configured and
evaluates nothing, which is worse than no ruler.

Finally it checks that the directories infrastructure/README.md claims exist
actually do. That check lives here because the first thing it caught was a
declared textfile collector whose directory was never created, which is exactly
how six backup alerts came to be incapable of firing.

Exit status 0 when the configuration is consistent, 1 otherwise.
"""

from __future__ import annotations

import re
import string
import sys
import warnings
from pathlib import Path, PurePosixPath

try:
    import yaml
except ImportError:  # pragma: no cover
    print("PyYAML is required: pip install pyyaml", file=sys.stderr)
    raise SystemExit(2)

METRIC_IN_SOURCE = re.compile(r"lynomia_[a-z0-9_]+")
METRIC_IN_EXPR = re.compile(r"lynomia_[a-z0-9_]+")

# Alerts whose destination is itself a remediation. Resolving to *some*
# receiver is not enough for these: relabelling the drift page to
# `component: backups` still reaches a receiver that exists -- the storage
# team's -- and every generic check here would pass while the on-call never
# hears about a customer paying for a machine the hypervisor does not have.
#
# `expr` is compared whole, whitespace collapsed, rather than parsed: each
# expression is a decision, and changing it is meant to mean changing the pin
# in the same commit, knowingly. `for` is compared as written, for the same
# reason: the runbook tells the responder how long each condition has held.
PINNED_ROUTES: dict[str, dict[str, str]] = {
    # F-22. Critical drift pages the on-call. It reads the series that counts
    # `open` AND `acknowledged` rows, so acknowledging a drift in the operator
    # screen does not silence the page; only resolving it does. Critical only:
    # the severities exist so that an orphan on a node an operator also uses
    # by hand does not page anybody at night.
    "ResourceDriftOpen": {
        "receiver": "pagerduty-critical",
        "reads": "lynomia_resource_drift_open",
        "expr": 'lynomia_resource_drift_open{severity="critical"} > 0',
        "for": "15m",
    },
    # F-22. Drift nobody has looked at, of any severity, reaches the platform
    # channel. This is the one acknowledging clears, by design: it asks for a
    # review, and a review is what acknowledging records.
    "DriftQueueUnworked": {
        "receiver": "platform-team",
        "reads": "lynomia_open_drift_total",
        "expr": "sum(lynomia_open_drift_total) > 0",
        "for": "24h",
    },
}

# Series no critical rule may read, and why.
NEVER_PAGES: dict[str, str] = {
    "lynomia_open_drift_total": (
        "counts `open` drift only, so a page on it is silenced by clicking "
        "acknowledge; page on lynomia_resource_drift_open instead"
    ),
}

# Every key Alertmanager's `Route` accepts (v0.28). Alertmanager refuses an
# unknown key at load, and so does this: a key the walk does not understand is
# a key that might change where an alert goes.
ROUTE_KEYS = frozenset({
    "receiver", "group_by", "continue", "matchers", "match", "match_re",
    "group_wait", "group_interval", "repeat_interval",
    "mute_time_intervals", "active_time_intervals", "routes",
})

# The Alertmanager the matcher port below was taken from. Another version may
# parse a matcher differently, so the compose file is held to this one; a bump
# means re-reading matcher/compat/parse.go and pkg/labels/parse.go at the new
# tag, correcting the port if they changed, and then widening this.
MODELLED_ALERTMANAGER = re.compile(r"^prom/alertmanager:v0\.28\.[0-9]+$")

# The one feature flag that changes how v0.28 reads a matcher: with it, only
# the UTF-8 parser runs, and the classic port below stops describing it.
# (`classic-mode` runs only the classic parser, which the port is.)
UNMODELLED_ALERTMANAGER_FEATURE = "utf8-strict-mode"

# Go's regexp `\s`, which is narrower than Python's.
_GO_RE_SPACE = "[\t\n\f\r ]"

# Go's unicode.IsSpace, which strings.TrimSpace uses. Narrower than what
# str.strip() removes, which also takes U+001C..U+001F.
_GO_UNICODE_SPACE = "\t\n\v\f\r \x85\xa0" + "".join(
    chr(code) for code in (0x1680, *range(0x2000, 0x200B), 0x2028, 0x2029, 0x202F, 0x205F, 0x3000)
)

# pkg/labels/parse.go's `re`, with its `\s` spelled out and its `$` (end of
# text, in Go) written as Python's `\Z`.
_CLASSIC_MATCHER = re.compile(
    rf"^{_GO_RE_SPACE}*([a-zA-Z_:][a-zA-Z0-9_:]*){_GO_RE_SPACE}*(=~|=|!=|!~)"
    rf"{_GO_RE_SPACE}*(.*?){_GO_RE_SPACE}*\Z",
    re.DOTALL,
)

# model.LabelNameRE, which a legacy `match:` or `match_re:` key must satisfy.
_LABEL_NAME = re.compile(r"^[a-zA-Z_][a-zA-Z0-9_]*\Z")

# What may follow a backslash in a portable regex. ASCII punctuation is itself
# in both engines; d, D, w and W are the same classes in both once Python is
# told re.ASCII. Every other escape is refused: \s differs on \v, \b means
# backspace inside a class in Python and is an error in Go, \pL, \z, \Q...\E
# and \x{..} exist only in Go, \Z, \u and backreferences only in Python.
_REGEX_PUNCTUATION = frozenset(string.punctuation)
_REGEX_CLASS_ESCAPES = frozenset("dDwW")
_REGEX_COUNT = re.compile(r"\{(0|[1-9][0-9]{0,3})(?:(,)(0|[1-9][0-9]{0,3})?)?\}")

# Go refuses a repeat count over 1000, including the product of nested
# counted repeats (regexp/syntax repeatIsValid). The length cap keeps a
# pattern well inside Go's program-size limit, which Python does not have.
_GO_MAX_REPEAT = 1000
_PORTABLE_REGEX_MAX_LENGTH = 1000
_PORTABLE_REGEX_MAX_DEPTH = 50


class RouteError(ValueError):
    """A route tree this walk cannot read, and so cannot vouch for."""


class UnknownLabel(RouteError):
    """A route whose match depends on a label the walk was not given."""


class RefusedYaml(yaml.constructor.ConstructorError):
    """YAML that Alertmanager does not turn into the mapping PyYAML builds."""

    consequence = "the routing of every alert is unverified"


class DuplicateKey(RefusedYaml):
    """A mapping key written twice, which go-yaml refuses and PyYAML does not."""

    consequence = "Alertmanager refuses the file, so the routing of every alert is unverified"


class MergeKey(RefusedYaml):
    """A YAML merge key, which Alertmanager applies differently from PyYAML."""

    consequence = (
        "write the mapping out in full. Alertmanager decodes a route field by "
        "field: it applies a merge where it stands, so a key written before "
        "`<<` is overwritten by the merged one, and it adds explicit `matchers:` "
        "and `match:` entries to merged ones instead of replacing them. "
        "PyYAML's flattened mapping is not the route Alertmanager reads, so the "
        "routing of every alert is unverified"
    )


_YAML_MERGE_TAG = "tag:yaml.org,2002:merge"


class _StrictLoader(yaml.SafeLoader):
    """PyYAML, refusing a mapping key written twice, and a merge key.

    Alertmanager loads its file with go-yaml's UnmarshalStrict, which refuses a
    duplicated key; PyYAML keeps the last one. Walking the last one would be a
    model of a file Alertmanager will not load.

    A merge key (`<<`) parses the same in go-yaml and PyYAML; what differs is
    what Alertmanager builds from it (MergeKey). The check runs on each mapping
    before PyYAML flattens it, and nothing is flattened except by a mapping
    that holds a merge key, so no merge reaches the walk.
    """

    def construct_mapping(self, node, deep=False):
        seen: set[tuple[str, str]] = set()
        for key_node, _ in node.value:
            spelled = isinstance(key_node, yaml.ScalarNode) and key_node.value == "<<"
            if spelled or key_node.tag == _YAML_MERGE_TAG:
                # PyYAML decides a merge by the tag, go-yaml by the text `<<`;
                # refusing either refuses every merge each of them makes.
                raise MergeKey(
                    None, None,
                    "a merge key `<<`" if spelled
                    else f"the key {key_node.value!r}, tagged !!merge, which PyYAML merges",
                    key_node.start_mark,
                )
            if not isinstance(key_node, yaml.ScalarNode):
                continue
            key = (key_node.tag, key_node.value)
            if key in seen:
                raise DuplicateKey(
                    None, None, f"the key {key_node.value!r} is written twice",
                    key_node.start_mark,
                )
            seen.add(key)
        return super().construct_mapping(node, deep=deep)


def portable_regex_problem(pattern: str) -> str | None:
    """Why Go's RE2 and Python's re might read this regex differently, or None.

    Alertmanager compiles a regex matcher with Go's regexp as ^(?:pattern)$;
    the walk matches it with re.fullmatch under re.ASCII. The two read the
    same language over the grammar accepted here -- literals, `.`, the escapes
    above, bracket classes of single characters and ranges, groups and
    non-capturing groups, alternation, and `* + ?` or `{n}` `{n,}` `{n,m}`
    repeats, each optionally lazy -- and not beyond it. Some of what is
    refused, and why: `[[:alpha:]]` is a POSIX class in Go and a set followed
    by a literal `]` in Python; `{,3}` and `{01}` are literal in Go and repeats
    in Python; `^`, `$` and flags carry different defaults; an unbalanced `)`
    re-anchors Go's wrapped pattern and is an error in Python; lookaround,
    backreferences and possessive repeats exist in Python only.
    """
    if len(pattern) > _PORTABLE_REGEX_MAX_LENGTH:
        return f"longer than {_PORTABLE_REGEX_MAX_LENGTH} characters"

    # Each open group holds the largest repeat product inside it so far; the
    # bottom frame is the whole pattern.
    frames: list[int] = [1]
    previous: int | None = None  # the repeat product of the atom a repeat may follow
    index = 0
    length = len(pattern)

    def escape(at: int) -> tuple[str | None, int] | str:
        if at + 1 >= length:
            return "a trailing backslash"
        char = pattern[at + 1]
        if char in _REGEX_CLASS_ESCAPES:
            return None, at + 2
        if char in _REGEX_PUNCTUATION:
            return char, at + 2
        return f"the escape \\{char}"

    while index < length:
        char = pattern[index]
        if char == "\\":
            read = escape(index)
            if isinstance(read, str):
                return read
            index = read[1]
            previous = 1
        elif char == "[":
            index += 1
            if index < length and pattern[index] == "^":
                index += 1
            items = 0
            while True:
                if index >= length:
                    return "an unterminated character class"
                char = pattern[index]
                if char == "]":
                    if not items:
                        return "an empty class, or a `]` that is not escaped"
                    index += 1
                    break
                low, index, problem = _class_member(pattern, index, escape, first=not items)
                if problem:
                    return problem
                if index + 1 < length and pattern[index] == "-" and pattern[index + 1] != "]":
                    if pattern[index + 1] == "-":
                        return "`--` inside a class"
                    high, index, problem = _class_member(pattern, index + 1, escape, first=False)
                    if problem:
                        return problem
                    if low is None or high is None:
                        return "a range with a class escape at one end"
                    if high < low:
                        return f"the backwards range {low}-{high}"
                items += 1
            previous = 1
        elif char == "(":
            if pattern.startswith("(?:", index):
                index += 3
            elif pattern.startswith("(?", index):
                return "a flag or group extension; only `(` and `(?:` are read"
            else:
                index += 1
            frames.append(1)
            if len(frames) > _PORTABLE_REGEX_MAX_DEPTH:
                return f"groups nested deeper than {_PORTABLE_REGEX_MAX_DEPTH}"
            previous = None
        elif char == ")":
            if len(frames) == 1:
                return "an unbalanced `)`"
            inner = frames.pop()
            frames[-1] = max(frames[-1], inner)
            previous = inner
            index += 1
        elif char == "|":
            previous = None
            index += 1
        elif char in "*+?":
            if previous is None:
                return f"a `{char}` with nothing to repeat"
            index += 1
            if index < length and pattern[index] == "?":
                index += 1
            previous = None
        elif char == "{":
            count = _REGEX_COUNT.match(pattern, index)
            if not count:
                return "a `{` that is not a counted repeat"
            if previous is None:
                return "a counted repeat with nothing to repeat"
            low = int(count.group(1))
            high = low if count.group(2) is None else (
                int(count.group(3)) if count.group(3) is not None else None
            )
            if high is not None and high < low:
                return f"the backwards repeat {count.group(0)}"
            times = high if high is not None else low
            if times > _GO_MAX_REPEAT or (times and previous * times > _GO_MAX_REPEAT):
                return f"repeats nested past Go's limit of {_GO_MAX_REPEAT}"
            frames[-1] = max(frames[-1], previous * max(times, 1))
            index = count.end()
            if index < length and pattern[index] == "?":
                index += 1
            previous = None
        elif char in "}]^$":
            return f"an unescaped `{char}`"
        else:
            index += 1
            previous = 1
    if len(frames) != 1:
        return "an unbalanced `(`"
    # Belt and braces: whatever the grammar above let through, Python must
    # compile without complaint. A FutureWarning is Python saying the pattern's
    # meaning is due to change, which is a disagreement waiting to happen.
    with warnings.catch_warnings():
        warnings.simplefilter("error")
        try:
            re.compile(pattern, re.ASCII)
        except (re.error, Warning) as error:
            return f"Python's re objects: {error}"
    return None


def _class_member(
    pattern: str, index: int, escape, *, first: bool
) -> tuple[str | None, int, str | None]:
    """One member of a bracket class: a character, or None for \\d \\D \\w \\W.

    An unescaped `-` is read only first or last in the class, where both
    engines take it literally.
    """
    char = pattern[index]
    if char == "\\":
        read = escape(index)
        if isinstance(read, str):
            return None, index, read
        return read[0], read[1], None
    if char == "[":
        return None, index, "an unescaped `[` inside a class"
    if char == "-" and not first and pattern[index + 1:index + 2] != "]":
        return None, index, "an unescaped `-` inside a class that is not first or last"
    if char in "&~|-" and pattern[index + 1:index + 2] == char:
        # Python warns that each of these may become a set operation.
        return None, index, f"`{char}{char}` inside a class"
    return char, index + 1, None


def parse_classic_matchers(text: str) -> list[tuple[str, str, str]]:
    """pkg/labels.ParseMatchers, Alertmanager v0.28.1, ported line for line."""
    if text.startswith("{"):
        text = text[1:]
    if text.endswith("}"):
        text = text[:-1]
    inside_quotes = False
    escaped = False
    token: list[str] = []
    tokens: list[str] = []
    for char in text:
        if char == ",":
            if not inside_quotes:
                tokens.append("".join(token))
                token = []
                continue
        elif char == '"':
            if not escaped:
                inside_quotes = not inside_quotes
            else:
                escaped = False
        elif char == "\\":
            escaped = not escaped
        else:
            escaped = False
        token.append(char)
    last = "".join(token).strip(_GO_UNICODE_SPACE)
    if last:
        tokens.append(last)
    return [parse_classic_matcher(token) for token in tokens]


def parse_classic_matcher(text: str) -> tuple[str, str, str]:
    """pkg/labels.ParseMatcher, Alertmanager v0.28.1, ported line for line.

    Note what it does NOT do, because each is a way a hand-written reader
    goes wrong: a single quote is an ordinary character, so `'critical'` is a
    value with quotes in it; and a backslash before anything but `"`, `\\` or
    `n` is kept, so `prov\\w+` is a regex with a word class in it.
    """
    found = _CLASSIC_MATCHER.match(text)
    if not found:
        raise RouteError(f"a matcher this walk cannot read: {text!r} is not a classic matcher")
    name, op, raw = found.groups()
    expect_trailing_quote = raw.startswith('"')
    if expect_trailing_quote:
        raw = raw[1:]
    value: list[str] = []
    escaped = False
    for index, char in enumerate(raw):
        if escaped:
            escaped = False
            if char == "n":
                value.append("\n")
            elif char in '"\\':
                value.append(char)
            else:
                # A spurious escape: Alertmanager keeps the backslash.
                value.append("\\" + char)
            continue
        if char == "\\":
            if index < len(raw) - 1:
                escaped = True
                continue
            value.append("\\")
        elif char == '"':
            if not expect_trailing_quote or index < len(raw) - 1:
                raise RouteError(
                    f"a matcher this walk cannot read: {text!r} has an unescaped double quote"
                )
            expect_trailing_quote = False
        else:
            value.append(char)
    if expect_trailing_quote:
        raise RouteError(f"a matcher this walk cannot read: {text!r} has an unescaped double quote")
    return name, op, "".join(value)


def _refuse_invalid_utf8(text: str) -> None:
    # A YAML escape can produce a lone surrogate, which has no UTF-8 form;
    # Alertmanager refuses a value that is not valid UTF-8.
    if any("\ud800" <= char <= "\udfff" for char in text):
        raise RouteError(f"a matcher this walk cannot read: {text!r} is not valid UTF-8")


def _regex_checked(name: str, op: str, value: str, where: str) -> tuple[str, str, str]:
    if op in ("=~", "!~"):
        problem = portable_regex_problem(value)
        if problem:
            raise RouteError(
                f"a matcher this walk cannot read: {where} {name}{op}{value!r} is a regex "
                f"Go's RE2 and Python's re might not read alike ({problem})"
            )
    return name, op, value


def parse_matchers(route: dict) -> list[tuple[str, str, str]]:
    """Every matcher on a route, legacy forms included, as (label, op, value)."""
    parsed: list[tuple[str, str, str]] = []
    entries = route.get("matchers")
    if entries is not None and not isinstance(entries, list):
        raise RouteError(f"`matchers` is not a list: {entries!r}")
    for entry in entries or []:
        if not isinstance(entry, str):
            raise RouteError(f"a matcher that is not a string: {entry!r}")
        _refuse_invalid_utf8(entry)
        for name, op, value in parse_classic_matchers(entry):
            parsed.append(_regex_checked(name, op, value, "`matchers:`"))
    for key, op in (("match", "="), ("match_re", "=~")):
        legacy = route.get(key)
        if legacy is None:
            continue
        if not isinstance(legacy, dict):
            raise RouteError(f"`{key}` is not a mapping: {legacy!r}")
        for name, value in legacy.items():
            # go-yaml hands Alertmanager a scalar's source text, and PyYAML a
            # typed value: `yes` is "yes" there and True here. Only a string
            # reads the same in both.
            if not isinstance(name, str) or not _LABEL_NAME.match(name):
                raise RouteError(f"`{key}` names a label Alertmanager refuses: {name!r}")
            if not isinstance(value, str):
                raise RouteError(
                    f"`{key}` gives {name} the non-string {value!r}; quote it, so "
                    f"Alertmanager and this walk read the same value"
                )
            _refuse_invalid_utf8(value)
            parsed.append(_regex_checked(name, op, value, f"`{key}:`"))
    return parsed


def matches(
    matchers: list[tuple[str, str, str]],
    labels: dict[str, str],
    known: set[str] | None = None,
) -> bool | None:
    """Whether a route's matchers all match, as Alertmanager's Matchers.Matches.

    With `known`, a label outside it has a value the walk does not know, and a
    route whose answer depends on one returns None rather than a guess.
    """
    undetermined = False
    for name, op, value in matchers:
        if known is not None and name not in known:
            undetermined = True
            continue
        actual = labels.get(name, "")
        if op == "=":
            ok = actual == value
        elif op == "!=":
            ok = actual != value
        else:
            ok = re.fullmatch(value, actual, re.ASCII) is not None
            if op == "!~":
                ok = not ok
        if not ok:
            return False
    return None if undetermined else True


def check_route(route: object, where: str, is_root: bool = False) -> None:
    if not isinstance(route, dict):
        raise RouteError(f"{where} is not a mapping")
    unknown = sorted(set(route) - ROUTE_KEYS)
    if unknown:
        raise RouteError(f"{where} has keys Alertmanager does not accept: {', '.join(unknown)}")
    if is_root and not route.get("receiver"):
        raise RouteError("the root route has no receiver, so an unmatched alert goes nowhere")
    if route.get("receiver") is not None and not isinstance(route["receiver"], str):
        raise RouteError(f"{where}.receiver is not a string: {route['receiver']!r}")
    if route.get("continue") is not None and not isinstance(route["continue"], bool):
        raise RouteError(
            f"{where}.continue is not a boolean: {route['continue']!r}. go-yaml and "
            f"PyYAML disagree on which words are booleans; write true or false"
        )
    matchers = parse_matchers(route)
    if is_root and matchers:
        raise RouteError("the root route has matchers, which Alertmanager refuses at load")
    if is_root and route.get("continue"):
        raise RouteError("the root route has `continue: true`, which Alertmanager refuses at load")
    children = route.get("routes") or []
    if not isinstance(children, list):
        raise RouteError(f"{where}.routes is not a list")
    for index, child in enumerate(children):
        check_route(child, f"{where}.routes[{index}]")


def receivers_for(
    route: dict,
    labels: dict[str, str],
    inherited: str | None = None,
    known: set[str] | None = None,
) -> list[str]:
    """The receivers Alertmanager's route tree selects for these labels.

    dispatch.Route.Match, v0.28: depth first, children in order; a matching
    child without `continue: true` stops the search among its siblings; a
    route no child matched delivers to its own receiver, inherited from its
    parent when it names none. The root matches everything. Matchers are read
    by parse_matchers(); the tree must have been loaded by _StrictLoader and
    accepted by check_route().

    Given `known`, labels outside it are ones the walk cannot know the value
    of, and UnknownLabel is raised when the choice turns on one. Without it, a
    label not in `labels` is absent, as Alertmanager treats one.

    This is where the route tree sends the alert, not whether a notification
    goes out at a given moment: inhibition, silences and time intervals come
    after, and are not modelled.
    """
    receiver = route.get("receiver") or inherited
    found: list[str] = []
    for child in route.get("routes") or []:
        outcome = matches(parse_matchers(child), labels, known)
        if outcome is None:
            undecided = sorted(
                {name for name, _, _ in parse_matchers(child)} - (known or set())
            )
            raise UnknownLabel(
                f"a route matching on {', '.join(undecided)} decides where it goes, and "
                f"its rule does not set {'that label' if len(undecided) == 1 else 'those labels'}"
            )
        if not outcome:
            continue
        found.extend(receivers_for(child, labels, receiver, known))
        if not child.get("continue", False):
            break
    return found or [receiver]


def alertmanager_model_problems(monitoring: Path) -> list[str]:
    """The Alertmanager that loads alertmanager.yml must be the one modelled.

    Read from the compose file that runs it. With no compose file, or no
    alertmanager service in it, there is nothing to hold the model to.
    """
    compose_path = monitoring / "docker-compose.monitoring.yml"
    if not compose_path.exists():
        return []
    try:
        compose = yaml.safe_load(compose_path.read_text()) or {}
    except yaml.YAMLError:
        return []  # reported with every other file that does not parse
    service = ((compose or {}).get("services") or {}).get("alertmanager")
    if not isinstance(service, dict):
        return []
    problems: list[str] = []
    image = service.get("image")
    if not isinstance(image, str) or not MODELLED_ALERTMANAGER.match(image):
        problems.append(
            f"docker-compose.monitoring.yml runs Alertmanager {image!r}, and the "
            f"route walk models {MODELLED_ALERTMANAGER.pattern}. Check the new "
            f"version's matcher parsers against the port in validate-monitoring.py "
            f"before widening MODELLED_ALERTMANAGER"
        )
    command = service.get("command") or []
    arguments = command if isinstance(command, list) else [command]
    if any(UNMODELLED_ALERTMANAGER_FEATURE in str(argument) for argument in arguments):
        problems.append(
            f"docker-compose.monitoring.yml starts Alertmanager with "
            f"{UNMODELLED_ALERTMANAGER_FEATURE}, under which only its UTF-8 matcher "
            f"parser runs; the route walk ports the classic parser and would "
            f"misread the file"
        )
    return problems


def alert_relabelling(monitoring: Path) -> bool:
    """Whether prometheus.yml rewrites alert labels before Alertmanager sees them."""
    path = monitoring / "prometheus" / "prometheus.yml"
    if not path.exists():
        return False
    try:
        config = yaml.safe_load(path.read_text()) or {}
    except yaml.YAMLError:
        return False  # reported with every other file that does not parse
    alerting = (config or {}).get("alerting") or {}
    if not isinstance(alerting, dict):
        return False
    if alerting.get("alert_relabel_configs"):
        return True
    return any(
        isinstance(manager, dict) and manager.get("alert_relabel_configs")
        for manager in alerting.get("alertmanagers") or []
    )


def loki_ruler_problems(monitoring: Path) -> list[str]:
    """A Loki ruler wired to Alertmanager must have rule files to evaluate.

    Rule files reach Loki one way in this tree: a bind mount from the
    repository at the ruler's local storage directory. `enable_api` would let
    rules be pushed at runtime, but nothing here pushes any, and a ruler that
    depends on an undocumented manual push is the configured-looking empty
    ruler this refuses.

    Loki's local rule store (pkg/ruler/rulestore/local, as of the 3.5 line the
    compose file runs) reads `<directory>/<tenant>/<file>` and nothing else:
    a file directly in the directory is not a tenant, and a directory inside a
    tenant is skipped. Only a rule in a file at that depth is counted. The
    tenant is `fake` while `auth_enabled` is false.
    """
    config_path = monitoring / "loki" / "loki-config.yml"
    if not config_path.exists():
        return []
    config = yaml.safe_load(config_path.read_text()) or {}
    ruler = config.get("ruler")
    if not isinstance(ruler, dict) or not ruler.get("alertmanager_url"):
        return []

    directory = ((ruler.get("storage") or {}).get("local") or {}).get("directory")
    wired = f"loki/loki-config.yml wires the ruler to {ruler['alertmanager_url']}"
    if not directory:
        return [f"{wired} with no local rules directory; it has nothing to evaluate"]

    compose_path = monitoring / "docker-compose.monitoring.yml"
    compose = yaml.safe_load(compose_path.read_text()) if compose_path.exists() else {}
    loki = ((compose or {}).get("services") or {}).get("loki") or {}
    for volume in loki.get("volumes") or []:
        if not isinstance(volume, str):
            continue
        parts = volume.split(":")
        if len(parts) < 2 or not parts[0].startswith((".", "/")):
            continue  # a named volume starts empty; it is not a source of rules
        root = PurePosixPath(directory.rstrip("/") or "/")
        target = PurePosixPath(parts[1].rstrip("/") or "/")
        if target != root and root not in target.parents:
            continue
        source = (compose_path.parent / parts[0]).resolve()
        rules = 0
        for path in sorted(source.rglob("*.y*ml")) if source.is_dir() else []:
            inside = target.joinpath(*path.relative_to(source).parts)
            if len(inside.relative_to(root).parts) != 2:
                continue  # not <directory>/<tenant>/<file>, so Loki never reads it
            document = yaml.safe_load(path.read_text()) or {}
            for group in document.get("groups", []) if isinstance(document, dict) else []:
                rules += len(group.get("rules") or [])
        if rules:
            return []
    return [
        f"{wired} and mounts no rule files at {directory.rstrip('/')}/<tenant>/, "
        f"the only place Loki's local store reads them. A ruler pointed at an "
        f"empty directory looks configured and evaluates nothing: ship rule "
        f"files and mount them there, or remove the ruler's wiring."
    ]


def exported_metrics(repo_root: Path) -> set[str]:
    """Metric families the control plane's own endpoint exports."""
    source = repo_root / "apps" / "control-plane" / "src"
    found: set[str] = set()
    for path in source.rglob("*.php"):
        found.update(METRIC_IN_SOURCE.findall(path.read_text(errors="replace")))
    return found


def collector_metrics(infra_root: Path) -> set[str]:
    """Metric families a textfile collector promises, per the declared contract.

    These have no PHP behind them by design. The README is the contract, so the
    README is what this reads.
    """
    readme = infra_root / "monitoring" / "README.md"
    if not readme.exists():
        return set()
    return set(METRIC_IN_SOURCE.findall(readme.read_text(errors="replace")))


def collector_homes(infra_root: Path) -> list[str]:
    """Where the monitoring README says each textfile collector lives.

    A collector contract is a promise that something writes those series. The
    promise is only worth anything if the thing exists, so the README is made to
    name a directory and this checks that the directory is there.
    """
    readme = infra_root / "monitoring" / "README.md"
    if not readme.exists():
        return []
    return sorted(set(re.findall(r"belongs to\s+`infrastructure/([a-z_]+)`", readme.read_text())))


def declared_directories(infra_root: Path) -> list[str]:
    """Directory names infrastructure/README.md's tree block claims exist.

    Top-level entries only: a line beginning with a tree connector at column
    zero. Nested entries are prefixed with a vertical bar and belong to the
    directory above them, which is checked on its own line.
    """
    readme = infra_root / "README.md"
    if not readme.exists():
        return []

    names: set[str] = set()
    for line in readme.read_text().splitlines():
        match = re.match(r"^(?:\u251c\u2500\u2500|\u2514\u2500\u2500)\s+(.*)$", line)
        if not match:
            continue
        # One line may list several siblings: "proxmox/ pbs/ pxe/ ...".
        names.update(re.findall(r"\b([a-z_]+)/", match.group(1)))
    return sorted(names)


def main(
    argv: list[str],
    pinned: dict[str, dict[str, str]] | None = None,
    never_pages: dict[str, str] | None = None,
) -> int:
    # The pins describe this repository's alerts. They are parameters only so
    # the self-test can run the validator over synthetic trees that do not
    # contain those alerts; from the command line the real tables apply.
    pinned = PINNED_ROUTES if pinned is None else pinned
    never_pages = NEVER_PAGES if never_pages is None else never_pages
    infra_root = Path(argv[1]) if len(argv) > 1 else Path(__file__).resolve().parent.parent
    repo_root = infra_root.parent

    exported = exported_metrics(repo_root)
    if not exported:
        print("found no lynomia_* metrics in the control plane source", file=sys.stderr)
        return 1
    declared = collector_metrics(infra_root)
    emitted = exported | declared

    problems: list[str] = []
    unimplemented: list[str] = []
    rules_dir = infra_root / "monitoring" / "prometheus" / "rules"
    rule_files = sorted(rules_dir.glob("*.yml"))
    if not rule_files:
        print(f"no rule files under {rules_dir}", file=sys.stderr)
        return 1

    total_rules = 0
    alerts: dict[str, list[tuple[str, dict, str, object]]] = {}
    for path in rule_files:
        document = yaml.safe_load(path.read_text()) or {}
        for group in document.get("groups", []):
            for rule in group.get("rules", []):
                name = rule.get("alert") or rule.get("record") or "<unnamed>"
                total_rules += 1
                # A recording rule computes a series; it never pages anybody, so
                # it has no runbook and no severity, and asking for either is a
                # bug in this check rather than a gap in the configuration.
                is_alert = "alert" in rule

                expr = str(rule.get("expr", ""))
                for metric in set(METRIC_IN_EXPR.findall(expr)):
                    if metric not in emitted:
                        problems.append(
                            f"{path.name}: {name} alerts on {metric}, which nothing "
                            f"exports and no collector contract declares"
                        )

                if not is_alert:
                    continue

                alerts.setdefault(name, []).append(
                    (path.name, dict(rule.get("labels") or {}), expr, rule.get("for"))
                )

                annotations = rule.get("annotations") or {}
                runbook = annotations.get("runbook")
                runbook_url = annotations.get("runbook_url")
                if not runbook and not runbook_url:
                    problems.append(
                        f"{path.name}: {name} has neither a runbook nor a runbook_url"
                    )
                elif runbook and not (repo_root / runbook).exists():
                    problems.append(
                        f"{path.name}: {name} points at {runbook}, which does not exist"
                    )

                if not rule.get("labels", {}).get("severity"):
                    problems.append(f"{path.name}: {name} has no severity label")

    # A critical rule on a series that must never page.
    for name, definitions in alerts.items():
        for file_name, labels, expr, _ in definitions:
            if labels.get("severity") != "critical":
                continue
            for metric in sorted(set(METRIC_IN_EXPR.findall(expr)) & set(never_pages)):
                problems.append(
                    f"{file_name}: {name} pages on {metric}, which {never_pages[metric]}"
                )

    # Where each alert goes: the receivers Alertmanager's route tree selects.
    monitoring = infra_root / "monitoring"
    alertmanager_path = monitoring / "alertmanager" / "alertmanager.yml"
    route: dict | None = None
    if alertmanager_path.exists():
        try:
            config = yaml.load(alertmanager_path.read_text(), Loader=_StrictLoader) or {}
            check_route(config.get("route"), "route", is_root=True)
            route = config["route"]
        except RefusedYaml as error:
            mark = error.problem_mark
            problems.append(
                f"alertmanager.yml line {mark.line + 1}, column {mark.column + 1}: "
                f"{error.problem}; {error.consequence}"
            )
        except yaml.YAMLError:
            pass  # reported below, with every other file that does not parse
        except RouteError as error:
            problems.append(f"alertmanager.yml: {error}; the routing of every alert is unverified")
        if route is not None:
            defined = {
                receiver.get("name")
                for receiver in config.get("receivers") or []
                if isinstance(receiver, dict)
            }
            for name, definitions in sorted(alerts.items()):
                for file_name, labels, _, _ in definitions:
                    walk_labels = {str(k): str(v) for k, v in labels.items()}
                    walk_labels["alertname"] = name
                    for receiver in receivers_for(route, walk_labels):
                        if receiver not in defined:
                            problems.append(
                                f"{file_name}: {name} routes to receiver {receiver!r}, "
                                f"which alertmanager.yml does not define"
                            )
        problems.extend(alertmanager_model_problems(monitoring))

    # The pinned alerts: present, reading the series they must, and routed
    # where the pin says.
    if pinned and alert_relabelling(monitoring):
        problems.append(
            "prometheus.yml relabels alerts before Alertmanager receives them, so "
            "the labels a rule sets are not the labels Alertmanager routes on, and "
            "no pinned route can be checked from the rule files"
        )
    for name, pin in sorted(pinned.items()):
        definitions = alerts.get(name)
        if not definitions:
            problems.append(
                f"{name} is pinned in PINNED_ROUTES and no rule file defines it"
            )
            continue
        for file_name, labels, expr, held_for in definitions:
            if pin["reads"] not in METRIC_IN_EXPR.findall(expr):
                problems.append(
                    f"{file_name}: {name} must read {pin['reads']}, and its "
                    f"expression does not"
                )
            if "expr" in pin and " ".join(expr.split()) != " ".join(pin["expr"].split()):
                problems.append(
                    f"{file_name}: {name}'s expression is {expr!r}, and PINNED_ROUTES "
                    f"pins it to {pin['expr']!r}. The expression is the decision the "
                    f"pin records; if the change is meant, change the pin with it"
                )
            if "for" in pin and held_for != pin["for"]:
                waits = "has no `for:`" if held_for is None else f"waits `for: {held_for}`"
                problems.append(
                    f"{file_name}: {name} {waits}, and PINNED_ROUTES "
                    f"pins it to `for: {pin['for']}`. The runbook tells the responder "
                    f"how long the condition has held; if the change is meant, change "
                    f"the pin and the runbook with it"
                )
            if route is None:
                problems.append(
                    f"{file_name}: {name} is pinned to {pin['receiver']!r}, and "
                    f"alertmanager.yml is missing or unreadable, so where it goes "
                    f"cannot be checked"
                )
                continue
            # The alert Alertmanager receives also carries the series' labels
            # and Prometheus's external labels, which no rule file fixes. Only
            # the labels the rule sets literally are known; the walk refuses a
            # route whose match turns on any other.
            walk_labels = {str(k): str(v) for k, v in labels.items()}
            walk_labels["alertname"] = name
            known = {
                str(k) for k, v in labels.items() if isinstance(v, str) and "{{" not in v
            } | {"alertname"}
            try:
                delivered = receivers_for(route, walk_labels, known=known)
            except UnknownLabel as error:
                problems.append(
                    f"{file_name}: {name} is pinned to {pin['receiver']!r}, and {error}, "
                    f"so where it goes depends on the series and cannot be checked here"
                )
                continue
            if pin["receiver"] not in delivered:
                problems.append(
                    f"{file_name}: {name} is routed to {', '.join(delivered)}, "
                    f"and is pinned to {pin['receiver']!r}"
                )

    problems.extend(loki_ruler_problems(monitoring))

    # Every config file must parse, and none may carry an inline secret.
    secretish = re.compile(
        r"(?i)^\s*[a-z_]*(password|token|secret|api_key)\s*:\s*(?!$)(?!\$\{)(?!null)\S",
        re.MULTILINE,
    )
    for path in sorted((infra_root / "monitoring").rglob("*.yml")):
        try:
            yaml.safe_load(path.read_text())
        except yaml.YAMLError as error:
            problems.append(f"{path.relative_to(infra_root)}: does not parse — {error}")
        if secretish.search(path.read_text()):
            problems.append(
                f"{path.relative_to(infra_root)}: has an inline credential; use a "
                f"*_file field or an environment placeholder"
            )

    # A directory the README promises and does not have is how a collector
    # contract ends up with nothing implementing it.
    for name in declared_directories(infra_root):
        if not (infra_root / name).is_dir():
            problems.append(
                f"README.md names infrastructure/{name}/, which does not exist"
            )

    # A collector contract with nothing implementing it is a set of alerts that
    # can never fire, which reads as coverage and is not. The gap may stand —
    # some collectors need hardware nobody has yet — but it may not stand
    # silently: the README has to say so in as many words, next to the contract.
    monitoring_readme = infra_root / "monitoring" / "README.md"
    readme_text = monitoring_readme.read_text() if monitoring_readme.exists() else ""
    for name in collector_homes(infra_root):
        if (infra_root / name).is_dir():
            continue
        acknowledged = re.search(
            rf"NOT IMPLEMENTED[^\n]*`?infrastructure/{name}`?"
            rf"|`?infrastructure/{name}`?[^\n]*NOT IMPLEMENTED",
            readme_text,
        )
        if acknowledged:
            unimplemented.append(name)
        else:
            problems.append(
                f"monitoring/README.md declares a textfile collector belonging to "
                f"infrastructure/{name}, which does not exist, and does not say so. "
                f"Every alert on those series is incapable of firing; mark the "
                f"contract NOT IMPLEMENTED next to it, with the blocker."
            )

    print(
        f"{len(rule_files)} rule file(s), {total_rules} rule(s), "
        f"{len(exported)} exported by the control plane, "
        f"{len(declared - exported)} declared by a collector contract"
    )

    # The absent rules directory is already refused above. This is the case in
    # between: files are there, they parse, and not one rule came out of them.
    # A `groups:` key that has been renamed, or a rules file that is now a
    # Prometheus `rule_files:` include rather than the rules themselves, gets
    # exactly this far -- every assertion below iterates an empty list and the
    # job prints a green line. An alerting configuration with no alerts in it
    # is not a configuration this check has approved.
    if total_rules == 0:
        print(
            f"{len(rule_files)} rule file(s) declare no rules at all. Every "
            f"check below this point iterates an empty list, so a green result "
            f"here would mean nothing was examined rather than nothing was "
            f"wrong.",
            file=sys.stderr,
        )
        return 1

    for name in unimplemented:
        print(
            f"  NOTE the textfile collector for infrastructure/{name} is declared "
            f"and not implemented; alerts on its series cannot fire"
        )
    for problem in problems:
        print(f"  FAIL {problem}", file=sys.stderr)

    return 1 if problems else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
