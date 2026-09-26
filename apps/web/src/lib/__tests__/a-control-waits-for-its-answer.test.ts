import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import ts from 'typescript'
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
 * are fixed in the components; this file keeps them fixed. Each of the two
 * controls is found as a control — every `Button` in its file that renders
 * its label — and not by its file or its query, and the gate on it must be
 * judged, and judged shut, waiting on its own query. So rewriting either gate
 * into a shape this file cannot read fails here as surely as reverting it,
 * and so does leaving it live while something else in the file waits
 * correctly in its place. `FINDING_GATES` says what it cannot find.
 *
 * ## What this gate asserts, exactly
 *
 * **No `disabled=` or `ready=` JSX attribute in a `.tsx` file under `src`
 * whose resolved text literally contains `.data` leaves its control live while
 * the query behind that `.data` is unresolved.** "Live" means `disabled` comes
 * out anything but `true`, or `ready` anything but `false` — `Button` disables
 * only on `disabled === true`, and `ConfirmDialog`'s `ready` defaults to true.
 * And **every `Button` that renders the label of one of F-21's own two
 * controls, Create account and Compute plan, carries a `disabled=` that is
 * judged shut waiting on that control's own query, with no spread after it**
 * (`FINDING_GATES`).
 *
 * It narrows exactly where that sentence does, and the narrowing is a pattern
 * match applied before the evaluation: a prop whose resolved text does not
 * contain `.data` is never judged, however it came by its value. The shapes
 * that walks past are listed under "What it cannot see", with the sites in
 * this tree that have them. Which binding a name in a gate reads is never
 * guessed: it is the one TypeScript's own binder gives it (step 1).
 *
 * ## How: evaluate, do not pattern-match
 *
 * A list of wrong shapes waves through the first shape it was not taught —
 * which is how the second instance survived a grep. So each prop is
 * **evaluated**:
 *
 *  1. Each file is parsed and bound by the `typescript` package this
 *     workspace builds with — one file at a time, with no library and no
 *     import followed — and each name in a gate is looked up with the
 *     compiler's own `getSymbolAtLocation`. A name therefore reads the binding
 *     the compiler gives it: the innermost in scope, whatever declares it — a
 *     parameter, a `catch` or `for` binding, a destructured name, a `const` in
 *     a block or a callback, a function or class name, an import — and a
 *     declaration in a block the gate is outside is not the one it reads. No
 *     binding is found by reading text: the compiler's scanner and parser
 *     deal with the brackets, strings, comments, regular expressions, types
 *     and JSX text around it.
 *  2. The expression is resolved by substituting `const` initialisers, to a
 *     fixpoint. A name is replaced by its initialiser, in parentheses, only
 *     when it is bound to a `const` declarator that has a name rather than a
 *     pattern and an initialiser of its own, and that initialiser is not a
 *     hook call — a call to a name that starts `use` and a capital or a
 *     digit, bare or as a member (`queries.useThing()`), under any
 *     parentheses, `await`, `as`, `satisfies` or `!`; a `const` initialised
 *     by one is the query itself — and holds no function, class, method or
 *     accessor, so that a `const` that is itself a function is never
 *     evaluated as a value. The initialiser is the compiler's node for it,
 *     so a statement written across lines is read whole, and a second
 *     declarator on its line is not part of it. The names inside a
 *     substituted initialiser are resolved
 *     where it is written, not where the gate is: a module constant that
 *     reads an import reads the import, whatever the component calls its own
 *     query. Every other name stays a name, and each binding keeps its own:
 *     two bindings that share a name in one resolved text are told apart by a
 *     suffix (`answer`, `answer$2`). Resolution **refuses** in two cases — a
 *     name bound by more than one declaration (a `var` declared twice, an
 *     overloaded function), and constants that do not settle (a cycle, or
 *     more than 32 deep) — and a refused gate is reported like any other
 *     that cannot be judged, whether or not its text reaches `.data`.
 *  3. The resolved text is run against a stub of an unresolved query —
 *     twice, once **pending** and once **failed**. A name written `X.data` or
 *     `X?.data` answers as that stub when it is bound to a `const` initialised
 *     by a hook call, and only then. Every other name reads as unknown — a
 *     parameter (a query passed down as a prop among them), an import, a
 *     `let`, a destructured name, `this` — except the evaluable globals
 *     (`undefined` among them, so a correct `=== undefined` gate is judged
 *     rather than turned away); a binding in the file named like one of them
 *     is renamed, so it cannot pass for the global. The stub answers only the
 *     fields an unresolved query is known to have; reading any other is also
 *     an unknown.
 *  4. The verdict is **shut** in both states, **live** in either, or
 *     **unjudgeable**: it threw, read an unknown, came out as something other
 *     than a boolean, or its resolution was refused — or it came out shut
 *     while a `const` it substituted, evaluated the same way, holds an object
 *     or a function. A `const` binding cannot change, but what such a value
 *     holds can be written to between its declaration and the control
 *     (`state.shut = false`, `rows.push(row)`), and the text does not show
 *     whether it was; a primitive cannot. Everything, the description of the
 *     value included, runs inside one `try`, so an unjudgeable gate is always
 *     reported with its file and line.
 *
 * A live gate is a failure, with the file, the line, the resolved expression
 * and the reason. An unjudgeable gate is a failure unless `GUARDED` names it
 * with the reason it is correct. `GUARDED` is closed in both directions: an
 * entry for a gate that no longer exists, or that now judges cleanly, is also
 * a failure. And a `GUARDED` entry never excuses a live verdict.
 *
 * ## The census, at the time of writing
 *
 * Dumped from this file's own machinery and then classified by hand. The
 * compiler's binding replaced a text reader at 2453cce, and the two were
 * measured against each other on this tree: the same 66 gates, each resolved
 * to the same text, so the classification below carries over unchanged.
 *
 * **66** `disabled=`/`ready=` attributes in 37 files. **6** reach `.data`:
 * **4 shut** (`RegisterPage`'s Create account, `PlansPage`'s Compute plan and
 * Run, `InvoicesPage`'s pay-from-credit `ready`), **2 guarded**, 0 live, and
 * **0 refused**. Every name the six read `.data` of is a `const` initialised
 * by a hook call. With the two pre-fix lines restored, the same run reports
 * exactly those two as live. **60** are dropped by the `.data` filter:
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
 *     `disabled=` attribute, so not even in the 66. No site. And a spread
 *     after a `disabled=` can override it, where the attribute is what is
 *     judged: one site, `Button`'s own `{...props}` after its `disabled=`,
 *     which cannot, since `disabled` is destructured out of `props`. On
 *     F-21's own two controls both are failures (`FINDING_GATES`).
 *  3. A value a parent read from a query and passed down, or a row of a list
 *     — 25 sites, and `AssignForm`'s, above. A query passed down whole and
 *     read for its `.data` is not silent: step 3 reads the parameter as an
 *     unknown, and the gate is reported. No site.
 *  4. A value whose `const` is not substituted: one initialised by a hook
 *     call (`useMemo`, `useState` from a query), and one whose initialiser
 *     holds a function — the last so a `const` that is itself a function is
 *     never evaluated as a value, which also stops `ProvidersPage`'s and
 *     `AssignForm`'s `.find((…) => …)`, the only two in this tree a gate
 *     reads. Every other binding — a `let`, a `var`, a parameter, a
 *     destructured name — stays a name, and reads as unknown if the gate
 *     reaches `.data`.
 *  5. A gate computed in a `.ts` file, or by calling a function: the text
 *     holds a call, not a `.data`. No site.
 *  6. A control gated by a prop with another name (`aria-disabled`,
 *     `isDisabled`, `canSubmit`). No site under a gate-like name.
 *  7. A global the program writes to. The evaluable globals are read as the
 *     language's own, and a write to one — `Math.flag = false`, a replaced
 *     `Number.isFinite` — can sit in any module, which the text of the
 *     gate's file does not show. No write to any of them in `src`.
 *
 * A `const` whose value is an object, written to after its declaration, is
 * not among them: step 4 reports it rather than judging it on what its
 * initialiser built. No gate in this tree substitutes one.
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

/** What a name left standing in a resolved gate is bound to. */
interface Leaf {
  /** A `const` initialised by a hook call: the query itself, and the only binding the stub answers for. */
  query: boolean
  /** The binding, in the words a failure message uses. */
  what: string
}

/** A JSX element, gated or not: what `FINDING_GATES` looks for a control among. */
interface Element {
  file: string
  /** Where its tag opens in the file: what a gate on it records as `element`. */
  at: number
  line: number
  tag: string
  /** The translation keys it renders (`labelsOf`). */
  labels: string[]
}

interface Gate {
  file: string
  line: number
  prop: Prop
  /** The attribute's own text, whitespace collapsed: what `GUARDED` is keyed on. */
  expression: string
  /** The top-level declaration the gate is written in, for messages. */
  region: string
  /** The element the attribute is on: its `at`, its tag and its labels. */
  element: number
  tag: string
  labels: string[]
  /** A spread follows the attribute on its element, and can override it. */
  overridable: boolean
  resolved: string
  /** Every name left standing in `resolved`, and what it is bound to. */
  names: Map<string, Leaf>
  /** Every `const` substituted into `resolved`, with its value as substituted. */
  constants: Constant[]
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

/*
 * One file, parsed and bound on its own. No library and no import is read:
 * a name from another file is an import binding here, and a global is a name
 * bound nowhere in the file. Binding is all this needs from the compiler, and
 * binding is per file.
 */
const COMPILER_OPTIONS: ts.CompilerOptions = {
  noLib: true,
  noResolve: true,
  types: [],
  noEmit: true,
  jsx: ts.JsxEmit.Preserve,
  target: ts.ScriptTarget.Latest,
  module: ts.ModuleKind.ESNext,
  moduleDetection: ts.ModuleDetectionKind.Force,
}

interface Compiled {
  file: ts.SourceFile
  checker: ts.TypeChecker
}

function compile(source: SourceFile): Compiled {
  const name = `/src/${source.file}`
  const file = ts.createSourceFile(name, source.text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX)
  const host: ts.CompilerHost = {
    getSourceFile: (requested) => (requested === name ? file : undefined),
    getDefaultLibFileName: () => '/lib.d.ts',
    writeFile: () => undefined,
    getCurrentDirectory: () => '/',
    getCanonicalFileName: (requested) => requested,
    useCaseSensitiveFileNames: () => true,
    getNewLine: () => '\n',
    fileExists: (requested) => requested === name,
    readFile: () => undefined,
  }
  const program = ts.createProgram({ rootNames: [name], options: COMPILER_OPTIONS, host })

  if (program.getSourceFile(name) !== file) throw new Error(`${source.file} was not bound as the file it was parsed as`)

  return { file, checker: program.getTypeChecker() }
}

// ---------------------------------------------------------------------------
// What a name is bound to
// ---------------------------------------------------------------------------

/** Past parentheses, `await`, and the type-only wrappers, to the expression that does the work. */
function unwrapped(expression: ts.Expression): ts.Expression {
  let at = expression

  while (
    ts.isParenthesizedExpression(at) ||
    ts.isAwaitExpression(at) ||
    ts.isAsExpression(at) ||
    ts.isSatisfiesExpression(at) ||
    ts.isNonNullExpression(at) ||
    ts.isTypeAssertionExpression(at)
  ) {
    at = at.expression
  }

  return at
}

/** A call to `use…` or `something.use…`: a hook, whose result this file takes to be the query itself. */
function isHookCall(expression: ts.Expression): boolean {
  const call = unwrapped(expression)
  if (!ts.isCallExpression(call)) return false

  const callee = call.expression
  const name = ts.isIdentifier(callee) ? callee.text : ts.isPropertyAccessExpression(callee) ? callee.name.text : ''

  return /^use[A-Z0-9]/.test(name)
}

/** Whether `node` is, or anywhere holds, a function, a class, a method or an accessor. */
function holdsFunction(node: ts.Node): boolean {
  if (ts.isFunctionLike(node) || ts.isClassLike(node)) return true

  return ts.forEachChild(node, (child) => (holdsFunction(child) ? true : undefined)) ?? false
}

/** Which keyword declares a variable: `let`, `const`, `using` (or `await using`) or `var`. */
function keywordOf(declaration: ts.VariableDeclaration): 'let' | 'const' | 'using' | 'var' {
  const flags: ts.NodeFlags = ts.getCombinedNodeFlags(declaration) & ts.NodeFlags.BlockScoped

  if (flags === ts.NodeFlags.Let) return 'let'
  if (flags === ts.NodeFlags.Const) return 'const'

  return flags === ts.NodeFlags.None ? 'var' : 'using'
}

/** A declaration, in the words a failure message uses. */
function describeDeclaration(declaration: ts.Declaration): string {
  if (ts.isParameter(declaration)) return 'a parameter'

  if (ts.isBindingElement(declaration)) {
    let pattern: ts.Node = declaration.parent
    while (ts.isBindingElement(pattern.parent) || ts.isObjectBindingPattern(pattern.parent) || ts.isArrayBindingPattern(pattern.parent)) {
      pattern = pattern.parent
    }

    return ts.isParameter(pattern.parent) ? 'a destructured parameter' : 'a destructured name'
  }

  if (ts.isImportClause(declaration) || ts.isImportSpecifier(declaration) || ts.isNamespaceImport(declaration) || ts.isImportEqualsDeclaration(declaration)) {
    return 'an import'
  }

  if (ts.isVariableDeclaration(declaration)) {
    if (ts.isCatchClause(declaration.parent)) return 'a `catch` binding'

    const statement = declaration.parent.parent
    if (ts.isForStatement(statement) || ts.isForInStatement(statement) || ts.isForOfStatement(statement)) return 'a `for` binding'

    const keyword = keywordOf(declaration)
    if (keyword !== 'const') return `a \`${keyword}\``
    if (declaration.initializer === undefined) return 'a `const` with no initialiser'
    if (isHookCall(declaration.initializer)) return 'a hook call'

    return 'a `const` that holds a function'
  }

  if (ts.isFunctionDeclaration(declaration) || ts.isFunctionExpression(declaration)) return 'a function'
  if (ts.isClassDeclaration(declaration) || ts.isClassExpression(declaration)) return 'a class'

  return `a ${ts.SyntaxKind[declaration.kind]}`
}

type Binding =
  | { kind: 'substitute'; init: ts.Expression }
  | { kind: 'leaf'; leaf: Leaf }
  | { kind: 'refused'; reason: string }

const GLOBAL: Leaf = { query: false, what: 'bound nowhere in this file' }

function bindingOf(symbol: ts.Symbol | undefined, name: string): Binding {
  // A type that shares the name reads nothing at run time.
  const declarations = (symbol?.declarations ?? []).filter(
    (declaration) =>
      !ts.isInterfaceDeclaration(declaration) && !ts.isTypeAliasDeclaration(declaration) && !ts.isTypeParameterDeclaration(declaration),
  )

  if (declarations.length > 1) {
    return {
      kind: 'refused',
      reason: `\`${name}\` is declared ${String(declarations.length)} times, so what it holds cannot be told from any one of them`,
    }
  }

  const [declaration] = declarations

  if (declaration === undefined) return { kind: 'leaf', leaf: GLOBAL }

  if (
    ts.isVariableDeclaration(declaration) &&
    ts.isIdentifier(declaration.name) &&
    ts.isVariableDeclarationList(declaration.parent) &&
    keywordOf(declaration) === 'const' &&
    declaration.initializer !== undefined
  ) {
    const init = declaration.initializer

    if (isHookCall(init)) return { kind: 'leaf', leaf: { query: true, what: 'a hook call' } }
    if (!holdsFunction(init)) return { kind: 'substitute', init }
  }

  return { kind: 'leaf', leaf: { query: false, what: describeDeclaration(declaration) } }
}

/**
 * Whether `identifier` reads a binding. A property's name, a declaration's own
 * name, a label, a JSX tag or attribute name, and an import or export
 * specifier do not.
 */
function isReference(identifier: ts.Identifier): boolean {
  const parent = identifier.parent

  if ('name' in parent && parent.name === identifier && !ts.isShorthandPropertyAssignment(parent)) return false
  if (ts.isBindingElement(parent) && parent.propertyName === identifier) return false
  if (ts.isJsxOpeningElement(parent) || ts.isJsxSelfClosingElement(parent) || ts.isJsxClosingElement(parent)) return false
  if (ts.isLabeledStatement(parent) || ts.isBreakOrContinueStatement(parent)) return false
  if (ts.isImportSpecifier(parent) || ts.isExportSpecifier(parent)) return false

  return true
}

// ---------------------------------------------------------------------------
// Resolving a gate
// ---------------------------------------------------------------------------

const PRINTER = ts.createPrinter({ removeComments: true, newLine: ts.NewLineKind.LineFeed })

/** How many `const`s deep a substitution may go before it is said not to settle. */
const DEPTH = 32

const EVALUABLE_GLOBALS = new Set(['undefined', 'NaN', 'Infinity', 'Number', 'String', 'Boolean', 'Math', 'Array', 'Object'])

/** A `const` a gate's resolution substituted, and its initialiser as substituted. */
interface Constant {
  name: string
  value: string
}

interface Resolution {
  resolved: string
  names: Map<string, Leaf>
  constants: Constant[]
  refusal: string | null
}

function resolve(expression: ts.Expression, compiled: Compiled): Resolution {
  const { checker, file } = compiled

  /*
   * Built in two passes. The first substitutes, and leaves every name that
   * stays a name as a placeholder that remembers its binding; the second
   * gives each binding one name, a suffixed one when another binding in the
   * same text already has it. A binding's own name is only its label: two
   * bindings of one name are different values.
   */
  const placeholders = new Map<ts.Node, { key: ts.Symbol | string; text: string; leaf: Leaf }>()
  // Each substitution made, by the name it stands for.
  const substitutions = new Map<ts.Node, string>()
  let refusal: string | null = null

  const placeholder = (text: string, key: ts.Symbol | string, leaf: Leaf): ts.Identifier => {
    const node = ts.factory.createIdentifier(text)
    placeholders.set(node, { key, text, leaf })
    return node
  }

  const substitute = (node: ts.Expression, chain: readonly ts.Symbol[]): ts.Expression => {
    const valueOf = (reference: ts.Identifier, symbol: ts.Symbol | undefined): ts.Expression => {
      const binding = bindingOf(symbol, reference.text)

      if (binding.kind === 'refused') {
        refusal ??= binding.reason
        return placeholder(reference.text, symbol ?? reference.text, { query: false, what: 'refused' })
      }

      if (binding.kind === 'leaf' || symbol === undefined) {
        const leaf = binding.kind === 'leaf' ? binding.leaf : GLOBAL
        return placeholder(reference.text, leaf === GLOBAL || symbol === undefined ? reference.text : symbol, leaf)
      }

      if (chain.includes(symbol) || chain.length >= DEPTH) {
        refusal ??= `its constants do not settle: \`${reference.text}\` is reached again, or more than ${String(DEPTH)} deep`
        return placeholder(reference.text, symbol, { query: false, what: 'a constant that does not settle' })
      }

      // Placed where the reference was written, so the printer lays the
      // substitution out as the reference was laid out rather than breaking
      // the line wherever the initialiser happened to sit in the file.
      const substitution = ts.setTextRange(ts.factory.createParenthesizedExpression(substitute(binding.init, [...chain, symbol])), reference)
      substitutions.set(substitution, reference.text)

      return substitution
    }

    const visit = (child: ts.Node): ts.Node => {
      // A type is not evaluated, so nothing in it is resolved.
      if (ts.isTypeNode(child)) return child

      if (ts.isShorthandPropertyAssignment(child)) {
        return ts.factory.createPropertyAssignment(
          ts.factory.createIdentifier(child.name.text),
          valueOf(child.name, checker.getShorthandAssignmentValueSymbol(child)),
        )
      }

      if (ts.isIdentifier(child)) return isReference(child) ? valueOf(child, checker.getSymbolAtLocation(child)) : child

      return ts.visitEachChild(child, visit, undefined)
    }

    return visit(node) as ts.Expression
  }

  const substituted = substitute(expression, [])

  // A global keeps its own name, the only one it can be evaluated under; a
  // binding takes its own unless a global or an earlier binding has it, or it
  // is one of the evaluable globals it would otherwise pass for.
  const entries = [...placeholders.values()]
  const nameOf = new Map<ts.Symbol | string, string>()
  const taken = new Set<string>()

  for (const entry of entries) {
    if (typeof entry.key === 'string') {
      nameOf.set(entry.key, entry.text)
      taken.add(entry.text)
    }
  }

  for (const entry of entries) {
    if (nameOf.has(entry.key)) continue

    let name = entry.text
    for (let suffix = 2; taken.has(name) || EVALUABLE_GLOBALS.has(name); suffix += 1) name = `${entry.text}$${String(suffix)}`

    nameOf.set(entry.key, name)
    taken.add(name)
  }

  const names = new Map<string, Leaf>()
  const constants: Constant[] = []
  const rename = (node: ts.Node): ts.Node => {
    const entry = placeholders.get(node)

    if (entry !== undefined) {
      const name = nameOf.get(entry.key) ?? entry.text
      names.set(name, entry.leaf)
      return ts.factory.createIdentifier(name)
    }

    const renamed = ts.visitEachChild(node, rename, undefined)
    const constant = substitutions.get(node)
    if (constant !== undefined) constants.push({ name: constant, value: PRINTER.printNode(ts.EmitHint.Expression, renamed, file) })

    return renamed
  }

  const resolved = PRINTER.printNode(ts.EmitHint.Expression, rename(substituted), file)

  return { resolved, names, constants, refusal }
}

/** The top-level statement a node is written in, named for messages. */
function regionOf(node: ts.Node): string {
  let statement = node
  while (!ts.isSourceFile(statement.parent)) statement = statement.parent

  if (ts.isFunctionDeclaration(statement) || ts.isClassDeclaration(statement)) return statement.name?.text ?? '(anonymous)'

  if (ts.isVariableStatement(statement)) {
    const [first] = statement.declarationList.declarations
    return first !== undefined && ts.isIdentifier(first.name) ? first.name.text : '(anonymous)'
  }

  return ts.isExportAssignment(statement) ? '(anonymous)' : '(module)'
}

type JsxTag = ts.JsxOpeningElement | ts.JsxSelfClosingElement

/** An element's tag, as written: `Button`, `Dialog.Close`. */
function tagOf(element: JsxTag): string {
  return element.tagName.getText()
}

/**
 * The keys of every `t('…')` inside `element` — in its attributes or among its
 * children, at any depth, and through any `const` they read that step 2 would
 * substitute — except inside an element of the same tag nested in it, which
 * is the one that renders them. So a `Button` is labelled by what it renders
 * and no other `Button` is; which element around a label is the control is
 * what `FINDING_GATES` says by tag.
 */
function labelsOf(element: JsxTag, checker: ts.TypeChecker): string[] {
  const tag = tagOf(element)
  const labels: string[] = []
  const followed = new Set<ts.Symbol>()

  const walk = (node: ts.Node): void => {
    if (ts.isJsxElement(node) && tagOf(node.openingElement) === tag) return
    if (ts.isJsxSelfClosingElement(node) && tagOf(node) === tag) return

    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && node.expression.text === 't') {
      const [key] = node.arguments
      if (key !== undefined && ts.isStringLiteralLike(key)) labels.push(key.text)
    }

    if (ts.isIdentifier(node) && isReference(node)) {
      const symbol = checker.getSymbolAtLocation(node)
      const binding = bindingOf(symbol, node.text)

      if (symbol !== undefined && binding.kind === 'substitute' && !followed.has(symbol)) {
        followed.add(symbol)
        walk(binding.init)
      }
    }

    ts.forEachChild(node, walk)
  }

  ts.forEachChild(element, walk)
  if (ts.isJsxOpeningElement(element)) for (const child of element.parent.children) walk(child)

  return labels
}

/** Whether a spread follows `attribute` on its element: a later prop wins, so it can override the gate. */
function overridable(attribute: ts.JsxAttribute): boolean {
  const attributes = attribute.parent.properties

  return attributes.slice(attributes.indexOf(attribute) + 1).some((later) => ts.isJsxSpreadAttribute(later))
}

/** A file's gates, and every element in it, gated or not. */
interface Reading {
  gates: Gate[]
  elements: Element[]
}

const READINGS = new Map<string, Reading>()

function readingOf(source: SourceFile): Reading {
  const cacheKey = `${source.file}\n${source.text}`
  const cached = READINGS.get(cacheKey)
  if (cached !== undefined) return cached

  const compiled = compile(source)
  const reading: Reading = { gates: [], elements: [] }
  const lineOf = (node: ts.Node): number => compiled.file.getLineAndCharacterOfPosition(node.getStart(compiled.file)).line + 1

  const visit = (node: ts.Node): void => {
    if (ts.isJsxOpeningElement(node) || ts.isJsxSelfClosingElement(node)) {
      const at = node.getStart(compiled.file)
      const tag = tagOf(node)
      const labels = labelsOf(node, compiled.checker)

      reading.elements.push({ file: source.file, at, line: lineOf(node), tag, labels })

      for (const attribute of node.attributes.properties) {
        if (
          ts.isJsxAttribute(attribute) &&
          ts.isIdentifier(attribute.name) &&
          (attribute.name.text === 'disabled' || attribute.name.text === 'ready') &&
          attribute.initializer !== undefined &&
          ts.isJsxExpression(attribute.initializer) &&
          attribute.initializer.expression !== undefined
        ) {
          const expression = attribute.initializer.expression

          reading.gates.push({
            file: source.file,
            line: lineOf(attribute),
            prop: attribute.name.text === 'ready' ? 'ready' : 'disabled',
            expression: expression.getText(compiled.file).replace(/\s+/g, ' ').trim(),
            region: regionOf(attribute),
            element: at,
            tag,
            labels,
            overridable: overridable(attribute),
            ...resolve(expression, compiled),
          })
        }
      }
    }

    ts.forEachChild(node, visit)
  }

  visit(compiled.file)
  READINGS.set(cacheKey, reading)

  return reading
}

// ---------------------------------------------------------------------------
// Judging a gate
// ---------------------------------------------------------------------------

const REACHES_DATA = /\.data\b/

/** Every `X` written `X.data` or `X?.data`. */
function dataRoots(text: string): Set<string> {
  return new Set([...text.matchAll(/(?<![\w$.])([A-Za-z_$][\w$]*)\s*(?:\?\.|\.)data\b/g)].map((match) => match[1] ?? ''))
}

/** The queries a gate waits on: the names it reads `.data` of that are bound to a hook call. */
function queriesOf(gate: Gate): Set<string> {
  return new Set([...dataRoots(gate.resolved)].filter((name) => gate.names.get(name)?.query === true))
}

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

/** A name the gate read and could not be answered, with what it is bound to. */
function describeUnknown(gate: Gate, name: string): string {
  const leaf = gate.names.get(name)

  if (leaf === undefined) return name
  if (leaf.query) return `${name} (a hook call, read here for something other than its \`.data\`)`

  return `${name} (${leaf.what})`
}

function unanswerable(name: string): unknown {
  return new Proxy(() => undefined, {
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
}

/**
 * `text`, one of a gate's resolved expressions, evaluated with the gate's
 * queries unresolved in `state`. It throws `Unknowable` on reading a name that
 * cannot be answered, and lists in `unknown` the fields of a stub it read that
 * an unresolved query does not have.
 */
function evaluateIn(gate: Gate, state: QueryState, text: string): { value: unknown; unknown: string[] } {
  const queries = queriesOf(gate)
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

  const scope = new Proxy(
    {},
    {
      has: () => true,
      get(_target, key) {
        if (key === Symbol.unscopables) return undefined

        const name = String(key)

        if (queries.has(name)) return stubFor(name)
        // A binding in the file never has one of these names (`resolve`
        // renames it), so a name here is the global itself.
        if (EVALUABLE_GLOBALS.has(name)) return (globalThis as Record<string, unknown>)[name]

        unknown.push(describeUnknown(gate, name))
        return unanswerable(describeUnknown(gate, name))
      },
    },
  )

  // Evaluated, not parsed: this is the whole design, and `with` is the only
  // way to route every free name through the stub scope above. `this` is not
  // a name `with` can route, so it is bound to an unknown as well.
  // eslint-disable-next-line @typescript-eslint/no-implied-eval -- evaluating the gate is the point
  const evaluate = new Function('scope', `with (scope) { return (${text}\n) }`) as (this: unknown, scope: object) => unknown

  return { value: evaluate.call(unanswerable('this'), scope), unknown }
}

/**
 * The first `const` the gate substituted whose value, evaluated as the gate
 * was, is an object or a function: what it holds when the control renders
 * can have been written to since its declaration, and the text does not say.
 */
function mutableConstant(gate: Gate, state: QueryState): string | null {
  for (const constant of gate.constants) {
    let value: unknown

    try {
      ;({ value } = evaluateIn(gate, state, constant.value))
    } catch {
      continue
    }

    if ((typeof value === 'object' && value !== null) || typeof value === 'function') return constant.name
  }

  return null
}

function judgeIn(gate: Gate, state: QueryState): Verdict {
  try {
    const { value, unknown } = evaluateIn(gate, state, gate.resolved)

    if (unknown.length > 0) {
      return { kind: 'unjudgeable', state, reason: `reads ${[...new Set(unknown)].join(', ')}, which an unresolved query cannot answer` }
    }

    if (typeof value !== 'boolean') {
      return { kind: 'unjudgeable', state, reason: `comes out as ${describeValue(value)}, not a boolean` }
    }

    const live = gate.prop === 'disabled' ? !value : value

    if (live) return { kind: 'live', state, value }

    const mutable = mutableConstant(gate, state)

    if (mutable !== null) {
      return {
        kind: 'unjudgeable',
        state,
        reason:
          `reads \`${mutable}\`, a \`const\` that holds an object here, and is shut on what its initialiser built; ` +
          'an object can have been written to between its declaration and the control, which the text does not show',
      }
    }

    return { kind: 'shut' }
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
  elements: Element[]
  gates: Gate[]
  reaching: Gate[]
  live: string[]
  unjudgeable: Map<string, string>
  shut: Gate[]
}

function report(sources: SourceFile[]): Report {
  const readings = sources.map(readingOf)
  const elements = readings.flatMap((reading) => reading.elements)
  const gates = readings.flatMap((reading) => reading.gates)
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

  return { elements, gates, reaching, live, unjudgeable, shut }
}

function probe(text: string, file = 'probe/Probe.tsx'): Report {
  return report([{ file, text }])
}

// ---------------------------------------------------------------------------
// The gate
// ---------------------------------------------------------------------------

/**
 * The finding's own two controls: in each file, every `Button` that renders
 * the control's label (`labelsOf`), gated or not. Each must carry the named
 * prop, and every gate on it must be judged shut, wait on the named query,
 * and have no spread after it that could override it. So a control's gate
 * cannot drop out in silence — rewritten into a shape this file cannot read,
 * a `const` behind a hook, a destructured `data` — while something else
 * stands in for it: another control in the file waiting on the same query,
 * an element of another tag around it or inside it, a `ready=` beside it, a
 * gated copy of it beside an ungated one. A file in which no `Button`
 * renders the label is named too. What it cannot find is a `Button` that
 * gets its label by a route `labelsOf` does not follow — a prop, a function,
 * a hook — beside one that renders it and is gated correctly.
 */
const FINDING_GATES = [
  { file: 'features/auth/RegisterPage.tsx', tag: 'Button', label: 'common.register', prop: 'disabled', query: 'options', control: 'Create account' },
  { file: 'features/controlCenter/PlansPage.tsx', tag: 'Button', label: 'admin.plans.compute', prop: 'disabled', query: 'desired', control: 'Compute plan' },
] as const

/** Every `tag` element in `file` that renders `label`. */
function controlsOf(result: Report, file: string, tag: string, label: string): Element[] {
  return result.elements.filter((element) => element.file === file && element.tag === tag && element.labels.includes(label))
}

/** The gates on `control`. */
function gatesOn(result: Report, control: Element): Gate[] {
  return result.gates.filter((gate) => gate.file === control.file && gate.element === control.at)
}

/** Those of the finding's own controls not gated by their prop, judged shut, on their query. */
function findingGatesNotJudgedShut(result: Report): string[] {
  return FINDING_GATES.filter(({ file, tag, label, prop, query }) => {
    const controls = controlsOf(result, file, tag, label)

    return (
      controls.length === 0 ||
      controls.some((control) => {
        const gates = gatesOn(result, control)

        return (
          !gates.some((gate) => gate.prop === prop) ||
          gates.some((gate) => gate.overridable || !result.shut.includes(gate) || !queriesOf(gate).has(query))
        )
      })
    )
  }).map(({ file, control }) => `${file} (${control})`)
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
      'One of F-21’s own controls is no longer judged shut. If its gate was rewritten into a shape this file cannot read, ' +
        'rewrite it into one it can: this file is what keeps it fixed.',
    ).toEqual([])
  })

  it('finds each of F-21’s own controls by the label it renders, once, with one gate on it', () => {
    for (const { file, tag, label } of FINDING_GATES) {
      const controls = controlsOf(tree(), file, tag, label)

      expect(controls, `${file} renders t('${label}') in one <${tag}>`).toHaveLength(1)
      expect(controls.flatMap((control) => gatesOn(tree(), control)), `${file}'s <${tag}> for t('${label}') has one gate`).toHaveLength(1)
    }
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
  const PERMITTED_LINE = '  const registrationPermitted = permitted === true\n'
  const CLOSED_LINE = '  const registrationClosed = permitted === false\n'
  const DESIRED_LINE = '  const desired = useDesiredState(server.id)\n'
  const TERMS_HINT = '        hint={legalDocuments(options.data?.legal, t)}\n'
  const CURRENCY_FIXED = "disabled={country === ''}"
  // A correct gate on another control, waiting on the same query as Create
  // account: the one F-21's own gate must not be confused with.
  const CURRENCY_WAITING = "disabled={options.data === undefined || country === ''}"

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

  it('judges F-21’s own defect written across lines live, beside a sibling that waits correctly', () => {
    // "Not knowing is permission", in the codebase's own multi-line style,
    // with the currency select waiting on the same query as it should.
    const result = report(
      withLines({
        [REGISTER]: [
          [CURRENCY_FIXED, CURRENCY_WAITING],
          [PERMITTED_LINE, '  const registrationPermitted = options.data !== undefined\n    ? permitted === true\n    : true\n'],
        ],
      }),
    )

    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain(`${REGISTER}:`)
    // The sibling is judged, shut, and is no alarm.
    expect(result.shut.filter((gate) => gate.file === REGISTER).map((gate) => gate.expression)).toEqual([
      "options.data === undefined || country === ''",
    ])
  })

  it('judges the Compute-plan twin written across lines live too', () => {
    const result = report(
      withLines({
        [PLANS]: [
          [DESIRED_LINE, `${DESIRED_LINE}  const computeClosed = desired.data\n    ?.data === null\n`],
          [PLANS_FIXED, 'disabled={computeClosed}'],
        ],
      }),
    )

    expect(findingGatesNotJudgedShut(result)).toEqual([`${PLANS} (Compute plan)`])
    expect(result.live.some((line) => line.startsWith(`${PLANS}:`))).toBe(true)
  })

  /*
   * The finding's own gate, left live or hidden, while another control in the
   * same file waits on the same query and is judged shut. Identified by its
   * file and its query, F-21's gate would be taken to be that other one.
   */
  it('names Create account when its own gate is live and another control waits on its query', () => {
    const result = report(
      withLines({
        [REGISTER]: [
          [REGISTER_FIXED, 'disabled={!canSubmit}'],
          [TERMS_HINT, `${TERMS_HINT}        disabled={!registrationPermitted}\n`],
          [CLOSED_LINE, `${CLOSED_LINE}  const canSubmit = options.data !== undefined\n    ? registrationPermitted\n    : true\n`],
        ],
      }),
    )

    expect(result.shut.filter((gate) => gate.file === REGISTER).map((gate) => gate.expression)).toEqual(['!registrationPermitted'])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it('names Create account when its own gate goes where this file cannot see, and another control waits on its query', () => {
    const result = report(
      withLines({
        [REGISTER]: [
          [REGISTER_FIXED, 'disabled={!canSubmit}'],
          [TERMS_HINT, `${TERMS_HINT}        disabled={!registrationPermitted}\n`],
          [CLOSED_LINE, `${CLOSED_LINE}  const canSubmit = useMemo(() => options.data === undefined || registrationPermitted, [options.data, registrationPermitted])\n`],
        ],
      }),
    )

    // Nothing is live: only the finding's own anchor can see this.
    expect(result.live).toEqual([])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it('names Compute plan the same way', () => {
    const result = report(
      withLines({
        [PLANS]: [
          [PLANS_FIXED, 'disabled={computeClosed}'],
          ['disabled={!canRun}', 'disabled={!canRun || desired.data === undefined}'],
          [DESIRED_LINE, `${DESIRED_LINE}  const computeClosed = useMemo(() => desired.data?.data === null, [desired.data])\n`],
        ],
      }),
    )

    expect(result.live).toEqual([])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${PLANS} (Compute plan)`])
  })

  it('names Create account when its gate is shut on some other query, not on its own', () => {
    // Shut while every query is unresolved, and so judged shut; but it waits
    // on the session, and is live once that arrives with the options still
    // on their way.
    const result = report(
      withLines({
        [REGISTER]: [
          [REGISTER_FIXED, 'disabled={session.data === undefined}'],
          [CLOSED_LINE, `${CLOSED_LINE}  const session = useSession()\n`],
        ],
      }),
    )

    expect(result.shut.filter((gate) => gate.file === REGISTER).map((gate) => gate.expression)).toEqual(['session.data === undefined'])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it.each([
    ['inside it, around its label', [["        {t('common.register')}\n      </Button>", `        <Tooltip ${REGISTER_FIXED}>{t('common.register')}</Tooltip>\n      </Button>`]]],
    [
      'around it',
      [
        ['      <Button\n        type="submit"', `      <Tooltip ${REGISTER_FIXED}>\n      <Button\n        type="submit"`],
        ["        {t('common.register')}\n      </Button>", "        {t('common.register')}\n      </Button>\n      </Tooltip>"],
      ],
    ],
  ] as [string, [string, string][]][])('names Create account when an element %s carries the gate and it carries none', (_where, pairs) => {
    const result = report(withLines({ [REGISTER]: [[`        ${REGISTER_FIXED}\n`, ''], ...pairs] }))

    expect(result.shut.filter((gate) => gate.file === REGISTER).map((gate) => gate.expression)).toEqual(['!registrationPermitted'])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it.each([
    ['a spread after its gate, which can override it', `${REGISTER_FIXED}\n        {...{ disabled: false }}`],
    ['a ready= in place of its disabled=', 'ready={registrationPermitted}'],
  ])('names Create account when it carries %s', (_shape, replacement) => {
    const result = report(withLines({ [REGISTER]: [REGISTER_FIXED, replacement] }))

    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it('names Create account when its gate reads an object written to after its declaration', () => {
    const result = report(
      withLines({
        [REGISTER]: [
          [REGISTER_FIXED, 'disabled={!state.permitted}'],
          [CLOSED_LINE, `${CLOSED_LINE}  const state = { permitted: registrationPermitted }\n  state.permitted = true\n`],
        ],
      }),
    )

    expect(result.shut.filter((gate) => gate.file === REGISTER)).toEqual([])
    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it('names Create account when it gets its label through a constant and a gated copy of it does not', () => {
    const result = report(
      withLines({
        [REGISTER]: [
          [`        ${REGISTER_FIXED}\n`, ''],
          ["        {t('common.register')}\n      </Button>", `        {registerLabel}\n      </Button>\n      {false && <Button ${REGISTER_FIXED}>{t('common.register')}</Button>}`],
          [CLOSED_LINE, `${CLOSED_LINE}  const registerLabel = t('common.register')\n`],
        ],
      }),
    )

    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
  })

  it('names a control whose label it can no longer find', () => {
    const result = report(withLines({ [REGISTER]: ["{t('common.register')}", '{label}'] }))

    expect(findingGatesNotJudgedShut(result)).toEqual([`${REGISTER} (Create account)`])
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

  it('resolves each arrow component’s constants in that component too', () => {
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

    expect(result.unjudgeable.get('probe/Probe.tsx::verdict || answer.data?.data === undefined')).toContain(
      'reads verdict (bound nowhere in this file)',
    )
  })

  it('lets a parameter hide a module constant of the same name, and does not take the parameter for a query', () => {
    const result = probe(`const answer = { data: { data: null } }

export function Probe({ answer }: { answer: Answer }) {
  return <Button disabled={answer.data?.data === null}>x</Button>
}`)

    // Substituting the module constant would evaluate to true and bless it;
    // and what the parameter holds is not something this file can see.
    expect(result.shut).toEqual([])
    expect(result.gates.map((gate) => gate.resolved)).toEqual(['answer.data?.data === null'])
    expect(result.unjudgeable.get('probe/Probe.tsx::answer.data?.data === null')).toContain('reads answer (a destructured parameter)')
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

  it('reads a module constant’s initialiser where it is written, not in the component', () => {
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

      // Read in the component, the imported object's `.data` would be the
      // component's own query: pending, so undefined, so "shut".
      expect(result.shut).toEqual([])
      expect([...result.unjudgeable.values()].join('\n')).toContain('reads answer (an import)')
    }
  })

  it('tells two bindings of one name apart in one resolved gate', () => {
    const result = probe(`import { answer } from './fixtures'

const shut = answer.data === undefined

export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={shut || answer.data === undefined}>x</Button>
}`)

    const [gate] = result.gates

    // The import is met first and keeps the name; the component's query is
    // the second binding of it. Read as one, the import's `.data` would be
    // the pending query's: undefined, so "shut".
    expect(gate?.resolved).toBe('(answer.data === undefined) || answer$2.data === undefined')
    expect(gate?.names.get('answer')).toEqual({ query: false, what: 'an import' })
    expect(gate?.names.get('answer$2')).toEqual({ query: true, what: 'a hook call' })
    expect(result.shut).toEqual([])
    expect([...result.unjudgeable.values()].join('\n')).toContain('reads answer (an import)')
  })

  it('takes a hook called as a member for the query too', () => {
    const result = probe(`export function Probe() {
  const answer = queries.useProbeAnswer()
  return <Button disabled={answer.data === undefined}>x</Button>
}`)

    expect(result.shut).toHaveLength(1)
    expect(result.gates[0]?.names.get('answer')).toEqual({ query: true, what: 'a hook call' })
  })

  it('reads a property’s name as a name, never as a binding to resolve or rename', () => {
    // `undefined` is a property here. Renamed as the binding its object type
    // gives it, the read would be of `.undefined$2`: not the value written.
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return <Button disabled={({ undefined: answer.data === undefined }).undefined}>x</Button>
}`)

    expect(result.gates.map((gate) => gate.resolved)).toEqual(['({ undefined: answer.data === undefined }).undefined'])
    expect(result.shut).toHaveLength(1)
  })

  it('reads an object literal’s key as a key, even where a constant has its name', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const shut = answer.data === undefined
  return <Button disabled={({ shut: false }).shut || shut}>x</Button>
}`)

    expect(result.gates.map((gate) => gate.resolved)).toEqual(['({ shut: false }).shut || (answer.data === undefined)'])
    expect(result.shut).toHaveLength(1)
  })

  it('resolves a shorthand property to the binding it reads', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === null
  return <Button disabled={({ shut }).shut}>x</Button>
}`)

    expect(result.gates.map((gate) => gate.resolved)).toEqual(['({ shut: (answer.data?.data === null) }).shut'])
    expect(result.live).toHaveLength(1)
  })

  it('does not let a binding pass for an evaluable global', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return rows.map((undefined) => <Button key="k" disabled={answer.data === undefined}>x</Button>)
}`)

    // Read as the global, the row would compare as `undefined`: shut.
    expect(result.shut).toEqual([])
    expect(result.gates.map((gate) => gate.resolved)).toEqual(['answer.data === undefined$2'])
    expect([...result.unjudgeable.values()].join('\n')).toContain('reads undefined$2 (a parameter)')
  })

  it('reads `this` as an unknown rather than as the global object', () => {
    const result = probe(`export class Probe extends Component {
  render() {
    return <Button disabled={this.data === undefined}>x</Button>
  }
}`)

    // As the global object, `this.data` would be undefined: shut.
    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::this.data === undefined')).toContain('reads this')
  })

  it('reads each block’s constant in its own block where one name is declared in two', () => {
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

    expect(result.shut.map((gate) => gate.line)).toEqual([7])
    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain('probe/Probe.tsx:11')
  })

  it('refuses a name declared twice rather than picking one', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  var shut = answer.data === undefined
  var shut = answer.data?.data === null
  return <Button disabled={shut}>x</Button>
}`)

    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::shut')).toContain('`shut` is declared 2 times')
  })

  it('refuses constants that do not settle', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const first = second || answer.data === undefined
  const second = first
  return <Button disabled={first}>x</Button>
}`)

    expect(result.shut).toEqual([])
    expect(result.unjudgeable.get('probe/Probe.tsx::first')).toContain('do not settle')
  })

  /*
   * A constant in a block, or in a callback, of the same name as the one a
   * gate outside it reads. At 2453cce, when this file still read bindings
   * from text, the first three were judged on the inner constant: shut,
   * where the control the gate belongs to is live.
   */
  it.each([
    [
      'a module constant, beside a constant in an `if` block',
      'const shut = false\n\nexport function Probe() {\n  const answer = useProbeAnswer()\n  if (answer.isError) {\n    const shut = answer.data === undefined\n    log(shut)\n  }\n  return <Button disabled={shut}>x</Button>\n}',
      '(false)',
    ],
    [
      'a module constant, beside a constant in a callback',
      'const ready = true\n\nexport function Probe() {\n  const answer = useProbeAnswer()\n  return (\n    <>\n      {(answer.data?.data ?? []).map((row) => {\n        const ready = answer.data?.data.ok === true\n        return <span key={row}>{String(ready)}</span>\n      })}\n      <Button disabled={!ready}>x</Button>\n    </>\n  )\n}',
      '!(true)',
    ],
    [
      'an import, beside a constant in an `if` block',
      "import { shut } from './flags'\n\nexport function Probe() {\n  const answer = useProbeAnswer()\n  if (answer.isError) {\n    const shut = answer.data?.data === undefined\n    log(shut)\n  }\n  return <Button disabled={shut}>x</Button>\n}",
      'shut',
    ],
    [
      'a parameter, beside a constant in an `if` block',
      'export function Probe({ ready }: { ready: boolean }) {\n  const answer = useProbeAnswer()\n  if (answer.isError) {\n    const ready = answer.data?.data.ok === true\n    log(ready)\n  }\n  return <Button disabled={!ready}>x</Button>\n}',
      '!ready',
    ],
  ])('reads %s from outside the block, not the block’s', (_shape, text, resolved) => {
    const result = probe(text)

    expect(result.shut).toEqual([])
    expect(result.gates.map((gate) => gate.resolved)).toEqual([resolved])
  })

  it('reads a block’s constant from inside the block', () => {
    const result = probe(`const shut = true

