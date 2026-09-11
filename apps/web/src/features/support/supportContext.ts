import type { TFunction } from 'i18next'

import i18n from '@/i18n'

/**
 * "Ask support about this", carried from the thing it is about.
 *
 * AS-14. Every path into support was the same empty form, so a customer whose
 * rebuild had stopped for a person to look at had to describe the machine, the
 * operation and the time from memory — and the ticket that arrived said "my
 * server is broken", which is the ticket support then has to ask three
 * questions about.
 *
 * The context travels in the URL rather than in memory, which is what makes
 * the link a link: it survives a middle-click, a copy, a bookmark and a
 * reload, and the support page can be opened directly with it.
 *
 * Two rules this module exists to hold:
 *
 *  - **Prefilled, never submitted.** What comes back is a draft in a form the
 *    customer edits and sends. A link that opened a ticket would mean a
 *    customer opening tickets by reading their own history.
 *
 *  - **The server does not trust any of it.** The ticket endpoint checks that
 *    a `service_id` belongs to the acting account and answers 404 otherwise,
 *    so a hand-edited link cannot attach a ticket to somebody else's machine.
 *    Nothing here is a security boundary; it is a convenience the server
 *    validates.
 */

export interface SupportContext {
  /**
   * A translation key for what happened — the same `message_code` the feed
   * renders, or an attention item's kind.
   *
   * A key rather than a sentence so the draft is written in the language the
   * customer is reading when they follow the link, not the one they were
   * reading when it was made.
   */
  subjectKey: string
  resource?: { kind: string; id: string; identity: string | null } | undefined
  /** Something quotable: an invoice number, a ticket reference. */
  reference?: string | undefined
  /**
   * The service the ticket should be attached to, where the caller has one.
   *
   * A resource id is not a service id — a machine's id and the id of the
   * service that pays for it are different rows — so this is only ever set by
   * a caller that actually holds the service id, and left out otherwise
   * rather than guessed at.
   */
  serviceId?: string | undefined
}

/**
 * The keys a link is allowed to name.
 *
 * `subjectKey` arrives from the URL, and `t()` on a key that does not exist
 * returns the key. Without this a hand-edited link would put an arbitrary
 * dotted string into the subject line of a support form — harmless to the
 * platform and confusing to the person reading it, which is reason enough to
 * check. Only the two namespaces that describe what happened to an account
 * are accepted, and only if the key resolves to a real string.
 */
function isDescribable(key: string): boolean {
  if (!/^(activity|attention)\.[A-Za-z0-9._]+$/.test(key)) return false

  return i18n.exists(key)
}

export function supportPathFor(context: SupportContext): string {
  const params = new URLSearchParams()

  params.set('about', context.subjectKey)

  if (context.resource !== undefined) {
    params.set('kind', context.resource.kind)
    params.set('id', context.resource.id)

    if (context.resource.identity !== null) params.set('identity', context.resource.identity)
  }

  if (context.reference !== undefined) params.set('ref', context.reference)
  if (context.serviceId !== undefined) params.set('service', context.serviceId)

  return `/support?${params.toString()}`
}

/**
 * The context a support link carried, or null if there was none.
 *
 * Returns null rather than a partial object for a link whose subject key is
 * not one of ours: a form prefilled from half a context is worse than one the
 * customer fills in themselves, because they have to notice what is wrong
 * with it first.
 */
export function readSupportContext(params: URLSearchParams): SupportContext | null {
  const about = params.get('about')

  if (about === null || !isDescribable(about)) return null

  const kind = params.get('kind')
  const id = params.get('id')
  const identity = params.get('identity')
  const reference = params.get('ref')
  const serviceId = params.get('service')

  return {
    subjectKey: about,
    ...(kind !== null && id !== null ? { resource: { kind, id, identity } } : {}),
    ...(reference === null ? {} : { reference }),
    ...(serviceId === null ? {} : { serviceId }),
  }
}

/**
 * The subject line a customer would have typed, and did not have to.
 *
 * The identity goes in it because that is the first thing support asks: "my
 * rebuild stopped" and "the rebuild of web-01 stopped" are the same sentence
 * with one round trip's difference.
 */
export function draftSubject(context: SupportContext, translate: TFunction): string {
  const what = translate(context.subjectKey)
  const identity = context.resource?.identity

  return identity === undefined || identity === null ? what : `${what} — ${identity}`
}

/**
 * The opening line of the body, with the facts support would ask for.
 *
 * Deliberately short and deliberately unfinished: the customer's own
 * description is the part that matters, and a body prefilled with three
 * paragraphs of template is a body people delete.
 */
export function draftBody(context: SupportContext, translate: TFunction): string {
  const lines = [translate('support.contextIntro', { what: translate(context.subjectKey) })]

  const identity = context.resource?.identity

  if (identity !== undefined && identity !== null) {
    lines.push(translate('support.contextResource', { identity }))
  }

  if (context.reference !== undefined) {
    lines.push(translate('support.contextReference', { reference: context.reference }))
  }

  return `${lines.join('\n')}\n\n`
}
