import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { PageHeader } from '@/components/PageHeader'
import { useProducts } from '@/lib/queries'
import { cn } from '@/lib/cn'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { Alert } from '@/components/Alert'
import { Loading } from '@/components/Loading'

const KINDS = ['vps', 'dedicated', 'shared_hosting'] as const

export function CataloguePage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const [kind, setKind] = useState<string | undefined>(undefined)

  const { data, isPending, error } = useProducts(kind)
  const displayed = describeError(error)

  return (
    <>
      <PageHeader title={t('nav.catalogue')} description={t('catalogue.subtitle')} />

      <div className="mb-4 flex flex-wrap gap-2" role="group" aria-label={t('catalogue.filterByKind')}>
        <FilterChip active={kind === undefined} onClick={() => { setKind(undefined); }}>
          {t('catalogue.allKinds')}
        </FilterChip>
        {KINDS.map((option) => (
          <FilterChip key={option} active={kind === option} onClick={() => { setKind(option); }}>
            {t(`catalogue.kinds.${option}`)}
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
              actions={<Badge tone="info">{t(`catalogue.kinds.${product.kind}`)}</Badge>}
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
