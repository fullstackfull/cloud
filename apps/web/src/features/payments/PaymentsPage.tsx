import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { safeLabel } from '@/lib/safeLabel'
import { usePayments } from '@/lib/queries'
import type { Payment } from '@/lib/types'
import { useUrlPage } from '@/lib/urlState'

/**
 * Every payment on the account, including the ones that failed and the ones
 * that are refunds.
 *
 * The failures are the point. A customer whose card was declined last Tuesday
 * and was never told learns about it from a suspension notice; a customer who
 * can see the decline replaces the card. Refunds are shown and cannot be
 * started here — a refund is a conversation, not a button.
 */
export function PaymentsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  // W5.7: in the address bar rather than in component state, so a refresh
  // stays on this page and Back returns to it from whatever the customer
  // opened. The one mechanism is in `useUrlPage`.
  const [page, setPage] = useUrlPage()

  const { data, isPending, error } = usePayments(page)

  const columns: Array<Column<Payment>> = [
    {
      key: 'when',
      header: t('payments.when'),
      cell: (payment) =>
        payment.processed_at === null
          ? t('payments.notYetProcessed')
          : formatDate(payment.processed_at, locale),
    },
    {
      key: 'kind',
      header: t('payments.kind'),
      cell: (payment) => safeLabel('paymentKind', payment.kind),
    },
    {
      key: 'method',
      header: t('payments.method'),
      /*
       * Where the money came from, which is what a customer reconciling their
       * own records is asking. The column used to print the gateway's
       * registered driver name — and its fallback printed the raw slug.
       */
      cell: (payment) =>
        payment.from_account_credit ? t('payments.fromCredit') : t('payments.fromOutside'),
    },
    { key: 'amount', header: t('payments.amount'), cell: (payment) => <MoneyText value={payment.amount} /> },
    { key: 'status', header: t('payments.status'), cell: (payment) => <StatusBadge status={payment.status} /> },
    {
      key: 'reason',
      header: t('payments.reason'),
      cell: (payment) =>
        payment.failure_code === null
          ? '—'
          : safeLabel('paymentFailure', payment.failure_code),
    },
    {
      key: 'invoice',
      header: t('payments.invoice'),
      cell: (payment) =>
        payment.invoice_id === null ? (
          '—'
        ) : (
          <Link className="underline" to={`/invoices/${payment.invoice_id}`}>
            {t('payments.viewInvoice')}
          </Link>
        ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.payments')} description={t('payments.subtitle')} />

      <LoadFailure error={error} />

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.payments')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(payment) => payment.id}
              empty={t('payments.empty')}
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
    </>
  )
}
