import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate, formatMinorUnits } from '@/lib/format'
import { useQuoteDomain, useRenewDomain } from '@/lib/queries'
import type { Domain, DomainQuote } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Renewing a name early, at the price the server quotes.
 *
 * The endpoint has existed since the domains module was built and no screen
 * ever called it: a customer whose name expired next week could set auto-renew
 * and wait, or open a ticket. This is the door.
 *
 * Three things about how it works are the point:
 *
 *  - **The price is the server's.** Nothing here posts an amount. The quote is
 *    taken first, its own figure is what the dialogue shows, and the renewal
 *    sends the quote's id. On a premium name the difference between that and a
 *    price the client believed is a hundredfold.
 *  - **The quote is taken when the button is pressed**, not when the page
 *    loads, so somebody who left the tab open over lunch is charged today's
 *    price rather than told their quote expired.
 *  - **Money moves before the registry does.** The renewal raises an invoice
 *    and the term is only extended once it is paid — the platform's rule, not
 *    this screen's — so the dialogue says so and the result links to the
 *    invoice rather than claiming the name is renewed.
 *
 * An outcome the platform did not hear the end of is reported as needing
 * review and is never retried from here: the money may already have moved.
 */
export function DomainRenewAction({ domain }: { domain: Domain }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const quote = useQuoteDomain()
  const renew = useRenewDomain(domain.id)

  const [pending, setPending] = useState<DomainQuote | null>(null)

  const failure = describeError(quote.error) ?? describeError(renew.error)
  const operation = renew.data?.data

  return (
    <div className="flex flex-col gap-2">
      <div>
        <Button
          size="sm"
          /*
           * On the API's own word. A name in redemption, on registrar hold or
           * mid-transfer is not renewable, and the recovery path for a lapsed
           * one is its own panel with its own price.
           */
          disabled={!domain.is_renewable}
          loading={quote.isPending}
          onClick={() => {
            quote.mutate(
              { name: domain.name, operation: 'renew' },
              { onSuccess: (result) => { setPending(result.data); } },
            )
          }}
        >
          {t('domains.renew.action')}
        </Button>
      </div>

      {operation === undefined ? null : (
        <Alert tone={operation.needs_attention ? 'warning' : 'info'}>
          {operation.needs_attention
            ? t('domains.renew.needsReview')
            : t('domains.renew.requested')}{' '}
          {operation.invoice_id === null ? null : (
            <Link to={`/invoices/${operation.invoice_id}`} className="underline underline-offset-2">
              {t('domains.renew.openInvoice')}
            </Link>
          )}
        </Alert>
      )}

      {failure === null ? null : (
        <Alert tone="error" requestId={failure.requestId}>
          {failure.message}
        </Alert>
      )}

      {pending === null ? null : (
        <ConfirmDialog
          open
          title={t('domains.renew.title', { domain: domain.name })}
          body={
            <div className="flex flex-col gap-2 text-sm">
              <p>{t('domains.renew.body')}</p>

              <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1">
                <dt className="text-[var(--text-muted)]">{t('domains.term')}</dt>
                <dd>{t('domains.termYears', { count: pending.term_years, years: pending.term_years })}</dd>

                <dt className="text-[var(--text-muted)]">{t('domains.renew.price')}</dt>
                <dd className="technical" dir="ltr">
                  {formatMinorUnits(pending.price_minor, pending.currency, locale)}
                </dd>

                <dt className="text-[var(--text-muted)]">{t('domains.expires')}</dt>
                <dd>
                  {domain.expires_at === null ? '—' : formatDate(domain.expires_at, locale)}
                </dd>
              </dl>

              <p className="text-[var(--text-muted)]">
                {t('domains.renew.quoteExpires', { when: formatDate(pending.expires_at, locale) })}
              </p>
              <p className="text-[var(--text-muted)]">{t('domains.renew.paymentExplainer')}</p>
            </div>
          }
          /*
           * No typed phrase. Renewing keeps a name the customer already has:
           * it costs money and nothing is destroyed, which is the plain
           * dialogue in the graded policy rather than the typed one.
           */
          confirmLabel={t('domains.renew.confirm')}
          loading={renew.isPending}
          {...(describeError(renew.error) === null
            ? {}
            : { error: describeError(renew.error)?.message })}
          onCancel={() => {
            setPending(null)
            renew.reset()
          }}
          onConfirm={() => {
            renew.mutate(
              { quote_id: pending.id },
              { onSuccess: () => { setPending(null); } },
            )
          }}
        />
      )}
    </div>
  )
}
