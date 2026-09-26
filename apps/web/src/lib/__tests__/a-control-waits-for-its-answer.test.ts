import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * F-21. A control that depends on an answer is not live before the answer.
 *
 * ## The defect
 *
 * Registration's Create-account button was gated on
 * `options.data?.legal.registration_permitted === false`. While the options
 * request was pending, or after it had failed, `options.data` is undefined, the
 * comparison is false, and the button was live: a visitor could submit a form
 * whose country select offered no country. The same shape sat on the operator
 * surface, `PlansPage`'s Compute-plan button on `desired.data?.data === null`,
 * live while the desired state it computes from was unread. Both are a strict
 * comparison against a sentinel, evaluated on a value that can be absent. Both
 * are fixed in the components; this file keeps them fixed — each of the two
 * must be judged, and judged shut, so rewriting either into a shape this file
 * cannot read fails here as surely as reverting it.
 *
 * ## What this gate asserts, exactly
 *
 * **No `disabled=` or `ready=` JSX prop in a `.tsx` file under `src` whose
 * resolved text literally contains `.data` leaves its control live while the
 * query behind that `.data` is unresolved.** "Live" means `disabled` comes out
 * anything but `true`, or `ready` anything but `false` — `Button` disables only
 * on `disabled === true`, and `ConfirmDialog`'s `ready` defaults to true. And
 * **F-21's own two gates are always among those judged shut**
 * (`FINDING_GATES`).
 *
 * It narrows exactly where that sentence does, and the narrowing is a pattern
 * match applied before the evaluation: a prop whose resolved text does not
 * contain `.data` is never judged, however it came by its value. The shapes
 * that walks past are listed under "What it cannot see", with the sites in
 * this tree that have them. Where the text cannot tell what a name in a gate
 * holds, steps 1 and 2 below hide or refuse that name rather than substitute
 * a guess.
 *
 * ## How: evaluate, do not pattern-match
 *
 * A list of wrong shapes waves through the first shape it was not taught —
 * which is how the second instance survived a grep. So each prop is
 * **evaluated**:
 *
 *  1. Its expression is resolved by substituting `const` initialisers to a
 *     fixpoint. A name is looked up in the gate's own region first — a region
 *     runs from one column-zero declaration (a `function`, a named `class`, a
 *     `const`, `let` or `var`, exported or not, or any `export default`) to
 *     the next — and then among the file's column-zero `const`s, exported or
 *     not. A parameter of the region's own declaration — the first function's
 *     or arrow's parameter list opening on its line (or the next, when the
 *     line ends on its `=`), wrapped in a call such as `memo(…)` or not —
 *     hides a column-zero `const` of the same name, and so does any
 *     declaration opening its own line in the region (a destructured `const`,
 *     a `let`, an inner `function` or `class`). A `const` initialised by a
 *     hook call is never substituted: it is the query itself. Only an
 *     initialiser that is the whole statement on its own line is substituted:
 *     the line closes every bracket it opens, holds one declarator and one
 *     statement, and does not end on an operator, and the next line of code
 *     is not indented deeper and does not open with something that can only
 *     continue an expression (`?`, `:`, `||`, `&&`, `??`, `.`, `(`, `as`, and
 *     the rest). Any other `const` only hides. That reading errs toward
 *     hiding — a comma between generic arguments counts as a second
 *     declarator — because a statement taken to end early is substituted as
 *     something it is not, and can certify a live gate as shut, where one
 *     taken to carry on only hides.
 *  2. Resolution **refuses** rather than guesses in five cases, and a refusal
 *     is reported like any other gate that cannot be judged — whether or not
 *     the text reaches `.data`, since what the refused name would have
 *     brought in is exactly what is not known. The cases:
 *      - a name declared more than once in the region, or, declared nowhere
 *        in it, more than once at column zero;
 *      - a parameter of the region's own declaration that is also declared
 *        inside it;
 *      - a name declared in the region that is also bound anywhere else in
 *        it, or a column-zero `const` bound anywhere in the region by
 *        anything but the region's own parameters (which hide it): by a
 *        parameter of an arrow function, a function or a method, whatever
 *        it holds — a pattern, a default with a call in it, a function type;
 *        by a `catch` or `for` binding, patterns included; by a declaration
 *        that does not open its line, or that follows another on one, or
 *        that is a `using`; by the name of a nested function or class. Which
 *        one a gate reads cannot be told from the text;
 *      - a column-zero `const` whose initialiser reads a name the region
 *        binds: written at module scope, it reads module scope, and
 *        substituted into the region its text would read the region's;
 *      - an expression that does not settle.
 *
 *     The bindings are read from text, not from a parse, and the reading
 *     errs one way on purpose. Declarations are read with comments blanked,
 *     so commented-out code declares nothing — and a declaration only the
 *     raw text shows, as behind a `/*` that was JSX text, counts as a
 *     binding besides. Every pair of parentheses is examined, inside strings
 *     and comments too, and the region is read both with its comments and
 *     without them, keeping every name either reading finds. A name wrongly
 *     taken for a binding costs a refusal; a binding missed would cost a
 *     verdict about a value the control never reads.
 *  3. The resolved text is run against a stub of an unresolved query —
 *     twice, once **pending** and once **failed** — with every identifier
 *     written `X.data` or `X?.data` answering as that stub. Any other name
 *     reads as unknown, except the evaluable globals (`undefined` among them,
 *     so a correct `=== undefined` gate is judged rather than turned away).
 *     The stub answers only the fields an unresolved query is known to have;
 *     reading any other is also an unknown.
 *  4. The verdict is **shut** in both states, **live** in either, or
 *     **unjudgeable**: it threw, read an unknown, came out as something other
 *     than a boolean, or its resolution was refused. Everything, the
 *     description of the value included, runs inside one `try`, so an
 *     unjudgeable gate is always reported with its file and line.
 *
 * A live gate is a failure, with the file, the line, the resolved expression
 * and the reason. An unjudgeable gate is a failure unless `GUARDED` names it
 * with the reason it is correct. `GUARDED` is closed in both directions: an
 * entry for a gate that no longer exists, or that now judges cleanly, is also
 * a failure. And a `GUARDED` entry never excuses a live verdict.
 *
 * ## The census, at the time of writing
 *
 * Dumped from this file's own machinery and then classified by hand.
 *
 * **66** `disabled=`/`ready=` props in 37 files. **6** reach `.data`:
 * **4 shut** (`RegisterPage`'s Create account, `PlansPage`'s Compute plan and
 * Run, `InvoicesPage`'s pay-from-credit `ready`), **2 guarded**, 0 live, and
 * **0 refused** — the binding reading over-collects by design, and on this
 * tree none of what it collects collides with a name a gate reads. With the
 * two pre-fix lines restored, the same run reports exactly those two as live.
 * **60** are dropped by the `.data` filter:
 *
 *  - **25**, in 19 files, gate on a field of a server record the control is
 *    drawn for — a record a parent read and passed down as a prop, or a row of
 *    a list — `!domain.is_manageable`, `!server.actions.power`,
 *    `! quote.is_available`, `r.is_being_deleted`. **This is the one real
 *    hole**: the gate cannot follow a value from the query that produced it
 *    through a prop or a row into the control. Each of these controls exists
 *    only once its record does, so none can be pressed before its answer;
 *    whether its polarity is right is a question this gate does not ask.
 *  - **4** are blind sites: a query's answer reaches the gate by a route this
 *    file does not follow. All four are correct today, and that is the code's
 *    doing, not this gate's.
 *     - `ActivityPage` `!hasMore || nextCursor === null` and
 *       `NotificationsPage` `unread === 0` read a destructured `data`; their
 *       fallbacks (`?? false`, `?? null`, `?? 0`) shut the control.
 *     - `ProvidersPage` `entry === undefined`, where `entry` is
 *       `catalogue?.data.find((candidate) => …)` over a destructured
 *       envelope; an unresolved catalogue finds nothing.
 *     - `PlansPage` `profile === undefined` in `AssignForm`, where `profile`
 *       is `profiles.find((candidate) => …)` over a list the parent read and
 *       passed down, and renders the form only once it has.
 *  - **31** read no answer at all: local state, a mutation's `isPending`, a
 *    component's own props, a countdown, a typed phrase.
 *
 * ## What it cannot see
 *
 * Six shapes pass silently. The site counts are measured on this tree.
 *
 *  1. A query read through a destructured `data` — three sites, above.
 *  2. A gate passed as a spread, `<Button {...{ disabled: x }}>`: no
 *     `disabled=` text, so not even in the 66. No site.
 *  3. A value a parent read from a query and passed down, or a row of a list
 *     — 25 sites, and `AssignForm`'s, above.
 *  4. A value whose `const` is not substituted: one initialised through a
 *     hook (`useMemo`, `useState` from a query); one whose statement carries
 *     on past its line, or whose line holds a second declarator or statement
 *     (step 1); or one whose initialiser holds an arrow function — the last
 *     so a `const` that is itself a function is never evaluated as a value,
 *     which also stops `ProvidersPage`'s and `AssignForm`'s
 *     `.find((…) => …)`. Of the `const`s whose line is bracket-balanced, six
 *     in this tree hide for the second reason alone: five whose statement
 *     carries on past the line, and `OperatorsPage`'s
 *     `new Map<string, typeof catalogue>()`, whose generic-argument comma
 *     reads as a second declarator. None of the six reads `.data`, and no
 *     verdict turns on them.
 *  5. A gate computed in a `.ts` file, or by calling a function: the text
 *     holds a call, not a `.data`. No site.
 *  6. A control gated by a prop with another name (`aria-disabled`,
 *     `isDisabled`, `canSubmit`). No site under a gate-like name.
 *
 * Widening to any of these is deliberately not done here: an unmeasured
 * widening is worse than a measured boundary.
 */

const SOURCE = path.resolve(import.meta.dirname, '../..')

type Prop = 'disabled' | 'ready'
type QueryState = 'pending' | 'failed'

interface SourceFile {
  file: string
  text: string
}

interface Declaration {
  line: number
  /** The initialiser to substitute, or null for a binding that only hides. */
  init: string | null
}

interface Region {
  name: string
  start: number
  end: number
  /** The parameters of the region's own declaration. */
  params: Set<string>
  /** Every other binding anywhere inside it, with what binds it: see `bindingsIn`. */
  innerBindings: Map<string, string>
  declarations: Map<string, Declaration[]>
}

interface FileModel {
  file: string
  lines: string[]
  regions: Region[]
  module: Map<string, Declaration[]>
}

interface Gate {
  file: string
  line: number
  prop: Prop
  expression: string
  region: string
  resolved: string
  refusal: string | null
}

type Verdict =
  | { kind: 'shut' }
  | { kind: 'live'; state: QueryState; value: boolean }
  | { kind: 'unjudgeable'; state: QueryState | null; reason: string }

/**
 * Gates that reach `.data` and cannot be judged against an unresolved query,
 * and why each is correct. Keyed `file::expression`, whitespace collapsed.
 */
const GUARDED: Record<string, string> = {
  'features/controlCenter/PlansPage.tsx::!current.is_applicable':
    'rendered only inside `current === null ? null : (…)`, where `current` is `plan.data?.data ?? null` — so on screen the plan has been read and is not null, and the unresolved case throws here only because the gate is evaluated outside that branch',
  'features/dns/ZoneTransfer.tsx::! previewed.applicable || stale || (previewed.counts.add + previewed.counts.update + previewed.counts.remove) === 0':
    'rendered only inside `previewed === null || result !== null ? null : (…)`, where `previewed` is `plan.data?.data ?? null` from the zone-import plan mutation — so on screen a plan has been returned, and the unresolved case throws here only because the gate is evaluated outside that branch',
}

// ---------------------------------------------------------------------------
// Reading the source
// ---------------------------------------------------------------------------

function readSources(): SourceFile[] {
  const files: SourceFile[] = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        if (entry !== '__tests__') walk(full)
        continue
      }

      if (entry.endsWith('.tsx') && !entry.endsWith('.test.tsx')) {
        files.push({ file: path.relative(SOURCE, full).split(path.sep).join('/'), text: readFileSync(full, 'utf8') })
      }
    }
  }

  walk(SOURCE)

  return files
}

