import i18n from '@/i18n'

/**
 * A sentence for a value the server chose, or a bounded sentence saying we do
 * not have one — never the value itself, and never the key path.
 *
 * ## The problem this exists for
 *
 * The portal renders about thirty server-chosen values through `t()` with a
 * dynamic key. Every one of them had a fallback, and the fallback was the raw
 * value:
 *
 * ```ts
 * t(`dedicated.profile.${server.hardware_profile}`, { defaultValue: server.hardware_profile })
 * ```
 *
 * That line shipped. The namespace had no keys in either language, so what a
 * customer read on their dedicated server's page was `ded-standard-1` — an
 * inventory join key, in English, on an Arabic page, styled as a labelled
 * fact.
 *
 * Deleting the fallback is worse: `t()` returns the key when it misses, so the
 * page would have read `dedicated.profile.ded-standard-1` instead. Both
 * failures look enough like a label that nobody reports them.
 *
 * ## What this does instead
 *
 * Look the value up. If there is a sentence, use it. If there is not, use the
 * namespace's own "we do not have a word for this" sentence — which is
 * written, translated, and says something true. The raw value is never
 * rendered, and neither is the key.
 *
 * A missing sentence is therefore a slightly vague page rather than a leak, and
 * the parity gates (`EveryStateAScreenShowsIsTranslatedTest` for enum-backed
 * namespaces, `EveryMessageCodeIsTranslatedTest` for message codes) are what
 * stop it being vague for long.
 */

/**
 * The fallback for each namespace, as a translation key.
 *
 * Every namespace that renders a server-chosen value is listed. The fallback
 * is deliberately specific to the namespace: "an unrecognised status" and "a
 * payment method we cannot name" are different sentences, and a single generic
 * "Unknown" would make a page read as broken rather than as incomplete.
 */
const FALLBACKS: Record<string, string> = {
  status: 'vocabulary.unknownState',
  billingPeriod: 'vocabulary.unknownPeriod',
  paymentFailure: 'paymentFailure.unknown',
  paymentKind: 'vocabulary.unknownPaymentKind',
  errors: 'errors.unknown',
  'activity.kinds': 'vocabulary.unknownEvent',
  'dns.import.kinds': 'vocabulary.unknownChange',
  'backups.browser.kinds': 'vocabulary.unknownEntry',
  'planChange.refusal': 'vocabulary.unknownRefusal',
  'planChange.warning': 'vocabulary.unknownWarning',
  'security.outcomes': 'vocabulary.unknownOutcome',
  'domains.availabilityStates': 'vocabulary.unknownState',
  'notifications.category': 'vocabulary.unknownCategory',
  'notifications.channel': 'vocabulary.unknownChannel',
  resources: 'vocabulary.unknownAttribute',
  'vps.blocked': 'vocabulary.unknownBlocker',
  'dedicated.blocked': 'vocabulary.unknownBlocker',
  'vps.serviceState': 'vocabulary.unknownState',
  'vps.reinstallState': 'vocabulary.unknownState',
  'dedicated.reinstallState': 'vocabulary.unknownState',
  walletKind: 'vocabulary.unknownEntry',
  productKind: 'vocabulary.unknownProduct',
  activity: 'vocabulary.unknownEvent',
}

/** The sentence used when a namespace has no fallback of its own. */
const LAST_RESORT = 'vocabulary.unknownValue'

/**
 * A translated sentence for `value` inside `namespace`.
 *
 * @param namespace the catalogue namespace, without a trailing dot
 * @param value the server's own value, which may be null or unrecognised
 */
export function safeLabel(namespace: string, value: string | null | undefined): string {
  if (value !== null && value !== undefined && value !== '') {
    const key = `${namespace}.${value}`

    if (i18n.exists(key)) {
      return i18n.t(key)
    }
  }

  const fallback = FALLBACKS[namespace] ?? LAST_RESORT

  // The fallback itself is a written key, so a missing one is a build failure
  // in the parity gate rather than a key path on a customer's screen.
  return i18n.exists(fallback) ? i18n.t(fallback) : i18n.t(LAST_RESORT)
}

/**
 * The fallback key a namespace uses.
 *
 * Exported for the parity gate: the sentence `safeLabel` returns is resolved
 * in whichever language is active, so a test that compared the returned string
 * against the Arabic catalogue would only ever be checking English. The key is
 * the thing both catalogues must carry.
 */
export function fallbackKey(namespace: string): string {
  return FALLBACKS[namespace] ?? LAST_RESORT
}

/**
 * Whether a namespace has a sentence for this value.
 *
 * For the few places that need to *decide* rather than render — showing a
 * hint only when there is one to show, rather than showing "we have no word
 * for this" in a slot that should simply be empty.
 */
export function hasLabel(namespace: string, value: string | null | undefined): boolean {
  return (
    value !== null &&
    value !== undefined &&
    value !== '' &&
    i18n.exists(`${namespace}.${value}`)
  )
}

/** The namespaces this module knows a fallback for. Read by the gate. */
export function labelledNamespaces(): string[] {
  return Object.keys(FALLBACKS)
}
