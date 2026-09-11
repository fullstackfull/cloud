import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

/**
 * W5.7. Every translation key the portal composes at runtime, classified —
 * and no way to add an unclassified one.
 *
 * ## The failure this prevents
 *
 * `t()` returns its own argument when the catalogue misses. So
 * `t(`support.priorities.${row.priority}`)` renders a sentence for every
 * priority the catalogue knows and renders the string
 * "support.priorities.emergency" for one it does not — in the middle of a
 * table, styled as a value, in whichever language the reader chose. It does
 * not throw, does not warn, and looks enough like a label that nobody
 * reports it. Wave 4 shipped exactly that on the dashboard
 * (`attention.domain.lapsed` as a heading) and W5.1 shipped the other half of
 * it (`ded-standard-1`, the raw value, when the fallback was the value).
 *
 * ## The three answers this file accepts
 *
 * A dynamic key is safe when the set of values it can carry is known to be
 * covered, and there are only three ways to know that:
 *
 *  - **CANONICAL_BOUNDED (server).** The value is a case of a backend enum and
 *    the namespace is listed in the control plane's
 *    `EveryStateAScreenShowsIsTranslatedTest`, which fails the build when an
 *    enum case has no English and Arabic sentence. W5.7 added nine
 *    namespaces to that map which had been rendering enum values outside any
 *    gate.
 *
 *  - **CANONICAL_BOUNDED (client).** The key comes from a constant in this
 *    repository — the navigation's `labelKey`, a resource page's tab list, a
 *    power action's own union. A miss is then a mistake in a file a developer
 *    edited, caught by the test beside this one that resolves every such
 *    constant in both catalogues.
 *
 *  - **VALIDATED_KEY.** The lookup is checked before it is rendered:
 *    `safeLabel` asks `i18n.exists` and returns a written sentence otherwise,
 *    and a call carrying `defaultValue: t('a.literal.key')` has a written
 *    sentence to fall back to.
 *
 * Anything else is UNSAFE_DYNAMIC, which is what this test fails on. The
 * count at W5.7's close is zero.
 */

const SOURCE = path.join(import.meta.dirname, '../..')

/**
 * Namespaces whose values are cases of a backend enum, with the reason each
 * is safe: the control plane gate that keeps the catalogue complete.
 *
 * The value is the enum, named so that this file says which boundary it is
 * trusting rather than merely that it trusts one.
 *
 * Only namespaces a `t()` template composes are listed. The ones reached
 * through `safeLabel('status', …)` — the whole `status` vocabulary,
 * `billingPeriod`, the notification categories and channels, the retry
 * advice — are safe by the mechanism rather than by this list, and listing
 * them here would be a claim nothing in this file checks.
 */
const GATED_BY_THE_CONTROL_PLANE: Record<string, string> = {
  'activity.categories': 'ActivityCategory',
  'attention.severity': 'AttentionSeverity',
  'backups.browser.kinds': 'BackupFileKind',
  'dns.import.kinds': 'ZoneChangeKind',
  'dns.import.modes': 'ZoneImportMode',
  'domains.availabilityStates': 'DomainAvailability',
  'domains.redemption.unavailable': 'RedemptionSupport',
  'notifications.categoryHint': 'NotificationCategory',
  'support.priorities': 'TicketPriority',
  'support.statuses': 'TicketStatus',
  'team.capabilities': 'CustomerCapability',
  'team.capabilityHints': 'CustomerCapability',
  'team.roles': 'CustomerRole',
  'team.roleHints': 'CustomerRole',
  'team.statuses': 'InvitationStatus',
  'wordpress.kinds': 'WordPressSiteKind',
  'wordpress.operations.kinds': 'WordPressOperationKind',
  'wordpress.push.scopes': 'WordPressPushScope',
  'wordpress.sources': 'WordPressDomainSource',
}

/**
 * Keys composed from a constant in this repository, and where the constant is.
 *
 * Keyed by the expression as written, because that is what a reader of the
 * call site sees. Each entry is a claim that the values are enumerated in
 * client code — which `every-label-key-the-client-picks.test.ts` then
 * resolves in both catalogues.
 */
