import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatMoney } from '@/lib/format'
import { newIdempotencyKey } from '@/lib/api'
import { useChangePlan, usePlanOptions } from '@/lib/queries'
import type { PlanChangeQuote } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { safeLabel } from '@/lib/safeLabel'

/**
 * Changing plan.
 *
 * Every number on this screen was computed by the backend, through the same
 * proration call the confirmation makes. Nothing here multiplies, divides or
 * rounds money: the quote a customer reads and the invoice they receive are
 * one calculation, performed twice, rather than two that ought to agree.
 *
 * Plans the platform would refuse are shown with their reason rather than
 * hidden. The commonest thing a customer opens this screen to do is move to
 * something smaller, and the commonest refusal is exactly that — a smaller
 * disk, which the platform will not do because it truncates a filesystem.
 */
export function PlanChangePage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { id = '' } = useParams<{ id: string }>()
  const describeError = useApiErrorMessage()

  const { data, isPending, error } = usePlanOptions(id)
  const changePlan = useChangePlan()

  const [chosen, setChosen] = useState<PlanChangeQuote | null>(null)

  const failure = describeError(changePlan.error)

  return (
    <>
      <PageHeader title={t('planChange.title')} description={t('planChange.subtitle')} />

      <LoadFailure error={error} />

      {failure !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={failure.requestId}>
            {failure.message}
          </Alert>
        </div>
      ) : null}

      {changePlan.isSuccess ? (
        <div className="mb-4">
          {/*
            * Two sentences, not one. The money has moved and the machine has
            * not yet changed, and telling a customer only "done" is how a
            * dashboard ends up showing four vCPU on a server running two.
            */}
          <Alert tone="success">{t('planChange.applied')}</Alert>
        </div>
      ) : null}

      {isPending ? (
        <Card>
          <Loading />
        </Card>
      ) : (
        <div className="grid gap-3">
          {(data ?? []).map((quote) => (
            <Card key={quote.plan_id}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="font-medium text-[var(--text-primary)]">{quote.plan_name}</h2>

                  <p className="technical text-xs text-[var(--text-muted)]">
                    {quote.new_resources.vcpu} vCPU · {quote.new_resources.memory_mib} MiB ·{' '}
                    {quote.new_resources.disk_gib} GiB
                  </p>

                  <p className="mt-1 text-sm">
                    {t('planChange.newRecurring', { amount: formatMoney(quote.new_recurring, locale) })}
                  </p>

                  {quote.is_available && quote.amount_due_now !== null ? (
                    <p className="mt-1 text-sm text-[var(--text-muted)]">
                      {t('planChange.dueNow', { amount: formatMoney(quote.amount_due_now, locale) })}
                    </p>
                  ) : null}

                  {quote.refusals.map((reason) => (
                    <p key={reason} className="mt-1 text-sm text-[var(--danger-text)]">
                      {safeLabel('planChange.refusal', reason)}
                    </p>
                  ))}

                  {quote.is_available
                    ? quote.warnings.map((warning) => (
                        <p key={warning} className="mt-1 text-sm text-[var(--warning-text)]">
                          {safeLabel('planChange.warning', warning)}
                        </p>
                      ))
                    : null}
                </div>

                <Button
                  variant="primary"
                  disabled={! quote.is_available}
                  onClick={() => { setChosen(quote); }}
                >
                  {t('planChange.choose')}
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <ConfirmDialog
        open={chosen !== null}
        title={t('planChange.confirmTitle', { plan: chosen?.plan_name ?? '' })}
        body={
          <>
            <p>
              {t('planChange.confirmBody', {
                amount:
                  chosen?.amount_due_now == null ? '' : formatMoney(chosen.amount_due_now, locale),
              })}
            </p>

            {chosen?.changes_infrastructure === true ? (
              <p className="mt-2 text-[var(--warning-text)]">{t('planChange.confirmResize')}</p>
            ) : null}
          </>
        }
        confirmLabel={t('planChange.confirm')}
        loading={changePlan.isPending}
        onConfirm={() => {
          if (chosen === null) return

          changePlan.mutate(
            {
              subscriptionId: id,
              plan_id: chosen.plan_id,
              price_id: chosen.price_id,
              idempotencyKey: newIdempotencyKey(),
            },
            { onSuccess: () => { setChosen(null); } },
          )
        }}
        onCancel={() => { setChosen(null); }}
      />
    </>
  )
}