export function Probe() {
  const answer = useProbeAnswer()
  if (answer.isError) {
    const shut = answer.data?.data === null
    return <Button disabled={shut}>x</Button>
  }
  return null
}`)

    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain('answer.data?.data === null')
  })

  /*
   * Every other way a name can be bound inside a component. In each the gate
   * reads the inner binding; judged on the component's constant instead, it
   * would be shut, a verdict about a value the control never reads. The last
   * three were judged shut at 2453cce, when this file still read bindings
   * from text: a `)` in a regular expression, a `-` in a return type, and a
   * return type past the 500 characters that reader looked through each hid
   * the parameter from it.
   */
  it.each([
    ['a destructured `catch` binding', 'try { run() } catch ({ ready }) { return <Button disabled={!ready}>x</Button> }', '!ready'],
    ['a typed `catch` binding', 'try { run() } catch (ready: unknown) { return <Button disabled={!ready}>x</Button> }', '!ready'],
    ['a classic `for` binding', 'for (let ready = 0; ready < 1; ready++) out.push(<Button disabled={!ready}>x</Button>)', '!ready'],
    ['a `for await` pattern', 'for await (const [ready] of stream) out.push(<Button disabled={!ready}>x</Button>)', '!ready'],
    ['a bare arrow parameter', 'return rows.map(ready => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a callback parameter', 'return rows.map((ready) => <Button key={String(ready)} disabled={!ready}>x</Button>)', '!ready'],
    ['a callback parameter whose default holds a call', 'return rows.map((ready = Boolean(0)) => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a callback parameter with a function type', 'return rows.map((ready: () => boolean) => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a parameter after one with a function type', 'return rows.map((row: () => void, ready: boolean) => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a pattern whose default is an arrow', 'return rows.map(({ ready = () => true }) => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a parameter behind a comment with an apostrophe', "return rows.map((\n    // the row's own flag\n    ready,\n  ) => <Button key=\"k\" disabled={!ready}>x</Button>)", '!ready'],
    ['a parameter after JSX text with an apostrophe', "return <div><p>Don't</p>{rows.map((ready) => <Button key=\"k\" disabled={!ready}>x</Button>)}</div>", '!ready'],
    ['a parameter after a `//` in JSX text', 'return <div><p>a // b</p>{rows.map((ready) => <Button key="k" disabled={!ready}>x</Button>)}</div>', '!ready'],
    ['a parameter with a generic return type', 'return rows.map((ready): ReturnType<typeof draw> => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a parameter with a function return type', 'return rows.map((ready): (() => JSX.Element) => () => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a parameter of a generic function expression', 'return rows.map(function <T>(ready: T) { return <Button key="k" disabled={!ready}>x</Button> })', '!ready'],
    ['the name of a function expression', 'return rows.map(function ready() { return <Button key="k" disabled={!ready}>x</Button> })', '!ready'],
    ['the name of a class expression', 'const Cell = class ready { render() { return <Button disabled={!ready}>x</Button> } }', '!ready'],
    ['a method parameter', 'const table = { cell(ready: boolean) { return <Button disabled={!ready}>x</Button> } }', '!ready'],
    ['a parameter property', 'const Cell = class { constructor(private readonly ready: boolean) { draw(<Button disabled={!ready}>x</Button>) } }', '!ready'],
    ['a setter parameter', 'const view = { set value(ready: boolean) { draw(<Button disabled={!ready}>x</Button>) } }', '!ready'],
    ['a declaration inside a line', 'return rows.map((row) => { const ready = row.ok; return <Button key={row.id} disabled={!ready}>x</Button> })', '!(row.ok)'],
    ['a second declarator on a line', 'if (answer.isError) {\n    let seen: Map<string, number> = new Map(), ready = seen.size > 0\n    return <Button disabled={!ready}>x</Button>\n  }', '!ready'],
    ['a `using` declaration', 'if (answer.isError) {\n    using ready = acquire()\n    return <Button disabled={!ready}>x</Button>\n  }', '!ready'],
    ['a parameter whose default holds a regular expression with a `)`', "return rows.map((ready: string | boolean = /\\)/.test('x')) => <Button key=\"k\" disabled={!ready}>x</Button>)", '!ready'],
    ['a parameter with a negative number in its return type', 'return rows.map((ready): -1 | JSX.Element => <Button key="k" disabled={!ready}>x</Button>)', '!ready'],
    ['a parameter with a return type of 141 members', `return rows.map((ready): ${'A | '.repeat(140)}JSX.Element => <Button key="k" disabled={!ready}>x</Button>)`, '!ready'],
  ])('reads the binding that %s makes, not the component’s constant', (_shape, body, resolved) => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const ready = answer.data?.data.ok === true
  ${body}
}`)

    expect(result.shut).toEqual([])
    expect(result.gates.map((gate) => gate.resolved)).toEqual([resolved])
    // It reads no query, so it is not judged at all: see "What it cannot see".
    expect(result.reaching).toEqual([])
  })

  it('reads a `/*` in JSX text as text, and the declaration after it as code', () => {
    const result = probe(`import { probeState } from './fixtures'

const shut = probeState.data === undefined

export function Probe() {
  const answer = useProbeAnswer()
  const hint = <p>Globs like /* match everything</p>
  const shut = answer.data?.data === null
  return <Button disabled={shut}>{hint}</Button>
}`)

    // Taken for a comment, the component's own `shut` would vanish and the
    // module's be judged in its place: shut, where the control is live.
    expect(result.shut).toEqual([])
    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain('answer.data?.data === null')
  })

  /*
   * A constant whose statement carries on past its first line. That line
   * alone is a complete expression in every one of these, and read alone
   * would be judged as a gate it is not; the compiler reads the statement
   * whole, and every one of these is live.
   */
  it.each([
    ['an `||` on the next line', 'const open = answer.data?.data.ok === true\n    || answer.isPending\n  return <Button disabled={!open}>x</Button>', 'pending'],
    ['an `&&` on the next line', 'const shut = answer.data === undefined\n    && answer.isFetching\n  return <Button disabled={shut}>x</Button>', 'failed'],
    ['a ternary across lines', 'const permitted = answer.data?.data.ok\n  const open = answer.data !== undefined\n    ? permitted === true\n    : true\n  return <Button disabled={!open}>x</Button>', 'pending'],
    ['an operator ending the line, the next not indented', 'const shut = answer.data === undefined &&\n  answer.isFetching\n  return <Button disabled={shut}>x</Button>', 'failed'],
    ['a comment between the lines', 'const open = answer.data?.data.ok === true\n    // pending counts as open\n    || answer.isPending\n  return <Button disabled={!open}>x</Button>', 'pending'],
  ])('judges the whole of a constant written across lines: %s', (_shape, body, state) => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  ${body}
}`)

    expect(result.shut).toEqual([])
    expect(result.live).toHaveLength(1)
    expect(result.live[0]).toContain(`(${state})`)
  })

  it('reads a call opening the next line as the call it is', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const shut = check
  (answer.data)
  return <Button disabled={shut}>x</Button>
}`)

    expect(result.gates.map((gate) => gate.resolved)).toEqual(['(check(answer.data))'])
    expect(result.unjudgeable.get('probe/Probe.tsx::shut')).toContain('reads check (bound nowhere in this file)')
  })

  it('ends a statement where the language does, whatever the next line’s indentation', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === null
    log(answer)
  return <Button disabled={shut}>x</Button>
}`)

    expect(result.live).toHaveLength(1)
  })

  it('still substitutes a constant whose statement ends on its own line', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const shut = answer.data?.data === null

  return <Button disabled={shut}>x</Button>
}`)

    expect(result.live).toHaveLength(1)
  })

  it('reads only its own declarator when a line holds a second declarator or a second statement', () => {
    for (const line of ['const shut = answer.data?.data === null, other = true', 'const shut = answer.data?.data === null; const other = true']) {
      const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  ${line}
  return <Button disabled={shut}>x</Button>
}`)

      // Taken whole, the first would be `(…, other = true)`: true, and shut.
      expect(result.shut, line).toEqual([])
      expect(result.gates.map((gate) => gate.resolved), line).toEqual(['(answer.data?.data === null)'])
      expect(result.live, line).toHaveLength(1)
    }
  })

  it.each([
    ['a function', 'export default function ({ ready }: { ready: boolean }) {\n  return <Button disabled={!ready}>x</Button>\n}'],
    ['an arrow', 'export default ({ ready }: { ready: boolean }) => <Button disabled={!ready}>x</Button>'],
  ])('judges a component’s gate on its own constant beside an export default of %s that has no name', (_kind, exported) => {
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

  it('labels an element by what it renders at any depth, except what a nested element of its own tag renders', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return (
    <form>
      <p>{t('probe.heading')}</p>
      <Button disabled={answer.data === undefined}>{answer.isError ? t('probe.retry') : t('probe.go')}</Button>
      <Tooltip disabled={answer.data === undefined}>
        <Button disabled={answer.data === undefined}>
          <span>{t('probe.inner')}</span>
        </Button>
      </Tooltip>
      <Button disabled={answer.data === undefined}>
        <Button disabled={answer.data === undefined}>{t('probe.nested')}</Button>
      </Button>
      <Toggle disabled={answer.data === undefined} label={t('probe.toggle')} />
    </form>
  )
}`)

    expect(result.gates.map((gate) => [gate.tag, gate.labels])).toEqual([
      ['Button', ['probe.retry', 'probe.go']],
      ['Tooltip', ['probe.inner']],
      ['Button', ['probe.inner']],
      ['Button', []],
      ['Button', ['probe.nested']],
      ['Toggle', ['probe.toggle']],
    ])
  })

  it('follows a constant to the label it holds', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const go = t('probe.go')
  const label = answer.isError ? t('probe.retry') : go
  return <Button disabled={answer.data === undefined}>{label}</Button>
}`)

    expect(result.gates.map((gate) => gate.labels)).toEqual([['probe.retry', 'probe.go']])
  })

  it.each([
    ['an object literal written to afterwards', 'const state = { shut: answer.data === undefined }\n  state.shut = false\n  return <Button disabled={state.shut}>x</Button>', '`state`'],
    ['an array a fallback made, pushed to afterwards', 'const rows = answer.data?.data ?? []\n  rows.push(1)\n  return <Button disabled={rows.length === 0}>x</Button>', '`rows`'],
    ['an object another constant reads a field of', 'const inner = { shut: answer.data === undefined }\n  const outer = inner.shut\n  inner.shut = false\n  return <Button disabled={outer && inner.shut}>x</Button>', '`inner`'],
    ['a function a global holds, given a property afterwards', 'const pick = Math.max\n  pick.shut = false\n  return <Button disabled={pick.shut ?? answer.data === undefined}>x</Button>', '`pick`'],
  ])('does not judge shut on a constant that holds %s', (_shape, body, name) => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  ${body}
}`)

    // Judged on what the initialiser built, every one of these is shut, and
    // the control is live.
    expect(result.shut).toEqual([])
    expect([...result.unjudgeable.values()].join('\n')).toContain(`reads ${name}, a \`const\` that holds an object`)
  })

  it('still judges a constant that holds a primitive, and one an unresolved query leaves empty', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  const record = answer.data?.data
  const shut = record === undefined
  return <Button disabled={shut}>x</Button>
}`)

    expect(result.shut).toHaveLength(1)
  })

  it('knows when a spread after a gate can override it, and when one before it cannot', () => {
    const result = probe(`export function Probe() {
  const answer = useProbeAnswer()
  return (
    <>
      <Button {...rest} disabled={answer.data === undefined}>x</Button>
      <Button disabled={answer.data === undefined} {...rest}>x</Button>
    </>
  )
}`)

    expect(result.gates.map((gate) => gate.overridable)).toEqual([false, true])
  })
})