const BOUNDED_IN_CLIENT_CODE: Record<string, string> = {
  'group.labelKey': 'CUSTOMER_NAV_GROUPS in app/navigation.ts',
  'item.labelKey': 'the navigation groups in app/navigation.ts',
  'tab.labelKey': 'each resource page’s own tab list',
  'RESOURCE_FAMILIES[family].labelKey': 'RESOURCE_FAMILIES in features/resources/resourcePaths.ts',
  'panelNameKey(account.panel_type)': 'a switch over three literal keys in hosting/panelName.ts',
  '`auth.accountTypes.${option}`': 'the two account types the registration form offers',
  '`console.state.${state}`': 'the console’s own ConnectionState union',
  '`dns.import.modeHint.${mode}`': 'the import modes the form offers, which are the gated enum',
  '`dns.import.counts.${key}`': 'the four count names the import summary renders',
  '`support.categories.${option}`': 'the category list the support form offers',
  '`vps.actions.${action}`': 'the power actions the component declares',
  '`dedicated.actions.${action}`': 'the power actions the component declares',
  '`security.browsers.${browser}`': 'the browser table in lib/useDeviceName.ts',
  '`security.platforms.${platform}`': 'the platform table in lib/useDeviceName.ts',
  '`wallet.direction.${entry.direction}`':
    'credit or debit, the only two values WalletTransactionResource emits',
  'options.actionKey': 'a literal key passed by the component starting the operation',
  'key': 'the key returned by a client-side mapper, which is a literal or null',
}

/**
 * Server-sent keys, and what validates them.
 *
 * These are the one case where the *key itself* comes over the wire. The
 * control plane gate reads the two files that emit them and requires a
 * sentence for each, in both languages.
 */
const VALIDATED_SERVER_KEYS: Record<string, string> = {
  'item.kind': 'attention codes, gated by EveryMessageCodeIsTranslatedTest',
  'item.message_code': 'activity codes, gated by EveryMessageCodeIsTranslatedTest',
  'watch.actionKey':
    'read back from session storage and dropped unless i18n.exists() recognises it',
}

/** Files that implement the safe path, and are therefore not held to it. */
const MECHANISMS = ['lib/safeLabel.ts', 'lib/useApiErrorMessage.ts']

interface Call {
  file: string
  line: number
  expression: string
  text: string
}

function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(?<!:)\/\/[^\n]*/g, '')
}

function customerFiles(): string[] {
  const found: string[] = []

  const walk = (directory: string): void => {
    for (const entry of readdirSync(directory)) {
      const full = path.join(directory, entry)

      if (statSync(full).isDirectory()) {
        // The operator surface answers staff in English with the engineer's
        // words, by the same decision the control plane's error catalogue
        // records; the customer portal is what this gate is about.
        if (entry === '__tests__' || entry === 'admin' || entry === 'controlCenter') continue

        walk(full)
      } else if (full.endsWith('.ts') || full.endsWith('.tsx')) {
        found.push(full)
      }
    }
  }

  walk(SOURCE)

  return found
}

/** The whole `t(...)` call, so the fallback can be seen. */
function callText(source: string, open: number): string {
  let depth = 0

  for (let index = open; index < source.length; index += 1) {
    const character = source[index]

    if (character === '(' || character === '[' || character === '{') depth += 1

    if (character === ')' || character === ']' || character === '}') {
      depth -= 1

      if (depth === 0) return source.slice(open, index + 1)
    }
  }

  return source.slice(open, open + 200)
}

function dynamicCalls(): Call[] {
  const calls: Call[] = []

  for (const file of customerFiles()) {
    const relative = path.relative(SOURCE, file)

    if (MECHANISMS.includes(relative)) continue

    const source = withoutComments(readFileSync(file, 'utf8'))

    for (const match of source.matchAll(/(?<![\w.$])t\(/g)) {
      const open = match.index + match[0].length - 1
      const text = callText(source, open)
      const argument = text.slice(1).trim()

      // A literal key is the normal case and is not this file's business.
      if (argument.startsWith("'") || argument.startsWith('"')) continue

      // `t()` with no argument is not a translation call.
      if (argument.startsWith(')')) continue

      const expression = (/^([^,]+?)\s*(?:,|\)$)/.exec(argument)?.[1] ?? argument).trim()

      calls.push({
        file: relative,
        line: source.slice(0, match.index).split('\n').length,
        expression,
        text: text.replace(/\s+/g, ' '),
      })
    }
  }

  return calls
}

