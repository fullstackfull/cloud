import { Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

export interface Crumb {
  /** What a person calls this place. Never an id. */
  label: string
  /** Omitted on the last crumb, which is where the reader already is. */
  to?: string
}

/**
 * Where this page sits, in words a customer recognises.
 *
 * The trail carries human identity — Services → Cloud VPS → web-01 — because
 * a breadcrumb made of ULIDs tells the reader nothing and cannot be read back
 * to support over the phone. The final crumb is the current page and is not a
 * link; it carries `aria-current="page"` so a screen reader announces the
 * position rather than offering a link to here.
 */
export function Breadcrumbs({ crumbs }: { crumbs: readonly Crumb[] }) {
  const { t } = useTranslation()

  if (crumbs.length === 0) return null

  return (
    <nav aria-label={t('common.breadcrumbs')} className="mb-3">
      <ol className="flex flex-wrap items-center gap-1 text-sm text-[var(--text-secondary)]">
        {crumbs.map((crumb, index) => {
          const last = index === crumbs.length - 1

          return (
            <Fragment key={`${crumb.label}-${index.toString()}`}>
              {index > 0 ? (
                /*
                 * The separator is decorative and direction-agnostic: a
                 * chevron would have to be mirrored in Arabic, and a slash
                 * inside a bidirectional line moves. A middle dot does
                 * neither.
                 */
                <li aria-hidden="true" className="text-[var(--text-muted)]">
                  ·
                </li>
              ) : null}

              <li className="min-w-0">
                {last || crumb.to === undefined ? (
                  <span aria-current={last ? 'page' : undefined} className="text-[var(--text-primary)]">
                    {crumb.label}
                  </span>
                ) : (
                  <Link to={crumb.to} className="hover:text-[var(--text-primary)] hover:underline">
                    {crumb.label}
                  </Link>
                )}
              </li>
            </Fragment>
          )
        })}
      </ol>
    </nav>
  )
}
