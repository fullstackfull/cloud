import type { ReactNode } from 'react'

import { Breadcrumbs, type Crumb } from '@/components/Breadcrumbs'

/**
 * The top of a resource page: what this is, whether it is healthy, and the
 * two or three things a customer does to it most often.
 *
 * Shared structure, not uniform fields. A machine has a hostname and a power
 * state; a domain has a name and an expiry; a zone has neither. So the header
 * takes an identity, an optional set of badges and an optional set of facts,
 * and each product fills in what it actually has. Forcing every product
 * through identical slots would mean inventing values for the ones that do not
 * have them, which is how "unknown" ends up on a screen.
 *
 * `identity` is rendered inside the page's only `h1`, and marked `dir="ltr"`
 * where a product asks for it: a hostname, a domain or a serial reads left to
 * right even on an Arabic page.
 */
export function ResourceHeader({
  crumbs,
  identity,
  identityIsTechnical = true,
  family,
  badges,
  facts,
  actions,
}: {
  crumbs: readonly Crumb[]
  identity: string
  /** Hostnames, domains and serials are Latin text and must not mirror. */
  identityIsTechnical?: boolean
  /** The product family, in the customer's vocabulary — "Cloud VPS", not "vps". */
  family: string
  badges?: ReactNode
  /** Short label/value pairs that belong beside the name rather than in a tab. */
  facts?: ReactNode
  actions?: ReactNode
}) {
  return (
    <header className="mb-6">
      <Breadcrumbs crumbs={crumbs} />

      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-xs font-medium tracking-wide text-[var(--text-muted)] uppercase">
            {family}
          </p>

          <h1 className="mt-1 text-xl font-semibold break-words text-[var(--text-primary)] sm:text-2xl">
            {identityIsTechnical ? (
              <span dir="ltr" className="inline-block">
                {identity}
              </span>
            ) : (
              identity
            )}
          </h1>

          {badges === undefined ? null : (
            <div className="mt-2 flex flex-wrap items-center gap-2">{badges}</div>
          )}

          {facts === undefined ? null : (
            <div className="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-[var(--text-secondary)]">
              {facts}
            </div>
          )}
        </div>

        {actions === undefined ? null : (
          <div className="flex flex-wrap items-center gap-2">{actions}</div>
        )}
      </div>
    </header>
  )
}
