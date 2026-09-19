/**
 * Where each family of thing a customer owns lives in the portal.
 *
 * One map, because three different places need the same answer: the services
 * index turning a service into a link, the notification inbox turning the
 * API's `{kind, id}` into a destination, and the breadcrumbs naming the index
 * a resource sits under. Three copies of it would drift the first time a route
 * was renamed.
 *
 * The API deliberately publishes a kind and an id rather than a path — routes
 * belong to the portal — so this is the one place that knows both.
 */
export type ResourceKind = 'vps' | 'dedicated' | 'hosting' | 'wordpress' | 'domain' | 'dns'

export interface ResourceFamily {
  /** The index page for the family. */
  index: string
  /** The i18n key for the family's name, in the customer's vocabulary. */
  labelKey: string
  /** Builds the address of one resource. */
  detail: (identity: string) => string
}

export const RESOURCE_FAMILIES: Record<ResourceKind, ResourceFamily> = {
  vps: {
    index: '/vps',
    labelKey: 'nav.vps',
    detail: (id) => `/vps/${encodeURIComponent(id)}`,
  },
  dedicated: {
    index: '/dedicated',
    labelKey: 'nav.dedicated',
    detail: (id) => `/dedicated/${encodeURIComponent(id)}`,
  },
  hosting: {
    index: '/hosting',
    labelKey: 'nav.hosting',
    detail: (id) => `/hosting/${encodeURIComponent(id)}`,
  },
  wordpress: {
    index: '/wordpress',
    labelKey: 'nav.wordpress',
    detail: (id) => `/wordpress/${encodeURIComponent(id)}`,
  },
  domain: {
    index: '/domains',
    labelKey: 'nav.domains',
    // A domain is addressed by its name, which is what a customer recognises
    // and what the API accepts alongside the id.
    detail: (name) => `/domains/${encodeURIComponent(name)}`,
  },
  dns: {
    index: '/dns',
    labelKey: 'nav.dns',
    detail: (name) => `/dns/${encodeURIComponent(name)}`,
  },
}

/**
 * The family a service belongs to.
 *
 * `Service.kind` is the commercial vocabulary — what was bought — and the
 * portal's addresses use the shorter product word. Domains and DNS zones are
 * not services in this sense and are not reachable from here.
 */
export function familyForServiceKind(kind: string): ResourceKind | null {
  switch (kind) {
    case 'vps':
      return 'vps'
    case 'dedicated':
      return 'dedicated'
    case 'shared_hosting':
      return 'hosting'
    default:
      return null
  }
}

/**
 * The portal address for a `{kind, id}` handle published by the API.
 *
 * Returns null for a kind the portal has no page for, so a caller renders
 * whatever it would have rendered without a link rather than a dead one.
 */
export function pathForResource(kind: string, id: string): string | null {
  if (kind === 'invoice') return `/invoices/${encodeURIComponent(id)}`

  /*
   * `kind` is whatever the API sent. The record's type says every key is
   * present, which is true of the keys the portal knows and says nothing
   * about a kind added to the API later — so the lookup is checked before it
   * is used, and an unknown family renders as text with no link rather than
   * as a link to nowhere.
   */
  const family: ResourceFamily | undefined = Object.hasOwn(RESOURCE_FAMILIES, kind)
    ? RESOURCE_FAMILIES[kind as ResourceKind]
    : undefined

  return family?.detail(id) ?? null
}

/**
 * The i18n key for a product kind, in the customer's vocabulary.
 *
 * The audit's AS-20: the catalogue said "Shared hosting" where the navigation
 * said "Shared Hosting" and a resource page said something else again, because
 * each screen had its own word list. The families above are the vocabulary, so
 * the catalogue reads from them, and a product kind the portal has no family
 * for falls back to the kind itself rather than to an invented name.
 */
export function labelKeyForProductKind(kind: string): string | null {
  const family = familyForServiceKind(kind)

  return family === null ? null : RESOURCE_FAMILIES[family].labelKey
}
