import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { PageHeader } from '@/components/PageHeader'
import { labelKeyForProductKind } from '@/features/resources/resourcePaths'
import { useProducts } from '@/lib/queries'
import { cn } from '@/lib/cn'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { useUrlParams } from '@/lib/urlState'
import { Alert } from '@/components/Alert'
import { Loading } from '@/components/Loading'
import { safeLabel } from '@/lib/safeLabel'

const KINDS = ['vps', 'dedicated', 'shared_hosting'] as const

export function CataloguePage() {
  const { t } = useTranslation()

  /*
   * One word per family, shared with the navigation and the resource pages.
   *
   * W5.7: a kind the portal has no family for used to be named by the API's
   * own word — `shared_hosting`, in English, on an Arabic page, in a heading.
   * Unreachable today, because `familyForServiceKind` covers every case of
   * the server's `ProductKind` and a gate holds it there, but it was one
   * enum case away from shipping. The fallback is now a written sentence,
   * which is a vague catalogue rather than a leaking one.
   */
  const kindName = (kind: string): string => {
    const key = labelKeyForProductKind(kind)

    return key === null ? safeLabel('productKind', kind) : t(key)
  }
  const describeError = useApiErrorMessage()
  /*
   * The chosen family is in the address bar, so that a refresh keeps it, a
   * link to "the VPS plans" is a link anybody can send, and pressing Back
   * after opening a plan returns to the list the customer was reading rather
   * than to the unfiltered one. `undefined` — the absence of the parameter —
   * is "everything", so the plain address is still the whole catalogue.
   */
  const params = useUrlParams()
  const kind = params.choice('kind', KINDS) ?? undefined
  const setKind = (next: (typeof KINDS)[number] | undefined): void => {
    params.set({ kind: next ?? null })
  }

  const { data, isPending, error } = useProducts(kind)
  const displayed = describeError(error)

  return (
    <>
      <PageHeader title={t('nav.buy')} description={t('catalogue.subtitle')} />

      <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label={t('catalogue.filterByKind')}>
        <FilterChip active={kind === undefined} onClick={() => { setKind(undefined); }}>
          {t('catalogue.allKinds')}
        </FilterChip>
        {KINDS.map((option) => (
          <FilterChip key={option} active={kind === option} onClick={() => { setKind(option); }}>
            {kindName(option)}
          </FilterChip>
        ))}
      </div>

      {displayed !== null ? (
        <Alert tone="error" requestId={displayed.requestId}>
          {displayed.message}
        </Alert>
      ) : isPending ? (
        <Loading className="py-12" />
      ) : (data?.data.length ?? 0) === 0 ? (
        <EmptyState>{t('catalogue.empty')}</EmptyState>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data?.data.map((product) => (
            <Card
              key={product.id}
              title={product.name}
              description={product.description ?? undefined}
              actions={<Badge tone="info">{kindName(product.kind)}</Badge>}
            >
              <p className="text-sm text-[var(--text-secondary)]">
                {t('catalogue.planCount', { count: product.plan_count })}
              </p>

              <Link
                to={`/catalogue/${encodeURIComponent(product.slug)}`}
                className="mt-4 inline-block text-sm font-medium text-brand-600 hover:underline"
              >
                {t('catalogue.viewPlans')}
              </Link>
            </Card>
          ))}
        </div>
      )}
    </>
  )
}

function FilterChip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'rounded-full border px-3 py-1 text-sm transition-colors',
        active
          ? 'border-brand-600 bg-brand-600 text-white'
          : 'border-[var(--border-subtle)] text-[var(--text-secondary)] hover:text-[var(--text-primary)]',
      )}
    >
      {children}
    </button>
  )
}
