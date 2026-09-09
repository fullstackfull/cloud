import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'
import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import {
  READINESS_LADDER,
  useAssessAllProducts,
  useDeclareSellable,
  useProductDependencies,
  useProductReadiness,
  useWithdrawSellability,
  type ProductReadiness,
  type ProductReadinessState,
} from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Whether the platform may sell each thing, and what stands in the way.
 *
 * One ladder per product, five rungs, the reached ones lit. The blocker
 * beside it is a blocker TO the next rung, not a list of everything wrong —
 * fixing it reveals the next. The last rung is the only control on the
 * screen, and it is a declaration a person makes with a reference to what
 * they checked; the platform refuses it below production, and withdraws it
 * on its own the moment the evidence goes.
 */
export function ReadinessPage() {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const readiness = useProductReadiness()
  const dependencies = useProductDependencies()
  const assess = useAssessAllProducts()
  const sweep = assess.data?.data

  return (
    <>
      <PageHeader
        title={t('admin.readiness.title')}
        description={t('admin.readiness.subtitle')}
        actions={
          <Button variant="secondary" loading={assess.isPending} onClick={() => { assess.mutate(); }}>
            {t('admin.readiness.assessAll')}
          </Button>
        }
      />

      {sweep === undefined ? null : (
        <Alert tone="info">{t('admin.readiness.assessed', { examined: sweep.examined, changed: sweep.changed })}</Alert>
      )}
      {describe(assess.error) === null ? null : <Alert tone="error">{describe(assess.error)?.message}</Alert>}

      <Alert tone="info">{t('admin.readiness.ladderNote')}</Alert>

      {readiness.isPending ? (
        <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
      ) : readiness.error ? (
        <LoadFailure error={readiness.error} />
      ) : (
        <ul className="flex flex-col gap-4" aria-label={t('admin.readiness.title')}>
          {readiness.data.data.map((product) => (
            <li key={product.product} aria-label={t(`admin.readiness.products.${product.product}`)}>
              <ProductCard product={product} />
            </li>
          ))}
        </ul>
      )}

      <Card>
        <h2 className="mb-3 text-base font-semibold">{t('admin.readiness.dependenciesHeading')}</h2>
        <p className="mb-3 text-sm text-[var(--text-muted)]">{t('admin.readiness.dependenciesNote')}</p>
        {dependencies.isPending ? (
          <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : dependencies.error ? (
          <LoadFailure error={dependencies.error} />
        ) : (
          <ul className="flex flex-col gap-2" aria-label={t('admin.readiness.dependenciesHeading')}>
            {dependencies.data.data.map((node) => (
              <li key={node.product} className="rounded-lg border border-[var(--border-subtle)] p-3 text-sm" aria-label={t(`admin.readiness.products.${node.product}`)}>
                <p className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{t(`admin.readiness.products.${node.product}`)}</span>
                  <StatusBadge status={node.state} />
                  {node.depends_on.length === 0 ? null : (
                    <span className="text-xs text-[var(--text-muted)]">
                      {t('admin.readiness.leansOn', { products: node.depends_on.map((p) => t(`admin.readiness.products.${p}`)).join(', ') })}
                    </span>
                  )}
                </p>
                <ul className="mt-2 flex flex-wrap gap-2">
                  {node.providers.map((edge) => (
                    <li key={`${node.product}-${edge.category}`} className="flex items-center gap-1 text-xs">
                      <span>{t(`admin.providers.categories.${edge.category}`)}</span>
                      <span className="text-[var(--text-muted)]">→</span>
                      <span className="technical">{edge.provider_name ?? t('admin.readiness.nobody')}</span>
                      {edge.shared ? <Badge tone="neutral">{t('admin.readiness.shared')}</Badge> : null}
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}

function Ladder({ state }: { state: ProductReadinessState }) {
  const { t } = useTranslation()
  const reached = READINESS_LADDER.indexOf(state)

  return (
    <ol className="flex flex-wrap gap-1" aria-label={t('admin.readiness.ladder')}>
      {READINESS_LADDER.map((rung, index) => (
        <li
          key={rung}
          aria-current={rung === state ? 'step' : undefined}
          className={`rounded-md border px-2 py-0.5 text-xs ${index <= reached ? 'border-blue-500/30 bg-blue-500/10 text-blue-700 dark:text-blue-300' : 'border-[var(--border-subtle)] text-[var(--text-muted)]'}`}
        >
          {t(`status.${rung}`)}
        </li>
      ))}
    </ol>
  )
}

function ProductCard({ product }: { product: ProductReadiness }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describe = useApiErrorMessage()
  const [declaring, setDeclaring] = useState(false)
  const [withdrawing, setWithdrawing] = useState(false)
  const withdraw = useWithdrawSellability()
  const canDeclare = product.state === 'ready_for_production'

  return (
    <Card>
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-base font-semibold">{t(`admin.readiness.products.${product.product}`)}</h2>
            {product.assessed_at === null ? null : (
              <p className="text-xs text-[var(--text-muted)]">{t('admin.readiness.assessedAt', { when: formatDateTime(product.assessed_at, locale) })}</p>
            )}
          </div>
          <StatusBadge status={product.state} />
        </div>

        <Ladder state={product.state} />

        {product.blocker === null ? (
          <p className="text-sm text-[var(--text-secondary)]">{product.detail}</p>
        ) : (
          <Alert tone="warning">
            <span className="font-medium">
              {t('admin.readiness.blockerTo', { rung: product.next_state === null ? '' : t(`status.${product.next_state}`) })}{' '}
              {t(`admin.controlCenter.blockers.${product.blocker}`)}
            </span>
            {product.next_action === null ? null : <span> — {t(product.next_action)}</span>}
            {product.detail === null ? null : <span className="block text-xs">{product.detail}</span>}
          </Alert>
        )}

        <details>
          <summary className="cursor-pointer text-sm font-medium">{t('admin.readiness.requirementsHeading', { count: product.requirements.length })}</summary>
          <table className="mt-2 w-full text-xs">
            <thead>
              <tr className="text-start text-[var(--text-muted)]">
                <th className="py-1 text-start">{t('admin.readiness.requirement')}</th>
                <th className="py-1 text-start">{t('admin.readiness.reaches')}</th>
                <th className="py-1 text-start">{t('admin.readiness.carriedBy')}</th>
              </tr>
            </thead>
            <tbody>
              {product.requirements.map((row) => (
                <tr key={row.category} className="border-t border-[var(--border-subtle)] align-top">
                  <td className="py-1 pe-2">
                    {t(`admin.providers.categories.${row.category}`)}
                    {row.shared ? <Badge tone="neutral">{t('admin.readiness.shared')}</Badge> : null}
                    <span className="technical block text-[var(--text-muted)]">{row.capabilities.join(', ')}</span>
                  </td>
                  <td className="py-1 pe-2"><StatusBadge status={row.satisfied_up_to} /></td>
                  <td className="py-1">
                    <span className="technical">{row.provider_name ?? t('admin.readiness.nobody')}</span>
                    <span className="block text-[var(--text-muted)]">{row.detail}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </details>

        <div className="flex flex-wrap items-center gap-2">
          {product.sellable.declared ? (
            <>
              <span className="text-sm text-[var(--text-secondary)]">
                {t('admin.readiness.declaredOn', { when: product.sellable.declared_at === null ? '' : formatDateTime(product.sellable.declared_at, locale) })}{' '}
                <span className="technical">{product.sellable.validation_reference}</span>
              </span>
              <Button size="sm" variant="ghost" onClick={() => { setWithdrawing(true); }}>{t('admin.readiness.withdraw')}</Button>
            </>
          ) : (
            <>
              <Button size="sm" disabled={!canDeclare} onClick={() => { setDeclaring(true); }}>{t('admin.readiness.declare')}</Button>
              {canDeclare ? null : <span className="text-xs text-[var(--text-muted)]">{t('admin.readiness.declareNeedsProduction')}</span>}
            </>
          )}
          {product.sellable.withdrawn_at !== null && !product.sellable.declared ? (
            <span className="text-xs text-[var(--text-muted)]">{t('admin.readiness.withdrawnBecause', { reason: product.sellable.withdrawn_reason ?? '' })}</span>
          ) : null}
        </div>
      </div>

      {declaring ? <DeclareDialog product={product} onClose={() => { setDeclaring(false); }} /> : null}

      <ConfirmDialog
        open={withdrawing}
        title={t('admin.readiness.withdrawTitle', { product: t(`admin.readiness.products.${product.product}`) })}
        body={<p>{t('admin.readiness.withdrawBody')}</p>}
        evidenceLabel={t('admin.readiness.reason')}
        confirmLabel={t('admin.readiness.withdraw')}
        loading={withdraw.isPending}
        error={describe(withdraw.error)?.message}
        onCancel={() => { setWithdrawing(false); withdraw.reset(); }}
        onConfirm={(_phrase, reason) => { withdraw.mutate({ product: product.product, reason }, { onSuccess: () => { setWithdrawing(false); } }); }}
      />
    </Card>
  )
}

function DeclareDialog({ product, onClose }: { product: ProductReadiness; onClose: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const declare = useDeclareSellable()
  const [reference, setReference] = useState('')

  return (
    <ConfirmDialog
      open
      title={t('admin.readiness.declareTitle', { product: t(`admin.readiness.products.${product.product}`) })}
      body={
        <div className="flex flex-col gap-3">
          <p>{t('admin.readiness.declareBody')}</p>
          <Field
            label={t('admin.readiness.validationReference')}
            hint={t('admin.readiness.validationReferenceHint')}
            value={reference}
            onChange={(event) => { setReference(event.target.value); }}
            dir="ltr"
          />
        </div>
      }
      evidenceLabel={t('admin.readiness.reason')}
      evidenceHint={t('admin.readiness.reasonHint')}
      confirmLabel={t('admin.readiness.declare')}
      ready={reference.trim().length >= 3}
      loading={declare.isPending}
      error={describe(declare.error)?.message}
      onCancel={onClose}
      onConfirm={(_phrase, reason) => {
        declare.mutate({ product: product.product, reason, validation_reference: reference.trim() }, { onSuccess: onClose })
      }}
    />
  )
}