const IDENTIFIER_START = /[A-Za-z_$]/
const IDENTIFIER_PART = /[\w$]/

const KEYWORDS = new Set([
  'true', 'false', 'null', 'typeof', 'instanceof', 'in', 'of', 'new', 'void', 'delete', 'this', 'as', 'satisfies',
])

/** The index just past the string literal that opens at `start`. */
function endOfString(text: string, start: number): number {
  const quote = text.charAt(start)
  let index = start + 1

  while (index < text.length) {
    const character = text.charAt(index)

    if (character === '\\') {
      index += 2
      continue
    }

    if (character === quote) return index + 1

    index += 1
  }

  return text.length
}

/** The index of the bracket that closes the one at `open`, or -1. */
function closing(text: string, open: number): number {
  let depth = 0
  let index = open

  while (index < text.length) {
    const character = text.charAt(index)

    if (character === "'" || character === '"' || character === '`') {
      index = endOfString(text, index)
      continue
    }

    if (character === '(' || character === '[' || character === '{') depth += 1

    if (character === ')' || character === ']' || character === '}') {
      depth -= 1
      if (depth === 0) return index
    }

    index += 1
  }

  return -1
}

function balanced(text: string): boolean {
  let depth = 0
  let index = 0

  while (index < text.length) {
    const character = text.charAt(index)

    if (character === "'" || character === '"' || character === '`') {
      const end = endOfString(text, index)
      if (end >= text.length && text.charAt(text.length - 1) !== character) return false
      index = end
      continue
    }

    if (character === '(' || character === '[' || character === '{') depth += 1
    if (character === ')' || character === ']' || character === '}') depth -= 1
    if (depth < 0) return false

    index += 1
  }

  return depth === 0
}

/**
 * Splits on `separator` where it is not inside brackets or a string.
 *
 * Angle brackets are not counted. Over a parameter list that splits a generic
 * type's arguments apart, which only adds names; counting them would let the
 * `>` of an arrow type, or a comparison in a default, swallow every parameter
 * after it.
 */
function splitTopLevel(text: string, separator: string): string[] {
  const parts: string[] = []
  let depth = 0
  let from = 0
  let index = 0

  while (index < text.length) {
    const character = text.charAt(index)

    if (character === "'" || character === '"' || character === '`') {
      index = endOfString(text, index)
      continue
    }

    if (character === '(' || character === '[' || character === '{') depth += 1
    if (character === ')' || character === ']' || character === '}') depth -= 1

    if (depth === 0 && character === separator) {
      parts.push(text.slice(from, index))
      from = index + 1
    }

    index += 1
  }

  parts.push(text.slice(from))

  return parts
}

/** TypeScript's parameter-property modifiers, which come before the name they modify. */
const MODIFIERS = /^(?:(?:public|private|protected|readonly|override)\s+)+/

/** The names a parameter list or a destructuring pattern binds. */
function bindingNames(list: string): string[] {
  const names: string[] = []

  for (const item of splitTopLevel(list, ',')) {
    let piece = item.trim().replace(/^\.\.\./, '').replace(MODIFIERS, '')

    if (piece === '') continue

    if (piece.startsWith('{') || piece.startsWith('[')) {
      const end = closing(piece, 0)
      const inner = piece.slice(1, end === -1 ? piece.length : end)
      const object = piece.startsWith('{')

      for (const part of splitTopLevel(inner, ',')) {
        let binding = part.trim().replace(/^\.\.\./, '')
        const equals = splitTopLevel(binding, '=')[0] ?? binding
        binding = equals.trim()

        if (object) {
          const colon = splitTopLevel(binding, ':')
          binding = (colon.length > 1 ? colon.slice(1).join(':') : binding).trim()
        }

        if (binding.startsWith('{') || binding.startsWith('[')) {
          names.push(...bindingNames(binding))
        } else if (/^[A-Za-z_$][\w$]*$/.test(binding)) {
          names.push(binding)
        }
      }

      continue
    }

    piece = (splitTopLevel(piece, '=')[0] ?? piece).trim()
    const name = /^([A-Za-z_$][\w$]*)/.exec(piece)?.[1]

    if (name !== undefined) names.push(name)
  }

  return names
}

/**
 * Rewrites every identifier outside a string literal that is not a property
 * name. `replace` returns the replacement, or null to keep the name.
 */
function mapIdentifiers(text: string, replace: (name: string) => string | null): string {
  let out = ''
  let index = 0

  while (index < text.length) {
    const character = text.charAt(index)

    if (character === "'" || character === '"' || character === '`') {
      const end = endOfString(text, index)
      out += text.slice(index, end)
      index = end
      continue
    }

    if (/[0-9]/.test(character)) {
      let end = index + 1
      while (end < text.length && /[\w.]/.test(text.charAt(end))) end += 1
      out += text.slice(index, end)
      index = end
      continue
    }

    if (IDENTIFIER_START.test(character)) {
      let end = index + 1
      while (end < text.length && IDENTIFIER_PART.test(text.charAt(end))) end += 1

      const name = text.slice(index, end)
      const property = out.trimEnd().endsWith('.')
      const replacement = property || KEYWORDS.has(name) ? null : replace(name)

      out += replacement ?? name
      index = end
      continue
    }

    out += character
    index += 1
  }

  return out
}

/*
 * A column-zero declaration opens a region: a named function, class, `const`,
 * `let` or `var`, exported or not, and any `export default` — whose function
 * or class need not have a name.
 */
const OPENER =
  /^(?:(?:export\s+(?:default\s+)?)?(?:async\s+)?(?:function\b\s*\*?\s*([A-Za-z_$][\w$]*)?|class\s+([A-Za-z_$][\w$]*)|(?:const|let|var)\s+([A-Za-z_$][\w$]*))|export\s+default\b)/

