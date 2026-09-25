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
 * are fixed in the components; this file keeps them fixed.
 *
 * ## What this gate asserts, exactly
 *
 * **No `disabled=` or `ready=` JSX prop in a `.tsx` file under `src` whose
 * resolved text literally contains `.data` leaves its control live while the
 * query behind that `.data` is unresolved.** "Live" means `disabled` comes out
 * anything but `true`, or `ready` anything but `false` — `Button` disables only
 * on `disabled === true`, and `ConfirmDialog`'s `ready` defaults to true.
 *
 * It narrows exactly where that sentence does, and the narrowing is a pattern
 * match applied before the evaluation: a prop whose resolved text does not
 * contain `.data` is never judged, however it came by its value. The shapes
 * that walks past are listed under "What it cannot see", with the sites in
 * this tree that have them.
 *
 * ## How: evaluate, do not pattern-match
 *
 * A list of wrong shapes waves through the first shape it was not taught —
 * which is how the second instance survived a grep. So each prop is
 * **evaluated**:
 *
 *  1. Its expression is resolved by substituting `const` initialisers to a
 *     fixpoint. A name is looked up in the gate's own region first — a region
 *     runs from one column-zero declaration (`function`, `class`, `const`,
 *     `let`, exported or not) to the next — and then among the file's
 *     column-zero `const`s, exported or not. A parameter of the region's own
 *     declaration hides a column-zero `const` of the same name, and so does
 *     any other binding the region declares (a destructured `const`, a
 *     `let`, an inner `function`). A `const` initialised by a hook call is
 *     never substituted: it is the query itself. Only an initialiser that
 *     is complete on its own line is substituted; any other `const` only
 *     hides.
 *  2. Resolution **refuses** rather than guesses in three cases, and a refusal
 *     is reported like any other gate that cannot be judged — whether or not
 *     the text reaches `.data`, since what the refused name would have
 *     brought in is exactly what is not known. The cases: a name declared
 *     more than once in the region; a declared name that is also a parameter
 *     of a callback, `catch` or `for` binding anywhere in the region, since
 *     which one a gate reads cannot be told from the text; and an expression
 *     that does not settle.
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
 * Run, `InvoicesPage`'s pay-from-credit `ready`), **2 guarded**, 0 live. With
 * the two pre-fix lines restored, the same run reports exactly those two as
 * live. **60** are dropped by the `.data` filter:
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
 * Seven shapes pass silently. The site counts are measured on this tree.
 *
 *  1. A query read through a destructured `data` — three sites, above.
 *  2. A gate passed as a spread, `<Button {...{ disabled: x }}>`: no
 *     `disabled=` text, so not even in the 66. No site.
 *  3. A value a parent read from a query and passed down, or a row of a list
 *     — 25 sites, and `AssignForm`'s, above.
 *  4. A value whose `const` is not substituted: one initialised through a
 *     hook (`useMemo`, `useState` from a query), one whose initialiser spans
 *     lines, or one whose initialiser holds an arrow function — the last so a
 *     `const` that is itself a function is never evaluated as a value, which
 *     also stops `ProvidersPage`'s and `AssignForm`'s `.find((…) => …)`.
 *  5. A gate computed in a `.ts` file, or by calling a function: the text
 *     holds a call, not a `.data`. No site.
 *  6. A control gated by a prop with another name (`aria-disabled`,
 *     `isDisabled`, `canSubmit`). No site under a gate-like name.
 *  7. A column-zero `const`'s initialiser is resolved in the gate's region,
 *     not at module scope, so a region binding with the same name as
 *     something that initialiser reads would be substituted in its place. A
 *     hook cannot run at module scope, so no module `const` can reach a
 *     query's `.data`; no site.
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
  /** Parameters of callbacks, `catch` and `for` bindings anywhere inside it. */
  innerBindings: Set<string>
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

/** Splits on `separator` where it is not inside brackets or a string. */
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

    if (character === '(' || character === '[' || character === '{' || character === '<') depth += 1
    if (character === ')' || character === ']' || character === '}' || character === '>') depth -= 1

    if (depth === 0 && character === separator) {
      parts.push(text.slice(from, index))
      from = index + 1
    }

    index += 1
  }

  parts.push(text.slice(from))

  return parts
}

