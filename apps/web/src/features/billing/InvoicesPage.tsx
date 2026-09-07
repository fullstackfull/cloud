import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useInvoices, useStartPayment } from '@/lib/queries'
import type { Invoice } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function InvoicesPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const { data, isPending, error: readError } = useInvoices(page)
  const pay = useStartPayment()

  const displayed = describeError(pay.error)

  /**
   * Hands the browser to the provider.
   *
   * This is the whole of the client's part in a payment. It does not, and must
   * never, tell the server that the money arrived — that is decided by the
   * webhook, server-side, and a portal that could assert it would be a portal an
   * attacker could use to provision for free.
   */
  async function startPayment(invoice: Invoice) {
    const started = await pay.mutateAsync(invoice.id).catch(() => null)

    if (started?.redirect_url != null && started.redirect_url !== '') {
      window.location.assign(started.redirect_url)
    }
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
      key: 'pay',
      header: '',
      cell: (invoice) =>
        invoice.is_payable ? (
          <Button
            size="sm"
            loading={pay.isPending && pay.variables === invoice.id}
            onClick={() => void startPayment(invoice)}
          >
            {t('invoices.pay')}
          </Button>
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

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
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
    </>
  )
}
