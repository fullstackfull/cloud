import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'

import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { MoneyText } from '@/components/MoneyText'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useInvoice } from '@/lib/queries'

import { BillingSnapshotView } from './InvoiceDetailPage'

/**
 * The printable invoice: HTML, and nothing else.
 *
 * A PDF service was considered and rejected. It would mean a headless browser
 * in production, a font pipeline, a queue, and a new way for an invoice to be
 * unavailable — for a document the browser already renders and prints, in both
 * languages, with the customer's own paper size.
 *
 * Two rules hold here. The figures are the invoice's, straight from the API,
 * and the address is the snapshot taken when the invoice was issued: a printed
 * copy from last month and one printed today have to say the same thing.
 */
export function InvoicePrintPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { id = '' } = useParams()

  const { data: invoice, isPending, error } = useInvoice(id)

  useEffect(() => {
    // The tab is opened to be printed. The title becomes the default file name
    // when the customer chooses "save as PDF" in their own browser.
    if (invoice !== undefined) {
      document.title = `${t('invoices.documentTitle')} ${invoice.number}`
    }
  }, [invoice, t])

  if (isPending) return <Loading className="py-12" />

  if (invoice === undefined) return <LoadFailure error={error} />

  return (
    <div className="mx-auto max-w-3xl bg-white p-6 text-black print:p-0">
      <div className="mb-6 flex items-start justify-between gap-6">
        <div>
          <h1 className="text-2xl font-semibold">{t('invoices.documentTitle')}</h1>
          <p className="technical mt-1 text-sm" dir="ltr">
            {invoice.number}
          </p>
        </div>

        <div className="text-end text-sm">
          <p>
            {t('invoices.issuedAt')}:{' '}
            {invoice.issued_at === null ? '—' : formatDate(invoice.issued_at, locale)}
          </p>
          <p>
            {t('invoices.dueAt')}: {invoice.due_at === null ? '—' : formatDate(invoice.due_at, locale)}
          </p>
          <p>
            {t('invoices.status')}: {t(`status.${invoice.status}`, { defaultValue: invoice.status })}
          </p>
        </div>
      </div>

      <section className="mb-6">
        <h2 className="mb-1 text-xs font-medium uppercase tracking-wide">{t('invoices.addressedTo')}</h2>
        <BillingSnapshotView snapshot={invoice.billing_snapshot} />
      </section>

      <table className="w-full border-collapse text-sm">
        <caption className="sr-only">{t('invoices.whatYouBought')}</caption>
        <thead>
          <tr className="border-b border-black/30 text-start">
            <th scope="col" className="py-2 text-start">{t('invoices.lineDescription')}</th>
            <th scope="col" className="py-2 text-start">{t('invoices.lineQuantity')}</th>
            <th scope="col" className="py-2 text-start">{t('invoices.lineUnit')}</th>
            <th scope="col" className="py-2 text-start">{t('invoices.lineTax')}</th>
            <th scope="col" className="py-2 text-start">{t('invoices.lineTotal')}</th>
          </tr>
        </thead>
        <tbody>
          {(invoice.items ?? []).map((item) => (
            <tr key={item.id} className="border-b border-black/10">
              <td className="py-2">
                {item.description}
                {item.period_start !== null && item.period_end !== null ? (
                  <span className="block text-xs opacity-70">
                    {formatDate(item.period_start, locale)} – {formatDate(item.period_end, locale)}
                  </span>
                ) : null}
              </td>
              <td className="py-2 tabular-nums" dir="ltr">{item.quantity}</td>
              <td className="py-2"><MoneyText value={item.unit_amount} /></td>
              <td className="py-2"><MoneyText value={item.tax} /></td>
              <td className="py-2"><MoneyText value={item.total} /></td>
            </tr>
          ))}
        </tbody>
      </table>

      <dl className="mt-4 ms-auto flex max-w-xs flex-col gap-1 text-sm">
        <div className="flex justify-between gap-6">
          <dt>{t('invoices.subtotal')}</dt>
          <dd><MoneyText value={invoice.subtotal} /></dd>
        </div>
        {invoice.discount.minor_units > 0 ? (
          <div className="flex justify-between gap-6">
            <dt>{t('invoices.discount')}</dt>
            <dd><MoneyText value={invoice.discount} /></dd>
          </div>
        ) : null}
        <div className="flex justify-between gap-6">
          <dt>
            {t('invoices.tax')}
            {invoice.items?.[0]?.tax_name != null && invoice.items[0].tax_name !== ''
              ? ` (${invoice.items[0].tax_name})`
              : ''}
          </dt>
          <dd><MoneyText value={invoice.tax} /></dd>
        </div>
        <div className="flex justify-between gap-6 border-t border-black/30 pt-1 font-semibold">
          <dt>{t('invoices.total')}</dt>
          <dd><MoneyText value={invoice.total} /></dd>
        </div>
        <div className="flex justify-between gap-6">
          <dt>{t('invoices.amountPaid')}</dt>
          <dd><MoneyText value={invoice.amount_paid} /></dd>
        </div>
        <div className="flex justify-between gap-6 font-semibold">
          <dt>{t('invoices.amountDue')}</dt>
          <dd><MoneyText value={invoice.amount_due} /></dd>
        </div>
      </dl>

      {/* Not printed: the way back, for the tab this was opened in. */}
      <p className="mt-8 text-sm print:hidden">
        <Link className="underline" to={`/invoices/${invoice.id}`}>
          {t('invoices.backToInvoice')}
        </Link>
      </p>
    </div>
  )
}