/** The names a parameter list or a destructuring pattern binds. */
function bindingNames(list: string): string[] {
  const names: string[] = []

  for (const item of splitTopLevel(list, ',')) {
    let piece = item.trim().replace(/^\.\.\./, '')

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

const OPENER =
  /^(?:export\s+(?:default\s+)?)?(?:async\s+)?(?:function\s*\*?\s*([A-Za-z_$][\w$]*)|class\s+([A-Za-z_$][\w$]*)|(?:const|let|var)\s+([A-Za-z_$][\w$]*))/

const DECLARATION = /^\s*(?:export\s+)?const\s+([A-Za-z_$][\w$]*)\s*(?::\s*([^=]*?))?\s*=(?![=>])\s*(.*)$/

/** A `const` line's initialiser, when it is complete on that line and not a hook call. */
function substitutable(rest: string): string | null {
  const init = rest.replace(/\s+\/\/.*$/, '').replace(/;\s*$/, '').trim()

  if (init === '' || !balanced(init)) return null
  if (/^(?:await\s+)?use[A-Z0-9]\w*\s*(?:<[^>]*>)?\s*\(/.test(init)) return null
  if (/=>/.test(init) || /^(?:async\s+)?function\b/.test(init)) return null

  return init
}

function addDeclaration(into: Map<string, Declaration[]>, name: string, declaration: Declaration): void {
  const existing = into.get(name) ?? []
  existing.push(declaration)
  into.set(name, existing)
}

/** The parameters of the declaration that opens at `offset` in `text`. */
function openerParams(text: string, offset: number, lineEnd: number): { names: string[]; end: number } {
  const line = text.slice(offset, lineEnd)
  const functionMatch = /^[^(=]*\bfunction\b[^(]*\(/.exec(line)
  const arrowMatch = /=\s*(?:async\s+)?\(/.exec(line)
  const bareArrow = /=\s*(?:async\s+)?([A-Za-z_$][\w$]*)\s*=>/.exec(line)

  const open =
    functionMatch !== null
      ? offset + functionMatch[0].length - 1
      : arrowMatch !== null
        ? offset + arrowMatch.index + arrowMatch[0].length - 1
        : -1

  if (open !== -1) {
    const close = closing(text, open)
    const params = close === -1 ? '' : text.slice(open + 1, close)
    const isArrow = functionMatch !== null || /^\s*(?::[^=]*)?=>/.test(text.slice(close + 1, close + 200))

    return isArrow ? { names: bindingNames(params), end: close === -1 ? lineEnd : close + 1 } : { names: [], end: lineEnd }
  }

  if (bareArrow?.[1] !== undefined) return { names: [bareArrow[1]], end: lineEnd }

  return { names: [], end: lineEnd }
}

/** Callback parameters, `catch` and `for` bindings in `text`. */
function innerBindingsOf(text: string): string[] {
  const names: string[] = []

  for (const match of text.matchAll(/\(([^()]*)\)\s*(?::\s*[^=()]+?)?\s*=>/g)) {
    names.push(...bindingNames(match[1] ?? ''))
  }

  for (const match of text.matchAll(/(?<![\w$.])([A-Za-z_$][\w$]*)\s*=>/g)) {
    if (match[1] !== undefined) names.push(match[1])
  }

  for (const match of text.matchAll(/\bfunction\s*\*?\s*(?:[A-Za-z_$][\w$]*)?\s*\(([^()]*)\)/g)) {
    names.push(...bindingNames(match[1] ?? ''))
  }

  for (const match of text.matchAll(/\bcatch\s*\(\s*([A-Za-z_$][\w$]*)/g)) {
    if (match[1] !== undefined) names.push(match[1])
  }

  for (const match of text.matchAll(/\bfor\s*\(\s*(?:const|let|var)\s+(.+?)\s+(?:of|in)\b/g)) {
    names.push(...bindingNames(match[1] ?? ''))
  }

  return names
}

function modelOf(source: SourceFile): FileModel {
  const lines = source.text.split('\n')
  const offsets: number[] = []
  let running = 0

  for (const line of lines) {
    offsets.push(running)
    running += line.length + 1
  }

  const openers: { line: number; name: string }[] = []
  const module = new Map<string, Declaration[]>()

  lines.forEach((line, index) => {
    const match = OPENER.exec(line)
    if (match === null) return

    openers.push({ line: index, name: match[1] ?? match[2] ?? match[3] ?? '(anonymous)' })

    // A column-zero `const`, exported or not, is visible to every region.
    const declaration = DECLARATION.exec(line)
    if (declaration?.[1] !== undefined && /^(?:export\s+)?const\b/.test(line)) {
      addDeclaration(module, declaration[1], { line: index, init: substitutable(declaration[3] ?? '') })
    } else if (match[3] !== undefined || match[1] !== undefined || match[2] !== undefined) {
      addDeclaration(module, match[1] ?? match[2] ?? match[3] ?? '', { line: index, init: null })
    }
  })

  const regions: Region[] = []
  const firstOpener = openers[0]?.line ?? lines.length

  regions.push({ name: '(module)', start: 0, end: firstOpener, params: new Set(), innerBindings: new Set(), declarations: new Map() })

  openers.forEach((opener, position) => {
    const start = opener.line
    const end = openers[position + 1]?.line ?? lines.length
    const startOffset = offsets[start] ?? 0
    const lineEnd = startOffset + (lines[start]?.length ?? 0)
    const params = openerParams(source.text, startOffset, lineEnd)
    const endOffset = offsets[end] ?? source.text.length
    const body = source.text.slice(params.end, endOffset)

    const declarations = new Map<string, Declaration[]>()

    for (let index = start + 1; index < end; index += 1) {
      const line = lines[index] ?? ''

      if (!/^\s+/.test(line)) continue

      const pattern = /^\s+(?:const|let|var)\s+([{[])/.exec(line)
      if (pattern !== null) {
        const open = (offsets[index] ?? 0) + pattern[0].length - 1
        const close = closing(source.text, open)
        for (const name of bindingNames(source.text.slice(open, close === -1 ? open + 1 : close + 1))) {
          addDeclaration(declarations, name, { line: index, init: null })
        }
        continue
      }

      const declaration = DECLARATION.exec(line)
      if (declaration?.[1] !== undefined) {
        addDeclaration(declarations, declaration[1], { line: index, init: substitutable(declaration[3] ?? '') })
        continue
      }

      const other = /^\s+(?:(?:let|var|const)\s+([A-Za-z_$][\w$]*)|(?:async\s+)?function\s*\*?\s*([A-Za-z_$][\w$]*))/.exec(line)
      const name = other?.[1] ?? other?.[2]
      if (name !== undefined) addDeclaration(declarations, name, { line: index, init: null })
    }

    regions.push({
      name: opener.name,
      start,
      end,
      params: new Set(params.names),
      innerBindings: new Set(innerBindingsOf(body)),
      declarations,
    })
  })

  return { file: source.file, lines, regions, module }
}

type Lookup = { kind: 'substitute'; init: string } | { kind: 'free' } | { kind: 'refused'; reason: string }

function lookup(name: string, model: FileModel, region: Region): Lookup {
  const local = region.declarations.get(name) ?? []
  // The shadow filter, in this one place: a parameter of the region's own
  // declaration hides a column-zero `const` of the same name.
  const module = region.params.has(name) ? [] : (model.module.get(name) ?? [])

  if ((local.length > 0 || module.length > 0) && region.innerBindings.has(name)) {
    return {
      kind: 'refused',
      reason: `\`${name}\` is declared in ${region.name} and is also a callback parameter there, so which one this gate reads cannot be told from the text`,
    }
  }

  if (local.length > 1) {
    return { kind: 'refused', reason: `\`${name}\` is declared ${String(local.length)} times in ${region.name}` }
  }

  if (local.length === 0 && module.length > 1) {
    return { kind: 'refused', reason: `\`${name}\` is declared ${String(module.length)} times at module scope` }
  }

  const found = local[0] ?? module[0]

  if (found === undefined) return { kind: 'free' }

  return found.init === null ? { kind: 'free' } : { kind: 'substitute', init: found.init }
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

describe('a control that waits on an answer is not live before the answer', () => {
  // Built inside the tests rather than while collecting them, so a gate that
  // throws fails a named test instead of taking the whole file down unnamed.
  let built: Report | undefined
  const tree = (): Report => (built ??= report(readSources()))

  it('finds the gates to check', () => {
    // Floors, so a broken reader cannot pass this file by finding nothing.
    expect(tree().gates.length).toBeGreaterThan(50)
    expect(tree().reaching.length).toBeGreaterThanOrEqual(5)
    // And the finding's own gate is always among those judged.
    expect(tree().shut.map((gate) => gate.file)).toContain('features/auth/RegisterPage.tsx')
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

  function withLines(replacements: Record<string, [string, string]>): SourceFile[] {
    return readSources().map((source) => {
      const replacement = replacements[source.file]
      if (replacement === undefined) return source

      const [from, to] = replacement
      expect(source.text, `${source.file} no longer carries ${from}; update this drift test with it`).toContain(from)

      return { ...source, text: source.text.replace(from, to) }
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
