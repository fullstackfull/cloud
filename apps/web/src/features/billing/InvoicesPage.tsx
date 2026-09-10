import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { newIdempotencyKey } from '@/lib/api'
import { formatDate } from '@/lib/format'
import { useInvoices, usePayFromWalletCredit, useWalletCreditQuote } from '@/lib/queries'
import type { Invoice } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { PaymentNextAction } from '../payments/PaymentNextAction'
import { usePaymentLaunch } from '../payments/usePaymentLaunch'

/**
 * One key per opened dialogue, not one per click.
 *
 * The server requires an Idempotency-Key and uses it to decide whether a
 * request is a repeat. A key minted per click would make an impatient
 * double-click two payments; a key that never changed would make a *second*,
 * deliberate payment on the same invoice look like a replay of the first and
 * silently do nothing. Per opened dialogue is the intent boundary: one
 * decision to pay, however many times the button is pressed.
 */
function mintKey(): string {
  return `wallet-credit-${newIdempotencyKey()}`
}

export function InvoicesPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useInvoices(page)
  /*
   * The launcher handles all five answers the server can give — a redirect, a
   * confirmation to make from here, a payment already taken, a refusal, and a
   * payment nobody has decided yet. The list used to look for a redirect URL
   * and do nothing when there was none, which made a declined card and a
   * payment in flight both look like a button that did not work.
   */
  const launch = usePaymentLaunch()

  const [payingFromCredit, setPayingFromCredit] = useState<Invoice | null>(null)
  const [idempotencyKey, setIdempotencyKey] = useState<string>(mintKey)

  const quote = useWalletCreditQuote(payingFromCredit?.id ?? null)
  const payFromCredit = usePayFromWalletCredit()

  const displayed = describeError(launch.error)
  const creditError = describeError(quote.error ?? payFromCredit.error)

  function openCreditDialog(invoice: Invoice) {
    setIdempotencyKey(mintKey())
    setPayingFromCredit(invoice)
  }


  const columns: Array<Column<Invoice>> = [
    {
      key: 'number',
      header: t('invoices.number'),
      ltr: true,
      cell: (invoice) => <span className="technical">{invoice.number}</span>,
    },
    { key: 'status', header: t('invoices.status'), cell: (invoice) => <StatusBadge status={invoice.status} /> },
    { key: 'total', header: t('invoices.total'), cell: (invoice) => <MoneyText value={invoice.total} /> },
    { key: 'due', header: t('invoices.amountDue'), cell: (invoice) => <MoneyText value={invoice.amount_due} /> },
    {
      key: 'dueAt',
      header: t('invoices.dueAt'),
      cell: (invoice) => (invoice.due_at === null ? '—' : formatDate(invoice.due_at, locale)),
    },
    {
      key: 'view',
      header: '',
      cell: (invoice) => (
        <Link className="text-sm underline" to={`/invoices/${invoice.id}`}>
          {t('invoices.view')}
        </Link>
      ),
    },
    {
      key: 'pay',
      header: '',
      cell: (invoice) =>
        invoice.is_payable ? (
          <div className="flex gap-2">
            <Button
              size="sm"
              loading={launch.isPending}
              onClick={() => void launch.start(invoice.id)}
            >
              {t('invoices.pay')}
            </Button>
            {/*
              Always offered on a payable invoice, and the dialogue says what
              the credit would actually cover. Hiding it when the balance is
              empty would need the balance on every row of the list, and a
              customer who has just been credited would find the button
              missing until the page was reloaded.
            */}
            <Button size="sm" variant="ghost" onClick={() => { openCreditDialog(invoice); }}>
              {t('invoices.useCredit')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.invoices')} description={t('invoices.subtitle')} />

      <LoadFailure error={readError} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      {launch.state.type !== 'idle' ? (
        <div className="mb-4">
          <PaymentNextAction state={launch.state} />
        </div>
      ) : null}

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.invoices')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(invoice) => invoice.id}
              empty={t('invoices.empty')}
            />
            <Paginator
              page={data?.meta.page ?? 1}
              lastPage={data?.meta.last_page ?? 1}
              total={data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>

      <ConfirmDialog
        open={payingFromCredit !== null}
        title={t('invoices.useCreditTitle')}
        body={
          <div className="flex flex-col gap-3">
            {quote.isPending ? (
              <Loading />
            ) : quote.data === undefined ? null : (
              <>
                <dl className="flex flex-col gap-2 text-sm">
                  <div className="flex justify-between gap-4">
                    <dt className="text-[var(--text-muted)]">{t('invoices.creditAvailable')}</dt>
                    <dd><MoneyText value={quote.data.data.available} /></dd>
                  </div>
                  <div className="flex justify-between gap-4">
                    <dt className="text-[var(--text-muted)]">{t('invoices.creditApplied')}</dt>
                    <dd><MoneyText value={quote.data.data.applicable} /></dd>
                  </div>
                  <div className="flex justify-between gap-4 border-t border-[var(--border-subtle)] pt-2">
                    <dt>{t('invoices.creditRemaining')}</dt>
                    <dd><MoneyText value={quote.data.data.remaining} /></dd>
                  </div>
                </dl>

                {!quote.data.data.is_payable ? (
                  <Alert tone="warning">{t('invoices.noCreditToApply')}</Alert>
                ) : quote.data.data.remaining.minor_units > 0 ? (
                  // Said before the customer presses the button, because
                  // "paid" and "partly paid" are different outcomes and only
                  // one of them stops the dunning.
                  <Alert tone="info">{t('invoices.creditWillNotCoverAll')}</Alert>
                ) : null}
              </>
            )}
          </div>
        }
        confirmLabel={t('invoices.useCredit')}
        loading={payFromCredit.isPending}
        // The same condition the handler checks before it will send anything.
        // Stated here too so the button is disabled, rather than enabled and
        // inert, until the quote has said the invoice can be paid this way.
        ready={quote.data?.data.is_payable === true}
        error={creditError?.message}
        onCancel={() => { setPayingFromCredit(null); }}
        onConfirm={() => {
          if (payingFromCredit !== null && quote.data?.data.is_payable === true) {
            payFromCredit.mutate(
              { invoiceId: payingFromCredit.id, idempotencyKey },
              { onSuccess: () => { setPayingFromCredit(null); } },
            )
          }
        }}
      />
    </>
  )
}