/** The namespace a template literal composes a key in, if it is one. */
function namespaceOf(expression: string): string | null {
  const match = /^`([a-zA-Z][\w.]*)\.\$\{/.exec(expression)

  return match?.[1] ?? null
}

function classify(call: Call): string | null {
  const namespace = namespaceOf(call.expression)

  if (namespace !== null && namespace in GATED_BY_THE_CONTROL_PLANE) return 'CANONICAL_BOUNDED'
  if (call.expression in BOUNDED_IN_CLIENT_CODE) return 'CANONICAL_BOUNDED'
  if (call.expression in VALIDATED_SERVER_KEYS) return 'VALIDATED_KEY'

  // A written fallback: `defaultValue: t('some.literal.key')`.
  if (/defaultValue:\s*t\(\s*['"]/.test(call.text)) return 'VALIDATED_KEY'

  return null
}

describe('the keys the portal composes while it runs', () => {
  it('has no unsafe dynamic translation call on a customer screen', () => {
    const unsafe = dynamicCalls().filter((call) => classify(call) === null)

    expect(
      unsafe.map((call) => `${call.file}:${String(call.line)}  ${call.expression}`),
      'UNSAFE_DYNAMIC: a value that can miss the catalogue and render as a key path.\n' +
        'Route it through safeLabel(), give it a written defaultValue, or — if the value is a ' +
        'bounded enum — add its namespace to the control plane gate and to this file.',
    ).toEqual([])
  })

  it('classifies every one of them', () => {
    /*
     * The inventory, asserted as a whole rather than as a count: the numbers
     * in the wave report come from here, and a call that quietly stops being
     * classified is what the test above catches.
     */
    const calls = dynamicCalls()
    const counts = { CANONICAL_BOUNDED: 0, VALIDATED_KEY: 0 }

    for (const call of calls) {
      const answer = classify(call)

      if (answer === 'CANONICAL_BOUNDED') counts.CANONICAL_BOUNDED += 1
      if (answer === 'VALIDATED_KEY') counts.VALIDATED_KEY += 1
    }

    expect(counts.CANONICAL_BOUNDED + counts.VALIDATED_KEY).toBe(calls.length)
    expect(calls.length).toBeGreaterThan(40)
  })

  it('carries a reason for every namespace and expression it allows', () => {
    for (const [namespace, reason] of Object.entries(GATED_BY_THE_CONTROL_PLANE)) {
      expect(reason.length, `${namespace} has no enum named`).toBeGreaterThan(3)
    }

    for (const [expression, reason] of Object.entries(BOUNDED_IN_CLIENT_CODE)) {
      expect(reason.length, `${expression} has no reason`).toBeGreaterThan(10)
    }

    for (const [expression, reason] of Object.entries(VALIDATED_SERVER_KEYS)) {
      expect(reason.length, `${expression} has no reason`).toBeGreaterThan(10)
    }
  })

  it('has no stale entry in either allow-list', () => {
    /*
     * An allow-list nobody prunes stops being a list of decisions and becomes
     * a list of things that used to be true. Every entry must still describe
     * a call that exists.
     */
    const calls = dynamicCalls()
    const namespaces = new Set(calls.map((call) => namespaceOf(call.expression)))
    const expressions = new Set(calls.map((call) => call.expression))

    expect(
      Object.keys(GATED_BY_THE_CONTROL_PLANE).filter(
        (namespace) => ! namespaces.has(namespace) && ! expressions.has(namespace),
      ),
      'namespaces allowed here that no customer screen composes a key in',
    ).toEqual([])

    expect(
      Object.keys(BOUNDED_IN_CLIENT_CODE).filter((expression) => ! expressions.has(expression)),
      'client-bounded expressions allowed here that no customer screen uses',
    ).toEqual([])

    expect(
      Object.keys(VALIDATED_SERVER_KEYS).filter((expression) => ! expressions.has(expression)),
      'server keys allowed here that no customer screen renders',
    ).toEqual([])
  })
})
