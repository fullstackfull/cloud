import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useInvoice, usePayment } from '@/lib/queries'
import type {
  BillingSnapshot,
  InvoiceItem,
  InvoicePaymentRow,
  InvoiceWalletCreditRow,
} from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'
import { safeLabel } from '@/lib/safeLabel'

import { PaymentNextAction } from '../payments/PaymentNextAction'
import { usePaymentLaunch } from '../payments/usePaymentLaunch'

/**
 * One invoice, as a document.
 *
 * What a customer needs from this screen is what they bought, what it cost and
 * why, who the document is addressed to, and what happened to their money. All
 * four come from the server: the lines and the totals are the invoice's own,
 * the address is the snapshot taken when it was issued rather than the profile
 * as it stands today, and the payment history includes the attempts that
 * failed.
 *
 * The portal formats money and adds nothing up. Every figure below is a Money
 * object — minor units and a currency — and a total the browser computed could
 * only ever disagree with the invoice.
 */
export function InvoiceDetailPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { id = '' } = useParams()
  const [searchParams] = useSearchParams()
  const describeError = useApiErrorMessage()

  const { data: invoice, isPending, error } = useInvoice(id)
  const launch = usePaymentLaunch()
  const launchError = describeError(launch.error)

  /*
   * A customer coming back from the provider's page arrives with a payment id
   * in the URL. It is read to look the payment up — and for nothing else. The
   * status shown is the platform's record of that payment, never the query
   * string: a browser returning from a redirect controls its own URL, so
   * "?status=paid" is a claim and not evidence.
   */
  const returnedPaymentId = searchParams.get('payment')
  const returned = usePayment(returnedPaymentId)

  const [showReturn, setShowReturn] = useState(returnedPaymentId !== null)

  if (isPending) return <Loading className="py-12" />

  if (invoice === undefined) {
    return <LoadFailure error={error} />
  }

  const lineColumns: Array<Column<InvoiceItem>> = [
    { key: 'description', header: t('invoices.lineDescription'), cell: (item) => item.description },
    {
      key: 'period',
      header: t('invoices.linePeriod'),
      cell: (item) =>
        item.period_start === null || item.period_end === null
          ? '—'
          : `${formatDate(item.period_start, locale)} – ${formatDate(item.period_end, locale)}`,
    },
    { key: 'quantity', header: t('invoices.lineQuantity'), ltr: true, cell: (item) => item.quantity },
    { key: 'unit', header: t('invoices.lineUnit'), cell: (item) => <MoneyText value={item.unit_amount} /> },
    { key: 'tax', header: t('invoices.lineTax'), cell: (item) => <MoneyText value={item.tax} /> },
    { key: 'total', header: t('invoices.lineTotal'), cell: (item) => <MoneyText value={item.total} /> },
  ]

  const paymentColumns: Array<Column<InvoicePaymentRow>> = [
    {
      key: 'method',
      header: t('payments.method'),
      cell: (payment) =>
        payment.from_account_credit ? t('payments.fromCredit') : t('payments.fromOutside'),
    },
    { key: 'status', header: t('payments.status'), cell: (payment) => <StatusBadge status={payment.status} /> },
    { key: 'amount', header: t('payments.amount'), cell: (payment) => <MoneyText value={payment.amount} /> },
    {
      key: 'when',
      header: t('payments.when'),
      cell: (payment) =>
        payment.processed_at === null ? '—' : formatDate(payment.processed_at, locale),
    },
    {
      key: 'reason',
      header: t('payments.reason'),
      cell: (payment) =>
        payment.failure_code === null
          ? '—'
          : safeLabel('paymentFailure', payment.failure_code),
    },
  ]

  const creditColumns: Array<Column<InvoiceWalletCreditRow>> = [
    {
      key: 'kind',
      header: t('wallet.entryKind'),
      cell: (entry) => safeLabel('walletKind', entry.kind),
    },
    { key: 'amount', header: t('wallet.entryAmount'), cell: (entry) => <MoneyText value={entry.amount} /> },
    {
      key: 'when',
      header: t('wallet.entryWhen'),
      cell: (entry) => (entry.created_at === null ? '—' : formatDate(entry.created_at, locale)),
    },
  ]

  return (
    <>
      <PageHeader
        title={`${t('invoices.documentTitle')} ${invoice.number}`}
        description={t('invoices.documentSubtitle')}
        actions={
          <div className="flex flex-wrap gap-2">
            <Link
              to={`/invoices/${invoice.id}/print`}
              className="inline-flex h-9 items-center rounded-lg border border-[var(--border-strong)] px-3 text-sm text-[var(--text-primary)]"
            >
              {t('invoices.print')}
            </Link>
            {invoice.is_payable ? (
              <Button loading={launch.isPending} onClick={() => void launch.start(invoice.id)}>
                {t('invoices.pay')}
              </Button>
            ) : null}
          </div>
        }
      />

      <div className="flex flex-col gap-4">
        {launchError !== null ? (
          <Alert tone="error" requestId={launchError.requestId}>
            {launchError.message}
          </Alert>
        ) : null}

        <PaymentNextAction state={launch.state} />

        {showReturn && returnedPaymentId !== null ? (
          <Card title={t('payments.returnTitle')}>
            {returned.isPending ? (
              <Loading />
            ) : returned.data === undefined ? (
              <Alert tone="warning">{t('payments.returnUnknown')}</Alert>
            ) : (
              <div className="flex flex-col gap-2 text-sm">
                <p>
                  {t('payments.returnRecorded')} <StatusBadge status={returned.data.status} />
                </p>
                <p className="text-[var(--text-muted)]">{t('payments.returnIsNotProof')}</p>
                <div>
                  <Button variant="ghost" size="sm" onClick={() => { setShowReturn(false); }}>
                    {t('common.dismiss')}
                  </Button>
                </div>
              </div>
            )}
          </Card>
        ) : null}

        <div className="grid gap-4 lg:grid-cols-3">
          <Card title={t('invoices.addressedTo')} className="lg:col-span-2">
            <BillingSnapshotView snapshot={invoice.billing_snapshot} />
          </Card>

          <Card title={t('invoices.documentFacts')}>
            <dl className="flex flex-col gap-2 text-sm">
              <Fact label={t('invoices.status')}>
                <StatusBadge status={invoice.status} />
              </Fact>
              <Fact label={t('invoices.issuedAt')}>
                {invoice.issued_at === null ? '—' : formatDate(invoice.issued_at, locale)}
              </Fact>
              <Fact label={t('invoices.dueAt')}>
                {invoice.due_at === null ? '—' : formatDate(invoice.due_at, locale)}
              </Fact>
              {invoice.paid_at !== null ? (
                <Fact label={t('invoices.paidAt')}>{formatDate(invoice.paid_at, locale)}</Fact>
              ) : null}
              {invoice.order_id !== null ? (
                <Fact label={t('invoices.forOrder')}>
                  <Link className="underline" to={`/orders/${invoice.order_id}`}>
                    {t('invoices.viewOrder')}
                  </Link>
                </Fact>
              ) : null}
              {invoice.subscription_id != null ? (
                <Fact label={t('invoices.forSubscription')}>
                  <Link className="underline" to="/subscriptions">
                    {t('invoices.viewSubscription')}
                  </Link>
                </Fact>
              ) : null}
            </dl>
          </Card>
        </div>

        <Card title={t('invoices.whatYouBought')}>
          <DataTable
            caption={t('invoices.whatYouBought')}
            columns={lineColumns}
            rows={invoice.items ?? []}
            rowKey={(item) => item.id}
            empty={t('invoices.noLines')}
          />

          <dl className="mt-4 flex flex-col gap-2 border-t border-[var(--border-subtle)] pt-4 text-sm sm:ms-auto sm:max-w-xs">
            <Fact label={t('invoices.subtotal')}>
              <MoneyText value={invoice.subtotal} />
            </Fact>
            {invoice.discount.minor_units > 0 ? (
              <Fact label={t('invoices.discount')}>
                <MoneyText value={invoice.discount} />
              </Fact>
            ) : null}
            <Fact label={t('invoices.tax')}>
              <MoneyText value={invoice.tax} />
            </Fact>
            <Fact label={t('invoices.total')}>
              <strong>
                <MoneyText value={invoice.total} />
              </strong>
            </Fact>
            <Fact label={t('invoices.amountPaid')}>
              <MoneyText value={invoice.amount_paid} />
            </Fact>
            <Fact label={t('invoices.amountDue')}>
              <strong>
                <MoneyText value={invoice.amount_due} />
              </strong>
            </Fact>
          </dl>
        </Card>

        <Card title={t('payments.onThisInvoice')} description={t('payments.onThisInvoiceBody')}>
          <DataTable
            caption={t('payments.onThisInvoice')}
            columns={paymentColumns}
            rows={invoice.payments ?? []}
            rowKey={(payment) => payment.id}
            empty={t('payments.noneOnInvoice')}
          />
        </Card>

        {(invoice.wallet_credits ?? []).length > 0 ? (
          <Card title={t('wallet.appliedToThisInvoice')}>
            <DataTable
              caption={t('wallet.appliedToThisInvoice')}
              columns={creditColumns}
              rows={invoice.wallet_credits ?? []}
              rowKey={(entry) => entry.id}
              empty={t('wallet.noEntries')}
            />
          </Card>
        ) : null}
      </div>
    </>
  )
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <dt className="text-[var(--text-muted)]">{label}</dt>
      <dd className="text-[var(--text-primary)]">{children}</dd>
    </div>
  )
}

