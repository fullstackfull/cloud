import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import type { Locale } from '@/i18n'
import { formatDate, formatMinorUnits } from '@/lib/format'
import { useQuoteDomain, useRedeemDomain } from '@/lib/queries'
import type { Domain, DomainQuote } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * A lapsed name and the one way back.
 *
 * Every state the addendum lists is a sentence here — expired, in
 * redemption, recovery available or not (and why), the price, the quote's
 * expiry, awaiting payment, provider processing, needs review, recovered —
 * and none of them is urgent-sounding. The registry's clock is real; a
 * countdown would be theatre.
 */
export function RedemptionPanel({ domain, locale }: { domain: Domain; locale: Locale }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const quote = useQuoteDomain()
  const redeem = useRedeemDomain(domain.id)
  const [pending, setPending] = useState<DomainQuote | null>(null)
  const redemption = domain.redemption
  if (redemption === null) return null

  const attempt = redemption.attempt
  const inFlight = attempt !== null && ['requested', 'queued', 'running', 'awaiting_registry', 'indeterminate', 'needs_review'].includes(attempt.state)
  const failure = describeError(quote.error) ?? describeError(redeem.error)

  return (
    <section className="flex flex-col gap-2 rounded-lg border border-[var(--border-subtle)] p-3" aria-label={t('domains.redemption.heading')}>
      <h3 className="text-sm font-semibold">{t('domains.redemption.heading')}</h3>

      {domain.state === 'redemption' ? (
        <p className="text-sm text-[var(--text-secondary)]">
          {t('domains.redemption.lapsed', { date: domain.expires_at === null ? '' : formatDate(domain.expires_at, locale) })}
        </p>
      ) : null}

      {attempt !== null && attempt.state === 'completed' ? <Alert tone="success">{t('domains.redemption.recovered')}</Alert> : null}
      {attempt !== null && (attempt.state === 'requested' || attempt.state === 'queued') ? (
        <Alert tone="info">
          {t('domains.redemption.awaitingPayment')}{' '}
          {attempt.invoice_id === null ? null : (
            <Link to="/invoices" className="underline underline-offset-2">{t('domains.redemption.openInvoice')}</Link>
          )}
        </Alert>
      ) : null}
      {attempt !== null && (attempt.state === 'running' || attempt.state === 'awaiting_registry') ? <Alert tone="info">{t('domains.redemption.processing')}</Alert> : null}
      {attempt !== null && attempt.needs_attention ? <Alert tone="warning">{t('domains.redemption.needsReview')}</Alert> : null}
      {attempt !== null && attempt.state === 'failed' ? <Alert tone="error">{t('domains.redemption.failed')}</Alert> : null}

      {domain.is_redeemable && !inFlight ? (
        redemption.support === 'supported' && redemption.price_minor !== null && redemption.currency !== null ? (
          <>
            <p className="text-sm">{t('domains.redemption.available', { price: formatMinorUnits(redemption.price_minor, redemption.currency, locale) })}</p>
            <div>
              <Button
                loading={quote.isPending}
                onClick={() => {
                  quote.mutate({ name: domain.name, operation: 'redeem' }, { onSuccess: (result) => { setPending(result.data) } })
                }}
              >
                {t('domains.redemption.recover')}
              </Button>
            </div>
          </>
        ) : (
          <Alert tone="warning">{t(`domains.redemption.unavailable.${redemption.support === 'supported' ? 'blocked_configuration' : redemption.support}`)}</Alert>
        )
      ) : null}

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>{failure.message}</Alert>
      )}

      {pending === null ? null : (
        <ConfirmDialog
          open
          title={t('domains.redemption.quoteTitle', { domain: domain.name })}
          body={
            <div className="flex flex-col gap-2 text-sm">
              <p>{t('domains.redemption.quoteBody')}</p>
              <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
                <dt className="text-[var(--text-muted)]">{t('domains.redemption.quotePrice')}</dt>
                <dd className="technical">{formatMinorUnits(pending.price_minor, pending.currency, locale)}</dd>
              </dl>
              <p className="text-[var(--text-muted)]">{t('domains.redemption.quoteExpires', { when: formatDate(pending.expires_at, locale) })}</p>
              <p className="text-[var(--text-muted)]">{t('domains.redemption.paymentExplainer')}</p>
            </div>
          }
          confirmLabel={t('domains.redemption.confirm')}
          loading={redeem.isPending}
          error={describeError(redeem.error)?.message}
          onCancel={() => { setPending(null); redeem.reset() }}
          onConfirm={() => { redeem.mutate({ quote_id: pending.id }, { onSuccess: () => { setPending(null) } }) }}
        />
      )}
    </section>
  )
}