const DECLARATION = /^\s*(?:export\s+)?const\s+([A-Za-z_$][\w$]*)\s*(?::\s*([^=]*?))?\s*=(?![=>])\s*(.*)$/

/**
 * A `const` line's initialiser, when the line holds the whole of it and
 * nothing else, and it is not a hook call or a function. Whether the statement
 * carries on to the next line is `carriesOn`'s question, asked by the caller.
 */
function substitutable(rest: string): string | null {
  const init = rest.replace(/\s+\/\/.*$/, '').replace(/;\s*$/, '').trim()

  if (init === '' || !balanced(init)) return null
  // A second declarator (`= a, b = c`) or a second statement (`= a; f()`):
  // the text after `=` is then not this constant's value.
  if (splitTopLevel(init, ',').length > 1 || splitTopLevel(init, ';').length > 1) return null
  if (/^(?:await\s+)?use[A-Z0-9]\w*\s*(?:<[^>]*>)?\s*\(/.test(init)) return null
  if (/=>/.test(init) || /^(?:async\s+)?function\b/.test(init)) return null

  return init
}

/**
 * `text` with every comment blanked to spaces and its line breaks kept, so
 * offsets and line numbers do not move and nothing a comment says is read as
 * code. A `//` or `/*` written as JSX text is blanked too, which is why the
 * bindings are also read from the raw text (`modelOf`).
 */
function withoutComments(text: string): string {
  let out = ''
  let index = 0

  while (index < text.length) {
    const character = text.charAt(index)

    if (character === "'" || character === '"' || character === '`') {
      const end = endOfString(text, index)
      out += text.slice(index, end)
      index = end
      continue
    }

    if (character === '/' && text.charAt(index + 1) === '/') {
      const newline = text.indexOf('\n', index)
      const end = newline === -1 ? text.length : newline
      out += ' '.repeat(end - index)
      index = end
      continue
    }

    if (character === '/' && text.charAt(index + 1) === '*') {
      const close = text.indexOf('*/', index + 2)
      const end = close === -1 ? text.length : close + 2
      out += text.slice(index, end).replace(/[^\n]/g, ' ')
      index = end
      continue
    }

    out += character
    index += 1
  }

  return out
}

/** Text that, ending a line, leaves the expression on it unfinished. */
const UNFINISHED =
  /(?:[=+\-*/%&|^!~?:<>,.([{]|(?<![\w$])(?:typeof|instanceof|in|of|new|await|void|delete|as|satisfies|yield|keyof))$/

/** Text that, opening a line, can only carry on the expression before it. */
const CONTINUING = /^(?:[^\w$\s})\];]|(?:as|satisfies|instanceof|in|of)(?![\w$]))/

/** The next line of code after a line break: its indentation and its text. */
const NEXT_LINE = /(?:[ \t]*\r?\n)*([ \t]*)(\S[^\n]*)?/y

/**
 * Whether the statement whose line ends at `lineEnd` carries on to the next
 * line: that line leaves its expression unfinished, or the next line of code
 * is indented deeper than `indent` (the statement's own line), or opens with
 * something that can only continue an expression — `?`, `:`, `||`, `&&`,
 * `??`, `.`, `(`, `as`, and the rest. `text` has its comments blanked, so a
 * comment between two lines of one statement is a blank line here.
 *
 * It says yes when in doubt, and that direction is the point: a statement
 * taken to end early is substituted as something it is not, and can certify a
 * live gate as shut; a statement taken to carry on only hides.
 */
function carriesOn(text: string, lineEnd: number, indent: number): boolean {
  const lineStart = text.lastIndexOf('\n', lineEnd - 1) + 1

  if (UNFINISHED.test(text.slice(lineStart, lineEnd).trimEnd())) return true

  NEXT_LINE.lastIndex = lineEnd + 1
  const next = lineEnd >= text.length ? null : NEXT_LINE.exec(text)
  const nextText = next?.[2]

  if (next === null || nextText === undefined) return false

  return (next[1]?.length ?? 0) > indent || CONTINUING.test(nextText)
}

// ---------------------------------------------------------------------------
// Every other binding in a region
// ---------------------------------------------------------------------------

function skipSpace(text: string, index: number): number {
  let at = index
  while (at < text.length && /\s/.test(text.charAt(at))) at += 1
  return at
}

function identifierEnd(text: string, index: number): number {
  let at = index + 1
  while (at < text.length && IDENTIFIER_PART.test(text.charAt(at))) at += 1
  return at
}

/** The indentation of the line that `index` is on. */
function indentAt(text: string, index: number): number {
  const lineStart = text.lastIndexOf('\n', index - 1) + 1
  return /^[ \t]*/.exec(text.slice(lineStart, index))?.[0].length ?? 0
}

/** Words after which a type is still expected, so a `{` is an object type and not a body. */
const TYPE_OPERATORS = new Set(['keyof', 'typeof', 'extends', 'is', 'infer', 'readonly', 'unique', 'asserts'])

/**
 * What follows the `)` at `close`: `=>`, which makes the brackets an arrow
 * function's parameters; `{`, a function or method body; or neither. A return
 * type between them — `(row): Row =>`, `(): () => void =>`,
 * `(): { ok: boolean } =>` — is read through, bracket by bracket.
 */
function afterParameters(text: string, close: number): 'arrow' | 'body' | null {
  let at = skipSpace(text, close + 1)

  if (text.startsWith('=>', at)) return 'arrow'
  if (text.charAt(at) === '{') return 'body'
  if (text.charAt(at) !== ':') return null

  at += 1
  let angle = 0
  let expectingType = true
  const limit = Math.min(text.length, at + 500)

  while (at < limit) {
    const character = text.charAt(at)

    if (/\s/.test(character)) {
      at += 1
      continue
    }

    if (text.startsWith('=>', at)) {
      if (angle === 0) return 'arrow'
      at += 2
      expectingType = true
      continue
    }

    if (character === '{' && !expectingType && angle === 0) return 'body'

    if (character === '(' || character === '[' || character === '{') {
      const end = closing(text, at)
      if (end === -1) return null
      at = end + 1
      expectingType = false
      continue
    }

    if (character === "'" || character === '"' || character === '`') {
      at = endOfString(text, at)
      expectingType = false
      continue
    }

    if (character === '<') {
      angle += 1
      at += 1
      expectingType = true
      continue
    }

    if (character === '>') {
      if (angle === 0) return null
      angle -= 1
      at += 1
      expectingType = false
      continue
    }

    if ('|&?:,.'.includes(character)) {
      if (character === ',' && angle === 0) return null
      at += 1
      expectingType = character !== '.'
      continue
    }

    if (IDENTIFIER_PART.test(character)) {
      const end = identifierEnd(text, at)
      expectingType = TYPE_OPERATORS.has(text.slice(at, end))
      at = end
      continue
    }

    return null
  }

  return null
}

/** Statement heads whose parenthesised part is followed by a body and binds nothing. */
const CONTROL_HEADS = new Set(['if', 'for', 'while', 'switch', 'with'])

/**
 * What the brackets from `open` to `close` are, when they bind names: a
 * `catch` binding, or the parameters of an arrow function, a function or a
 * method. Null when they bind nothing.
 */
function parameterListKind(text: string, open: number, close: number): string | null {
  const before = text.slice(Math.max(0, open - 200), open)

  if (/(?<![\w$.])catch\s*$/.test(before)) return 'a `catch` binding'

  const after = afterParameters(text, close)

  if (after === 'arrow') return 'a callback parameter'
  if (/(?<![\w$.])function\b\s*\*?\s*(?:[A-Za-z_$][\w$]*)?\s*(?:<[^()]*>)?\s*$/.test(before)) {
    return 'a parameter of a nested function'
  }

  if (after === 'body') {
    const head = /([A-Za-z_$][\w$]*)\s*(?:<[^()]*>)?\s*$/.exec(before)?.[1]
    if (head === undefined || !CONTROL_HEADS.has(head)) return 'a method parameter'
  }

  return null
}

/**
 * Where a type annotation that starts at `index`, just past its `:`, ends: at
 * the `=`, `,`, `;` or closing bracket after it, or at a line break the
 * statement does not carry on past.
 */
function endOfType(text: string, index: number, indent: number): number {
  let at = index
  let angle = 0

  while (at < text.length) {
    const character = text.charAt(at)

    if (character === "'" || character === '"' || character === '`') {
      at = endOfString(text, at)
      continue
    }

    if (character === '(' || character === '[' || character === '{') {
      const close = closing(text, at)
      if (close === -1) return text.length
      at = close + 1
      continue
    }

    if (character === '=' && text.charAt(at + 1) === '>') {
      at += 2
      continue
    }

    if (character === '<') angle += 1
    if (character === '>' && angle > 0) angle -= 1

    if (angle === 0 && '=,;)]}'.includes(character)) return at
    if (character === '\n' && angle === 0 && !carriesOn(text, at, indent)) return at

    at += 1
  }

  return at
}

/**
 * Where an initialiser that starts at `index`, just past its `=`, ends: at a
 * `,` or `;` outside brackets, at the bracket that closes around it, or at a
 * line break the statement does not carry on past.
 */
function endOfInitialiser(text: string, index: number, indent: number): number {
  let at = index

  while (at < text.length) {
    const character = text.charAt(at)

    if (character === "'" || character === '"' || character === '`') {
      at = endOfString(text, at)
      continue
    }

    if (character === '(' || character === '[' || character === '{') {
      const close = closing(text, at)
      if (close === -1) return text.length
      at = close + 1
      continue
    }

    if (',;)]}'.includes(character)) return at
    if (character === '\n' && !carriesOn(text, at, indent)) return at

    at += 1
  }

  return at
}

/**
 * The names bound by the declarator list that starts at `index`, just past a
 * `const`, `let`, `var` or `using`: every declarator, pattern or name, however
 * many there are and whatever their types and initialisers hold.
 */
function declaratorNames(text: string, index: number): string[] {
  const indent = indentAt(text, index)
  const names: string[] = []
  let at = index

  for (;;) {
    at = skipSpace(text, at)
    const character = text.charAt(at)

    if (character === '{' || character === '[') {
      const close = closing(text, at)
      if (close === -1) break
      names.push(...bindingNames(text.slice(at, close + 1)))
      at = close + 1
    } else if (IDENTIFIER_START.test(character)) {
      const end = identifierEnd(text, at)
      names.push(text.slice(at, end))
      at = end
    } else {
      break
    }

    at = skipSpace(text, at)
    if (text.charAt(at) === '!') at = skipSpace(text, at + 1)
    if (text.charAt(at) === ':') at = skipSpace(text, endOfType(text, at + 1, indent))
    if (text.charAt(at) === '=' && text.charAt(at + 1) !== '=' && text.charAt(at + 1) !== '>') {
      at = endOfInitialiser(text, at + 1, indent)
    }

    if (text.charAt(at) !== ',') break
    at += 1
  }

  return names
}

/**
 * Every name `text` binds other than by the declarations that open their own
 * line, which the region records itself — each with what binds it:
 *
 *  - every bracket pair that is a parameter list: an arrow function's (its
 *    return type read through), a function's, a method's, and a `catch`
 *    binding, whatever the parameters hold — patterns, defaults with calls in
 *    them, function types;
 *  - a bare arrow parameter, `row => …`;
 *  - every declarator of a `const`, `let` or `var` that does not open its
 *    line — which is every `for` binding — and every declarator after the
 *    first of one that does; and every declarator of a `using`, which the
 *    region's own declarations do not record;
 *  - the name of a function or class that does not open its line.
 *
 * It reads text, not a parse, and it errs one way on purpose: every bracket
 * pair is examined, inside strings and comments too, so a name wrongly taken
 * for a binding costs a refusal, where a binding missed would cost a verdict
 * about a value the gate never reads.
 */
function bindingsIn(text: string): Map<string, string> {
  const bindings = new Map<string, string>()
  const bind = (names: string[], kind: string): void => {
    for (const name of names) if (name !== '' && !bindings.has(name)) bindings.set(name, kind)
  }

  const onlyIndentBefore = (index: number, allowing = ''): boolean => {
    const lineStart = text.lastIndexOf('\n', index - 1) + 1
    return new RegExp(`^[ \\t]+${allowing}$`).test(text.slice(lineStart, index))
  }

  for (let index = text.indexOf('('); index !== -1; index = text.indexOf('(', index + 1)) {
    const close = closing(text, index)
    const kind = close === -1 ? null : parameterListKind(text, index, close)
    if (kind !== null) bind(bindingNames(text.slice(index + 1, close)), kind)
  }

  for (const match of text.matchAll(/(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>/g)) {
    bind([match[1] ?? ''], 'a callback parameter')
  }

  for (const match of text.matchAll(/(?<![\w$.])(const|let|var|using(?=\s+[A-Za-z_$][\w$]*\s*=))\s+(?=[A-Za-z_$[{])/g)) {
    const names = declaratorNames(text, match.index + match[0].length)
    const inFor = /(?<![\w$.])for\s*(?:await\s*)?\(\s*$/.test(text.slice(Math.max(0, match.index - 40), match.index))

    if (match[1] === 'using') {
      bind(names, 'a `using` declaration')
    } else if (onlyIndentBefore(match.index)) {
      bind(names.slice(1), 'a second declarator on a line')
    } else {
      bind(names, inFor ? 'a `for` binding' : 'a declaration that does not open its line')
    }
  }

  for (const match of text.matchAll(/(?<![\w$.])(function\b\s*\*?\s*|class\s+)([A-Za-z_$][\w$]*)/g)) {
    if (!onlyIndentBefore(match.index, '(?:async\\s+)?')) {
      bind([match[2] ?? ''], match[1]?.startsWith('class') === true ? 'a nested class' : 'a nested function')
    }
  }

  return bindings
}

function addDeclaration(into: Map<string, Declaration[]>, name: string, declaration: Declaration): void {
  const existing = into.get(name) ?? []
  existing.push(declaration)
  into.set(name, existing)
}

/** The parameters of the declaration that opens at `offset` in `text`. */
/**
 * The parameters of the declaration that opens at `offset`: the first
 * function's or arrow's parameter list that opens on its line — wrapped in a
 * call or not, so `memo(function X({ … }) {` and `forwardRef((props, ref) =>`
 * are read like `function X({ … }) {` — or else a bare arrow parameter. A line
 * that ends on its `=` is read with the next.
 */
function openerParams(text: string, offset: number, lineEnd: number): string[] {
  const nextBreak = text.indexOf('\n', lineEnd + 1)
  const searchEnd = /=\s*$/.test(text.slice(offset, lineEnd)) ? (nextBreak === -1 ? text.length : nextBreak) : lineEnd
  const head = text.slice(offset, searchEnd)

  for (let open = text.indexOf('(', offset); open !== -1 && open < searchEnd; open = text.indexOf('(', open + 1)) {
    const close = closing(text, open)
    if (close === -1) break

    const kind = parameterListKind(text, open, close)
    if (kind === 'a callback parameter' || kind === 'a parameter of a nested function') {
      return bindingNames(text.slice(open + 1, close))
    }
  }

  const bareArrow = /=\s*(?:async\s+)?([A-Za-z_$][\w$]*)\s*=>/.exec(head)

  return bareArrow?.[1] === undefined ? [] : [bareArrow[1]]
}

/** The names an indented line declares at its start: a pattern's, or one `const`, `let`, `var`, `function` or `class`. */
function lineStartNames(text: string, offset: number, line: string): string[] {
  if (!/^\s+/.test(line)) return []

  const pattern = /^\s+(?:const|let|var)\s+([{[])/.exec(line)
  if (pattern !== null) {
    const open = offset + pattern[0].length - 1
    const close = closing(text, open)
    return bindingNames(text.slice(open, close === -1 ? open + 1 : close + 1))
  }

  const other =
    /^\s+(?:(?:let|var|const)\s+([A-Za-z_$][\w$]*)|(?:async\s+)?function\b\s*\*?\s*([A-Za-z_$][\w$]*)|class\s+([A-Za-z_$][\w$]*))/.exec(line)
  const name = other?.[1] ?? other?.[2] ?? other?.[3]

  return name === undefined ? [] : [name]
}

function modelOf(source: SourceFile): FileModel {
  // Comments blanked, so commented-out code declares nothing and an
  // apostrophe in a comment cannot open a string. Same length, same lines.
  const text = withoutComments(source.text)
  const lines = text.split('\n')
  const rawLines = source.text.split('\n')
  const offsets: number[] = []
  let running = 0

  for (const line of lines) {
    offsets.push(running)
    running += line.length + 1
  }

  const lineEndOf = (index: number): number => (offsets[index] ?? 0) + (lines[index]?.length ?? 0)

  /** A `const` line's initialiser, when the statement is complete on that line. */
  const initialiserOn = (index: number, rest: string): string | null => {
    const init = substitutable(rest)
    const indent = /^[ \t]*/.exec(lines[index] ?? '')?.[0].length ?? 0

    return init !== null && !carriesOn(text, lineEndOf(index), indent) ? init : null
  }

  const openers: { line: number; name: string; head: number }[] = []
  const module = new Map<string, Declaration[]>()

  lines.forEach((line, index) => {
    const match = OPENER.exec(line)
    if (match === null) return

    openers.push({ line: index, name: match[1] ?? match[2] ?? match[3] ?? '(anonymous)', head: match[0].length })

    // A column-zero `const`, exported or not, is visible to every region.
    const declaration = DECLARATION.exec(line)
    if (declaration?.[1] !== undefined && /^(?:export\s+)?const\b/.test(line)) {
      addDeclaration(module, declaration[1], { line: index, init: initialiserOn(index, declaration[3] ?? '') })
    } else if (match[3] !== undefined || match[1] !== undefined || match[2] !== undefined) {
      addDeclaration(module, match[1] ?? match[2] ?? match[3] ?? '', { line: index, init: null })
    }
  })

  const regions: Region[] = []
  const firstOpener = openers[0]?.line ?? lines.length

  regions.push({ name: '(module)', start: 0, end: firstOpener, params: new Set(), innerBindings: new Map(), declarations: new Map() })

  openers.forEach((opener, position) => {
    const start = opener.line
    const end = openers[position + 1]?.line ?? lines.length
    const startOffset = offsets[start] ?? 0
    const params = openerParams(text, startOffset, lineEndOf(start))
    const endOffset = offsets[end] ?? text.length

    const declarations = new Map<string, Declaration[]>()

    for (let index = start + 1; index < end; index += 1) {
      const line = lines[index] ?? ''
      const declaration = /^\s+/.test(line) ? DECLARATION.exec(line) : null

      if (declaration?.[1] !== undefined) {
        addDeclaration(declarations, declaration[1], { line: index, init: initialiserOn(index, declaration[3] ?? '') })
        continue
      }

      for (const name of lineStartNames(text, offsets[index] ?? 0, line)) {
        addDeclaration(declarations, name, { line: index, init: null })
      }
    }

    /*
     * Everything after the opener's own name, its parameter list included:
     * the region's own parameters are then found twice, which `lookup` reads
     * the same as once. Read twice more, with comments and without, keeping
     * every name either reading finds: the raw text can hide a binding behind
     * an apostrophe in a comment, the blanked text behind a `//` that was
     * JSX text rather than a comment.
     */
    const bodyStart = startOffset + opener.head
    const innerBindings = bindingsIn(text.slice(bodyStart, endOffset))
    for (const [name, kind] of bindingsIn(source.text.slice(bodyStart, endOffset))) {
      if (!innerBindings.has(name)) innerBindings.set(name, kind)
    }

    // A declaration opening its line that only the raw text shows — blanked
    // as a comment, which is right for commented-out code and wrong for a
    // `/*` that was JSX text — is one more binding the gate cannot place.
    const seen = new Map<string, number>()
    for (let index = start + 1; index < end; index += 1) {
      for (const name of lineStartNames(source.text, offsets[index] ?? 0, rawLines[index] ?? '')) {
        seen.set(name, (seen.get(name) ?? 0) + 1)
      }
    }
    for (const [name, count] of seen) {
      if (count > (declarations.get(name)?.length ?? 0) && !innerBindings.has(name)) {
        innerBindings.set(name, 'a declaration inside what reads as a comment')
      }
    }

    regions.push({
      name: opener.name,
      start,
      end,
      params: new Set(params),
      innerBindings,
      declarations,
    })
  })

  return { file: source.file, lines, regions, module }
}

type Lookup = { kind: 'substitute'; init: string } | { kind: 'free' } | { kind: 'refused'; reason: string }

function lookup(name: string, model: FileModel, region: Region): Lookup {
  const local = region.declarations.get(name) ?? []
  const parameter = region.params.has(name)
  const inner = region.innerBindings.get(name)
  // The shadow filter, in this one place: a parameter of the region's own
  // declaration hides a column-zero `const` of the same name.
  const module = parameter ? [] : (model.module.get(name) ?? [])

  if (local.length > 1) {
    return { kind: 'refused', reason: `\`${name}\` is declared ${String(local.length)} times in ${region.name}` }
  }

  if (local.length === 1 && parameter) {
    return {
      kind: 'refused',
      reason: `\`${name}\` is a parameter of ${region.name} and is also declared inside it, so which one this gate reads cannot be told from the text`,
    }
  }

  if (inner !== undefined && (local.length > 0 || module.length > 0)) {
    const where = local.length > 0 ? `in ${region.name} and is also ${inner} there` : `at module scope and is also ${inner} in ${region.name}`

    return {
      kind: 'refused',
      reason: `\`${name}\` is declared ${where}, so which one this gate reads cannot be told from the text`,
    }
  }

  if (local.length === 0 && module.length > 1) {
    return { kind: 'refused', reason: `\`${name}\` is declared ${String(module.length)} times at module scope` }
  }

  const found = local[0] ?? module[0]

  if (found === undefined || found.init === null) return { kind: 'free' }

  /*
   * A column-zero `const` is written at module scope, and its initialiser
   * reads module scope. Substituted as text into a gate, every name in it
   * would be read in the gate's region instead — so a name the region binds
   * too would be read as the region's, which is how an imported object's
   * `.data` becomes the component's own query and is judged as one.
   */
  if (local.length === 0) {
    const captured = [...namesIn(found.init)].find(
      (read) => region.declarations.has(read) || region.params.has(read) || region.innerBindings.has(read),
    )

    if (captured !== undefined) {
      return {
        kind: 'refused',
        reason: `\`${name}\` is a column-zero constant whose initialiser reads \`${captured}\`, which ${region.name} binds as well, so substituted here it would read the wrong one`,
      }
    }
  }

  return { kind: 'substitute', init: found.init }
}

/** The names `text` reads: every identifier that is not a property name or a keyword. */
function namesIn(text: string): Set<string> {
  const names = new Set<string>()

  mapIdentifiers(text, (name) => {
    names.add(name)
    return null
  })

  return names
}

function resolve(expression: string, model: FileModel, region: Region): { resolved: string; refusal: string | null } {
  let text = expression

  for (let pass = 0; pass < 32; pass += 1) {
    // An object rather than two `let`s, because both are written inside the
    // callback, where the compiler's flow analysis cannot follow them.
    const outcome: { changed: boolean; refusal: string | null } = { changed: false, refusal: null }

    const next = mapIdentifiers(text, (name) => {
      const found = lookup(name, model, region)

      if (found.kind === 'refused') {
        outcome.refusal ??= found.reason
        return null
      }

      if (found.kind === 'free') return null

      outcome.changed = true
      return `(${found.init})`
    })

    if (outcome.refusal !== null) return { resolved: next, refusal: outcome.refusal }
    if (!outcome.changed) return { resolved: next, refusal: null }

    text = next
  }

  return { resolved: text, refusal: 'its constants do not settle after 32 substitutions' }
}

const GATE = /(?<![\w$.-])(disabled|ready)=\{/g

function gatesOf(source: SourceFile): Gate[] {
  const model = modelOf(source)
  const gates: Gate[] = []

  for (const match of source.text.matchAll(GATE)) {
    const open = match.index + match[0].length - 1
    const close = closing(source.text, open)
    if (close === -1) continue

    const expression = source.text.slice(open + 1, close).replace(/\s+/g, ' ').trim()
    const line = source.text.slice(0, match.index).split('\n').length
    const region =
      [...model.regions].reverse().find((candidate) => candidate.start <= line - 1 && line - 1 < candidate.end) ??
      model.regions[0]

    if (region === undefined) continue

    const { resolved, refusal } = resolve(expression, model, region)

    gates.push({
      file: source.file,
      line,
      prop: match[1] === 'ready' ? 'ready' : 'disabled',
      expression,
      region: region.name,
      resolved,
      refusal,
    })
  }

  return gates
}

// ---------------------------------------------------------------------------
// Judging a gate
// ---------------------------------------------------------------------------

const REACHES_DATA = /\.data\b/

/** Every `X` written `X.data` or `X?.data`: the queries this gate waits on. */
function queryRoots(text: string): Set<string> {
  return new Set([...text.matchAll(/(?<![\w$.])([A-Za-z_$][\w$]*)\s*(?:\?\.|\.)data\b/g)].map((match) => match[1] ?? ''))
}

const EVALUABLE_GLOBALS = new Set(['undefined', 'NaN', 'Infinity', 'Number', 'String', 'Boolean', 'Math', 'Array', 'Object'])

/** What an unresolved TanStack query answers, and nothing else. */
const UNRESOLVED_QUERY: Record<QueryState, Record<string, unknown>> = {
  pending: {
    data: undefined,
    error: null,
    status: 'pending',
    fetchStatus: 'fetching',
    isPending: true,
    isLoading: true,
    isFetching: true,
    isFetched: false,
    isSuccess: false,
    isError: false,
    isRefetching: false,
    isPlaceholderData: false,
  },
  failed: {
    data: undefined,
    error: new Error('the read failed'),
    status: 'error',
    fetchStatus: 'idle',
    isPending: false,
    isLoading: false,
    isFetching: false,
    isFetched: true,
    isSuccess: false,
    isError: true,
    isRefetching: false,
    isPlaceholderData: false,
  },
}

class Unknowable extends Error {}

/**
 * A value as a sentence. This can throw — an object with no prototype, or a
 * proxy, has no way to become a string — which is why it is only ever called
 * inside the judge's `try`: outside it, the gate being described would die
 * without its name.
 */
function describeValue(value: unknown): string {
  if (typeof value === 'string') return JSON.stringify(value)
  if (typeof value === 'function') return 'a function'
  // eslint-disable-next-line @typescript-eslint/no-base-to-string -- how an arbitrary value describes itself, throwing included
  if (typeof value === 'object' && value !== null) return `an object (${String(value)})`

  return String(value)
}

function describeThrown(error: unknown): string {
  return error instanceof Error ? `${error.name}: ${error.message}` : `a thrown ${typeof error}`
}

function judgeIn(gate: Gate, state: QueryState): Verdict {
  try {
    const roots = queryRoots(gate.resolved)
    const unknown: string[] = []

    const stubFor = (root: string): unknown =>
      new Proxy(UNRESOLVED_QUERY[state], {
        get(target, key) {
          if (typeof key === 'symbol') return undefined
          if (key in target) return target[key]
          unknown.push(`${root}.${key}`)
          return undefined
        },
      })

    const unanswerable = (name: string): unknown =>
      new Proxy(() => undefined, {
        get() {
          throw new Unknowable(name)
        },
        apply() {
          throw new Unknowable(name)
        },
        construct() {
          throw new Unknowable(name)
        },
      })

    const scope = new Proxy(
      {},
      {
        has: () => true,
        get(_target, key) {
          if (key === Symbol.unscopables) return undefined

          const name = String(key)

          if (roots.has(name)) return stubFor(name)
          if (EVALUABLE_GLOBALS.has(name)) return (globalThis as Record<string, unknown>)[name]

          unknown.push(name)
          return unanswerable(name)
        },
      },
    )

    // Evaluated, not parsed: this is the whole design, and `with` is the
    // only way to route every free name through the stub scope above.
    // eslint-disable-next-line @typescript-eslint/no-implied-eval -- evaluating the gate is the point
    const evaluate = new Function('scope', `with (scope) { return (${gate.resolved}\n) }`) as (scope: object) => unknown
    const value = evaluate(scope)

    if (unknown.length > 0) {
      return { kind: 'unjudgeable', state, reason: `reads ${[...new Set(unknown)].join(', ')}, which an unresolved query cannot answer` }
    }

    if (typeof value !== 'boolean') {
      return { kind: 'unjudgeable', state, reason: `comes out as ${describeValue(value)}, not a boolean` }
    }

    const live = gate.prop === 'disabled' ? !value : value

    return live ? { kind: 'live', state, value } : { kind: 'shut' }
  } catch (error) {
    if (error instanceof Unknowable) {
      return { kind: 'unjudgeable', state, reason: `reads ${error.message}, which an unresolved query cannot answer` }
    }

    return { kind: 'unjudgeable', state, reason: `throws ${describeThrown(error)}` }
  }
}

function judge(gate: Gate): Verdict {
  if (gate.refusal !== null) return { kind: 'unjudgeable', state: null, reason: gate.refusal }

  const verdicts = (['pending', 'failed'] as const).map((state) => judgeIn(gate, state))

  return (
    verdicts.find((verdict) => verdict.kind === 'live') ??
    verdicts.find((verdict) => verdict.kind === 'unjudgeable') ?? { kind: 'shut' }
  )
}

function keyOf(gate: Gate): string {
  return `${gate.file}::${gate.expression}`
}

function explainLive(gate: Gate, verdict: Verdict & { kind: 'live' }): string {
  return (
    `${gate.file}:${String(gate.line)} ${gate.prop}={${gate.expression}} resolves to ${gate.resolved}; ` +
    `with the query unresolved (${verdict.state}) this is ${String(verdict.value)}, which leaves the control LIVE.`
  )
}

function explainUnjudgeable(gate: Gate, verdict: Verdict & { kind: 'unjudgeable' }): string {
  return `${gate.file}:${String(gate.line)} ${gate.prop}={${gate.expression}} resolves to ${gate.resolved}; it ${verdict.reason}.`
}

interface Report {
  gates: Gate[]
  reaching: Gate[]
  live: string[]
  unjudgeable: Map<string, string>
  shut: Gate[]
}

function report(sources: SourceFile[]): Report {
  const gates = sources.flatMap(gatesOf)
  const reaching = gates.filter((gate) => gate.refusal !== null || REACHES_DATA.test(gate.resolved))
  const live: string[] = []
  const unjudgeable = new Map<string, string>()
  const shut: Gate[] = []

  for (const gate of reaching) {
    const verdict = judge(gate)

    if (verdict.kind === 'live') live.push(explainLive(gate, verdict))
    else if (verdict.kind === 'unjudgeable') unjudgeable.set(keyOf(gate), explainUnjudgeable(gate, verdict))
    else shut.push(gate)
  }

  return { gates, reaching, live, unjudgeable, shut }
}

function probe(text: string, file = 'probe/Probe.tsx'): Report {
  return report([{ file, text }])
}

// ---------------------------------------------------------------------------
// The gate
// ---------------------------------------------------------------------------

/**
 * The finding's own two gates, each named by its file and the query it waits
 * on. Each must always be judged, and judged shut: rewritten into a shape this
 * file cannot read — a `const` across lines, a destructured `data`, a hook —
 * it would otherwise drop out of the judged set in silence, and the defect
 * could come back with it.
 */
const FINDING_GATES = [
  { file: 'features/auth/RegisterPage.tsx', query: 'options', control: 'Create account' },
  { file: 'features/controlCenter/PlansPage.tsx', query: 'desired', control: 'Compute plan' },
] as const

/** Those of the finding's own gates that `result` did not judge shut. */
function findingGatesNotJudgedShut(result: Report): string[] {
  return FINDING_GATES.filter(
    ({ file, query }) => !result.shut.some((gate) => gate.file === file && queryRoots(gate.resolved).has(query)),
  ).map(({ file, control }) => `${file} (${control})`)
}

describe('a control that waits on an answer is not live before the answer', () => {
  // Built inside the tests rather than while collecting them, so a gate that
  // throws fails a named test instead of taking the whole file down unnamed.
  let built: Report | undefined
  const tree = (): Report => (built ??= report(readSources()))

  it('finds the gates to check', () => {
    // Floors, so a broken reader cannot pass this file by finding nothing.
    expect(tree().gates.length).toBeGreaterThan(50)
    expect(tree().reaching.length).toBeGreaterThanOrEqual(5)
    // And the finding's own gates are always among those judged, and shut.
    expect(
      findingGatesNotJudgedShut(tree()),
      'One of F-21’s own gates is no longer judged shut. If it was rewritten into a shape this file cannot read, ' +
        'rewrite it into one it can: this file is what keeps it fixed.',
    ).toEqual([])
  })

  it('leaves no control live while the query behind it is unresolved', () => {
    expect(
      tree().live,
      'A disabled= that is not true, or a ready= that is not false, while its query is pending or failed ' +
        'leaves the control pressable before the answer it depends on. Gate on the answer having arrived.',
    ).toEqual([])
  })

  it('judges every gate that reaches a query, or names why it is correct without being judged', () => {
    const unexplained = [...tree().unjudgeable.entries()]
      .filter(([key]) => GUARDED[key] === undefined)
      .map(([, explanation]) => explanation)

    expect(
      unexplained,
      'A gate this file cannot evaluate against an unresolved query. Make it evaluable, or add it to GUARDED with the reason it is correct.',
    ).toEqual([])
  })

  it('keeps GUARDED closed in both directions', () => {
    const stale = Object.keys(GUARDED).filter((key) => !tree().unjudgeable.has(key))

    expect(stale, 'GUARDED entries whose gate no longer exists, or now judges cleanly and needs no excuse.').toEqual([])
  })

  it('gives every GUARDED entry a reason somebody wrote', () => {
    const thin = Object.entries(GUARDED).filter(([, reason]) => reason.trim().length < 40).map(([key]) => key)

    expect(thin).toEqual([])
  })
})

/*
 * Drift, measured on the real tree: the pre-fix lines put back into the real
 * source text, in memory, and the gate run unmodified over the result.
 */
describe('the gate reddens on the defect it exists for', () => {
  const REGISTER = 'features/auth/RegisterPage.tsx'
  const PLANS = 'features/controlCenter/PlansPage.tsx'
  const REGISTER_FIXED = 'disabled={!registrationPermitted}'
  const REGISTER_BROKEN = 'disabled={registrationClosed}'
  const PLANS_FIXED = 'disabled={desired.data === undefined || desired.data.data === null}'
  const PLANS_BROKEN = 'disabled={desired.data?.data === null}'

  /** The tree, with each file's `[from, to]` pairs applied in order. */
  function withLines(replacements: Record<string, [string, string] | [string, string][]>): SourceFile[] {
    return readSources().map((source) => {
      const replacement = replacements[source.file]
      if (replacement === undefined) return source

      const pairs: [string, string][] = typeof replacement[0] === 'string' ? [replacement as [string, string]] : (replacement as [string, string][])
      let text = source.text

      for (const [from, to] of pairs) {
        expect(text, `${source.file} no longer carries ${from}; update this drift test with it`).toContain(from)
        text = text.replace(from, to)
      }

      return { ...source, text }
    })
  }

  it('finds zero on the tree as it is', () => {
    expect(report(readSources()).live).toEqual([])
  })

  it('finds one with F-21’s own polarity restored to registration', () => {
    const live = report(withLines({ [REGISTER]: [REGISTER_FIXED, REGISTER_BROKEN] })).live

    expect(live).toHaveLength(1)
    expect(live[0]).toContain(`${REGISTER}:`)
    expect(live[0]).toMatch(/registration_permitted\)* === false/)
    expect(live[0]).toContain('which leaves the control LIVE')
  })

  it('finds two with the Compute-plan twin restored as well', () => {
    const live = report(
      withLines({ [REGISTER]: [REGISTER_FIXED, REGISTER_BROKEN], [PLANS]: [PLANS_FIXED, PLANS_BROKEN] }),
    ).live

    expect(live).toHaveLength(2)
    expect(live.some((line) => line.startsWith(`${PLANS}:`) && line.includes('desired.data?.data === null'))).toBe(true)
  })

  it('does not certify F-21’s own defect written across lines, and names the gate it can no longer judge', () => {
    // "Not knowing is permission", in the codebase's own multi-line style. The
    // first line alone is bracket-balanced and reads as a gate that waits.
    const result = report(
      withLines({
        [REGISTER]: [
          '  const registrationPermitted = permitted === true\n',
          '  const registrationPermitted = options.data !== undefined\n    ? permitted === true\n    : true\n',
        ],
      }),
    )

    expect(result.shut.filter((gate) => gate.file === REGISTER)).toEqual([])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it('names the Compute-plan gate too when its twin is written across lines', () => {
    const result = report(
      withLines({
        [PLANS]: [
          [
            '  const desired = useDesiredState(server.id)\n',
            '  const desired = useDesiredState(server.id)\n  const computeClosed = desired.data\n    ?.data === null\n',
          ],
          [PLANS_FIXED, 'disabled={computeClosed}'],
        ],
      }),
    )

    expect(findingGatesNotJudgedShut(result)).toEqual([`${PLANS} (Compute plan)`])
  })
})

/*
 * The machinery, over probes that are not in the tree. Each one pins a
 * property whose loss would turn a live gate into a blessed one, or a
 * diagnosis into a crash.
 */
describe('the gate itself', () => {
  it('uses probes that are not in the tree', () => {
    expect(readSources().filter((source) => source.text.includes('useProbeAnswer'))).toEqual([])
  })

  it('calls a strict comparison against a sentinel live, and the answered-first gate shut', () => {
    const wrong = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={answer.data?.data === null}>x</Button>
}`)
    const right = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={answer.data === undefined || answer.data.data === null}>x</Button>
}`)

    expect(wrong.live).toHaveLength(1)
    expect(wrong.live[0]).toContain('which leaves the control LIVE')
    expect(right.live).toEqual([])
    expect(right.shut).toHaveLength(1)
  })

  it('reads ready= the other way round', () => {
    const wrong = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <ConfirmDialog ready={answer.data?.data.payable !== false} />
}`)
    const right = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <ConfirmDialog ready={answer.data?.data.payable === true} />
}`)

    expect(wrong.live).toHaveLength(1)
    expect(right.shut).toHaveLength(1)
  })

  it('follows constants three deep before judging', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const record = answer.data?.data
  const known = record ?? null
  const absent = known === null
  return <Button disabled={!absent}>x</Button>
}`)

    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain('answer.data?.data')
  })

  it('judges a failed query as well as a pending one', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={answer.isPending ? true : answer.data?.data === null}>x</Button>
}`)

    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain('(failed)')
  })

  it('resolves each component’s constants in that component, not in the first one', () => {
    const result = probe(`function First() {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === undefined
  return <Button disabled={shut}>x</Button>
}

function Second() {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === null
  return <Button disabled={shut}>x</Button>
}`)

    expect(result.shut.map((gate) => gate.region)).toEqual(['First'])
    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain('probe/Probe.tsx:10')
  })

  it('opens a region at a top-level arrow component too', () => {
    const result = probe(`const First = () => {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === undefined
  return <Button disabled={shut}>x</Button>
}

const Second = () => {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === null
  return <Button disabled={shut}>x</Button>
}`)

    expect(result.shut.map((gate) => gate.region)).toEqual(['First'])
    expect(result.live).toHaveLength(1)
  })

  it('does not reach into another component for a name its own does not declare', () => {
    const result = probe(`function First() {
  const answer = useProbeAnswer()
  const verdict = answer.data?.data === undefined
  return <Button disabled={verdict}>x</Button>
}

function Second() {
  const answer = useProbeAnswer()
  return <Button disabled={verdict || answer.data?.data === undefined}>x</Button>
}`)

    expect(result.unjudgeable.get('probe/Probe.tsx::verdict || answer.data?.data === undefined')).toContain('reads verdict')
  })

  it('lets a parameter hide a module constant of the same name', () => {
    const result = probe(`const answer = { data: { data: null } }

export function Probe({ answer }: { answer: Answer }) {
  return <Button disabled={answer.data?.data === null}>x</Button>
}`)

    // Substituting the module constant would evaluate to true and bless it.
    expect(result.live).toHaveLength(1)
  })

  it.each([
    ['`memo(function …)`', 'export const Probe = memo(function Probe({ ready }: { ready: boolean }) {\n  return <Button disabled={!ready}>x</Button>\n})'],
    ['`forwardRef((…) => …)`', 'export const Probe = forwardRef<HTMLButtonElement, Props>(({ ready }, ref) => {\n  return <Button ref={ref} disabled={!ready}>x</Button>\n})'],
    ['nothing, its arrow on the next line', 'export const Probe =\n  ({ ready }: { ready: boolean }) => <Button disabled={!ready}>x</Button>'],
  ])('lets a parameter of a component wrapped in %s hide a module constant too', (_wrapper, component) => {
    const result = probe(`import { probeState } from './fixtures'

const ready = probeState.data?.data.ok === true

${component}`)

    // The gate reads the prop. Substituted, the module constant would come
    // out false while pending, and `!ready` shut.
    expect(result.shut).toEqual([])
    expect(result.unjudgeable.size).toBe(0)
    expect(result.gates.map((gate) => gate.resolved)).toEqual(['!ready'])
  })

  it('refuses a module constant whose initialiser reads a name the component binds too', () => {
    const direct = `import { answer } from './fixtures'

const shut = answer.data === undefined

export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={shut}>x</Button>
}`
    // One constant further away, so the check is made at every substitution.
    const transitive = direct.replace('const shut = answer.data === undefined', 'const closed = answer.data === undefined\nconst shut = closed')

    for (const text of [direct, transitive]) {
      const result = probe(text)

      // Substituted as text, the imported object's `.data` would be read as
      // the component's own query: pending, so undefined, so "shut".
      expect(result.shut).toEqual([])
      expect([...result.unjudgeable.values()].join('\n')).toContain('whose initialiser reads `answer`, which Probe binds as well')
    }
  })

  it('refuses a name declared twice in one component rather than picking one', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return (
    <>
      {rows.map((row) => {
        const shut = answer.data?.data === undefined
        return <Button key={row} disabled={shut}>x</Button>
      })}
      {rows.map((row) => {
        const shut = answer.data?.data === null
        return <Button key={row} disabled={shut}>x</Button>
      })}
    </>
  )
}`)

    expect(result.live).toEqual([])
    expect(result.shut).toEqual([])
    expect([...result.unjudgeable.values()].every((line) => line.includes('declared 2 times in Probe'))).toBe(true)
    expect(result.unjudgeable.size).toBe(1)
  })

  it('refuses a constant that a callback parameter shadows rather than reading the constant', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const ready = answer.data?.data.ok === true
  return rows.map((ready) => <Button key={String(ready)} disabled={!ready}>x</Button>)
}`)

    // Substituting the component's constant would come out true, and bless a
    // gate that actually reads the row's own field.
    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::!ready')).toContain('also a callback parameter')
  })

  /*
   * Every other way a name can be bound inside a component. Each of these
   * probes, at the commit that introduced the refusal above, was reported
   * shut on the component's constant: a verdict about a value the control
   * never reads, in the direction that blesses it.
   */
  it.each([
    ['a destructured `catch` binding', 'try { run() } catch ({ ready }) { return <Button disabled={!ready}>x</Button> }', 'a `catch` binding'],
    ['a typed `catch` binding', 'try { run() } catch (ready: unknown) { return <Button disabled={!ready}>x</Button> }', 'a `catch` binding'],
    ['a classic `for` binding', 'for (let ready = 0; ready < 1; ready++) out.push(<Button disabled={!ready}>x</Button>)', 'a `for` binding'],
    ['a `for await` pattern', 'for await (const [ready] of stream) out.push(<Button disabled={!ready}>x</Button>)', 'a `for` binding'],
    ['a bare arrow parameter', 'return rows.map(ready => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a callback parameter whose default holds a call', 'return rows.map((ready = Boolean(0)) => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a callback parameter with a function type', 'return rows.map((ready: () => boolean) => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a parameter after one with a function type', 'return rows.map((row: () => void, ready: boolean) => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a pattern whose default is an arrow', 'return rows.map(({ ready = () => true }) => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a parameter behind a comment with an apostrophe', "return rows.map((\n    // the row's own flag\n    ready,\n  ) => <Button key=\"k\" disabled={!ready}>x</Button>)", 'a callback parameter'],
    ['a parameter after JSX text with an apostrophe', "return <div><p>Don't</p>{rows.map((ready) => <Button key=\"k\" disabled={!ready}>x</Button>)}</div>", 'a callback parameter'],
    ['a parameter after a `//` in JSX text', 'return <div><p>a // b</p>{rows.map((ready) => <Button key="k" disabled={!ready}>x</Button>)}</div>', 'a callback parameter'],
    ['a parameter with a generic return type', 'return rows.map((ready): ReturnType<typeof draw> => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a parameter with a function return type', 'return rows.map((ready): (() => JSX.Element) => () => <Button key="k" disabled={!ready}>x</Button>)', 'a callback parameter'],
    ['a parameter of a generic function expression', 'return rows.map(function <T>(ready: T) { return <Button key="k" disabled={!ready}>x</Button> })', 'a parameter of a nested function'],
    ['the name of a function expression', 'return rows.map(function ready() { return <Button key="k" disabled={!ready}>x</Button> })', 'a nested function'],
    ['the name of a class expression', 'const Cell = class ready { render() { return <Button disabled={!ready}>x</Button> } }', 'a nested class'],
    ['a method parameter', 'const table = { cell(ready: boolean) { return <Button disabled={!ready}>x</Button> } }', 'a method parameter'],
    ['a parameter property', 'const Cell = class { constructor(private readonly ready: boolean) { draw(<Button disabled={!ready}>x</Button>) } }', 'a method parameter'],
    ['a setter parameter', 'const view = { set value(ready: boolean) { draw(<Button disabled={!ready}>x</Button>) } }', 'a method parameter'],
    ['a declaration inside a line', 'return rows.map((row) => { const ready = row.ok; return <Button key={row.id} disabled={!ready}>x</Button> })', 'a declaration that does not open its line'],
    ['a second declarator on a line', 'if (answer.isError) {\n    let seen: Map<string, number> = new Map(), ready = seen.size > 0\n    return <Button disabled={!ready}>x</Button>\n  }', 'a second declarator on a line'],
    ['a `using` declaration', 'if (answer.isError) {\n    using ready = acquire()\n    return <Button disabled={!ready}>x</Button>\n  }', 'a `using` declaration'],
  ])('refuses a constant that %s shadows rather than reading the constant', (_shape, body, kind) => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const ready = answer.data?.data.ok === true
  ${body}
}`)

    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::!ready')).toContain(`is also ${kind} there`)
  })

  it('reads a declaration behind a `/*` that was JSX text as a binding it cannot place', () => {
    const result = probe(`import { probeState } from './fixtures'

const shut = probeState.data === undefined

export function Probe() {
  const answer = useProbeAnswer()
  const hint = <p>Globs like /* match everything</p>
  const shut = answer.data?.data === null
  return <Button disabled={shut}>{hint}</Button>
}`)

    // Blanked as a comment, the component's own `shut` would vanish and the
    // module's be judged in its place: shut, where the control is live.
    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::shut')).toContain('a declaration inside what reads as a comment')
  })

  it('refuses a name that is both a parameter of the component and declared inside it', () => {
    const result = probe(`export function Probe({ ready }: { ready: boolean }) {
  const answer = useProbeAnswer()
  if (answer.isError) {
    const ready = answer.data?.data.ok === true
    log(ready)
  }
  return <Button disabled={!ready}>x</Button>
}`)

    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::!ready')).toContain('is a parameter of Probe and is also declared inside it')
  })

  /*
   * A constant whose statement carries on past its line only hides. Its first
   * line alone is bracket-balanced in every one of these, and substituted it
   * would be judged as a gate it is not: the first two are live while pending
   * or failed, and the third is F-21's own "not knowing is permission".
   */
  it.each([
    ['an `||` on the next line', 'const open = answer.data?.data.ok === true\n    || answer.isPending\n  return <Button disabled={!open}>x</Button>', '!open'],
    ['an `&&` on the next line', 'const shut = answer.data === undefined\n    && answer.isFetching\n  return <Button disabled={shut}>x</Button>', 'shut'],
    ['a ternary across lines', 'const permitted = answer.data?.data.ok\n  const open = answer.data !== undefined\n    ? permitted === true\n    : true\n  return <Button disabled={!open}>x</Button>', '!open'],
    ['an operator ending the line, the next not indented', 'const shut = answer.data === undefined &&\n  answer.isFetching\n  return <Button disabled={shut}>x</Button>', 'shut'],
    ['a comment between the lines', 'const open = answer.data?.data.ok === true\n    // pending counts as open\n    || answer.isPending\n  return <Button disabled={!open}>x</Button>', '!open'],
    ['a call opening the next line, not indented', 'const shut = check\n  (answer.data)\n  return <Button disabled={shut}>x</Button>', 'shut'],
    // The backstop: this codebase indents a statement it carries on, so a
    // deeper line is read as one whatever it opens with.
    ['a deeper next line, whatever it opens with', 'const shut = answer.data?.data === null\n    log(answer)\n  return <Button disabled={shut}>x</Button>', 'shut'],
  ])('does not substitute the first line of a constant that carries on: %s', (_shape, body, resolved) => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  ${body}
}`)

    expect(result.shut).toEqual([])
    expect(result.gates.map((gate) => gate.resolved)).toEqual([resolved])
  })

  it('still substitutes a constant whose statement ends on its own line', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === null

  return <Button disabled={shut}>x</Button>
}`)

    expect(result.live).toHaveLength(1)
  })

  it('does not substitute an initialiser that holds a second declarator or a second statement', () => {
    for (const line of ['const shut = answer.data?.data === null, other = true', 'const shut = answer.data?.data === null; const other = true']) {
      const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  ${line}
  return <Button disabled={shut}>x</Button>
}`)

      // Substituted whole, the first would be `(…, other = true)`: true, and shut.
      expect(result.shut, line).toEqual([])
      expect(result.gates.map((gate) => gate.resolved), line).toEqual(['shut'])
    }
  })

  it.each([
    ['a function', 'export default function ({ ready }: { ready: boolean }) {\n  return <Button disabled={!ready}>x</Button>\n}'],
    ['an arrow', 'export default ({ ready }: { ready: boolean }) => <Button disabled={!ready}>x</Button>'],
  ])('opens a region at an export default of %s that has no name', (_kind, exported) => {
    const result = probe(`function First() {
  const answer = useProbeAnswer()
  const ready = answer.data?.data.ok === true
  return <Button disabled={!ready}>x</Button>
}

${exported}`)

    // First's gate is judged on First's constant, and the default export's
    // reads its own parameter, which is not First's business.
    expect(result.shut.map((gate) => gate.region)).toEqual(['First'])
    expect(result.unjudgeable.size).toBe(0)
  })

  it('judges a correct comparison with undefined rather than turning it away', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={answer.data?.data === undefined}>x</Button>
}`)

    expect(result.unjudgeable.size).toBe(0)
    expect(result.shut).toHaveLength(1)
  })

  it('names the gate when describing its value throws', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={answer.data?.data ?? Object.create(null)}>x</Button>
}`)

    const [explanation] = [...result.unjudgeable.values()]

    expect(explanation).toContain('probe/Probe.tsx:3')
    expect(explanation).toContain('throws')
  })

  it('names the gate when it throws on an unresolved query', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const current = answer.data?.data ?? null
  return <Button disabled={!current.applicable}>x</Button>
}`)

    expect(result.unjudgeable.get('probe/Probe.tsx::!current.applicable')).toContain('throws TypeError')
  })

  it('judges only what reaches .data, and says so', () => {
    const result = probe(`export function Probe() {
  const [count] = useState(0)
  return <Button disabled={count === 0}>x</Button>
}`)

    expect(result.gates).toHaveLength(1)
    expect(result.reaching).toEqual([])
  })

  it('collects disabled= and ready= and nothing that merely ends in them', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return (
    <>
      <Button aria-disabled={answer.data?.data === null}>x</Button>
      <Toggle isDisabled={answer.data?.data === null} xDisabled={answer.data?.data === null} />
      <Dialog notready={answer.data?.data === null} xReady={answer.data?.data === null} />
    </>
  )
}`)

    expect(result.gates).toEqual([])
  })
})