/**
 * The billing details the invoice was issued against.
 *
 * A field the snapshot does not carry is omitted rather than shown empty: an
 * invoice issued before the platform recorded tax ids simply has no tax id,
 * and printing a blank label would make the document look wrong. Nothing here
 * falls back to the customer's current profile — that would silently rewrite
 * an issued document.
 */
export function BillingSnapshotView({ snapshot }: { snapshot: BillingSnapshot | undefined }) {
  const { t } = useTranslation()

  if (snapshot === undefined) {
    return <p className="text-sm text-[var(--text-muted)]">{t('invoices.noSnapshot')}</p>
  }

  const address = snapshot.address ?? {}
  const lines = [address.line1, address.line2, address.city, address.state, address.postal_code]
    .filter((line): line is string => typeof line === 'string' && line !== '')

  return (
    <div className="flex flex-col gap-1 text-sm">
      {snapshot.display_name !== undefined ? (
        <p className="font-medium text-[var(--text-primary)]">{snapshot.display_name}</p>
      ) : null}

      {snapshot.legal_name != null && snapshot.legal_name !== snapshot.display_name ? (
        <p className="text-[var(--text-secondary)]">{snapshot.legal_name}</p>
      ) : null}

      {lines.map((line) => (
        <p key={line} className="text-[var(--text-secondary)]">
          {line}
        </p>
      ))}

      {address.country != null && address.country !== '' ? (
        <p className="text-[var(--text-secondary)]">{address.country}</p>
      ) : null}

      {snapshot.tax_id != null && snapshot.tax_id !== '' ? (
        <p className="text-[var(--text-secondary)]">
          {t('invoices.taxId')}: <span className="technical">{snapshot.tax_id}</span>
        </p>
      ) : null}

      {snapshot.billing_email != null && snapshot.billing_email !== '' ? (
        <p dir="ltr" className="text-[var(--text-secondary)]">
          {snapshot.billing_email}
        </p>
      ) : null}
    </div>
  )
}
