import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate, formatDateTime } from '@/lib/format'
import { useWallet, useWalletTransactions } from '@/lib/queries'
import type { WalletTransaction } from '@/lib/types'
import { safeLabel } from '@/lib/safeLabel'

/**
 * Every balance the account holds, one per currency.
 *
 * Not one number. GET /wallet answers a list because a customer transacting in
 * two currencies has two independent balances and no total — adding them would
 * need a rate, and a converted balance is not one the platform would let them
 * spend. So each is printed on its own, and `meta.account_currency` names the
 * one that is theirs by default rather than the screen guessing at the first
 * row.
 *
 * The list is never empty in practice: the API reports the account currency
 * whether or not a wallets row was ever opened, so a customer who has never
 * been paid a credit sees a real zero rather than an absence. The empty state
 * below is kept for the case where that stops being true, not as the normal
 * answer — it used to be shown to every customer who *had* money, which is the
 * defect this screen was rewritten to fix.
 */
export function WalletPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data: wallet, isPending, error: readError } = useWallet()

  const [page, setPage] = useState(1)
  const ledger = useWalletTransactions(page)

  const balances = wallet?.data ?? []
  const accountCurrency = wallet?.meta.account_currency

  const ledgerColumns: Array<Column<WalletTransaction>> = [
    {
      key: 'when',
      header: t('wallet.entryWhen'),
      cell: (entry) => (entry.created_at === null ? '—' : formatDate(entry.created_at, locale)),
    },
    {
      key: 'kind',
      header: t('wallet.entryKind'),
      cell: (entry) => safeLabel('walletKind', entry.kind),
    },
    { key: 'description', header: t('wallet.entryDescription'), cell: (entry) => entry.description },
    {
      key: 'amount',
      header: t('wallet.entryAmount'),
      cell: (entry) => (
        <span className="flex items-center gap-2">
          <MoneyText value={entry.amount} />
          {/*
            The direction is a word as well as a sign: a minus on its own is
            easy to miss, and colour alone would mean nothing to a screen
            reader.
          */}
          <span className="text-xs text-[var(--text-muted)]">
            {t(`wallet.direction.${entry.direction}`)}
          </span>
        </span>
      ),
    },
    {
      key: 'balance',
      header: t('wallet.entryBalanceAfter'),
      cell: (entry) => <MoneyText value={entry.balance_after} />,
    },
    {
      key: 'invoice',
      header: t('wallet.entryInvoice'),
      cell: (entry) =>
        entry.invoice_id === null ? (
          '—'
        ) : (
          <Link className="underline" to={`/invoices/${entry.invoice_id}`}>
            {t('payments.viewInvoice')}
          </Link>
        ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.wallet')} description={t('wallet.subtitle')} />

      <LoadFailure error={readError} />

      <div className="max-w-sm">
        <Card title={t('wallet.balance')}>
          {isPending ? (
            <Loading />
          ) : balances.length === 0 ? (
            <p className="text-sm text-[var(--text-muted)]">{t('wallet.none')}</p>
          ) : (
            <ul className="space-y-4">
              {balances.map((balance) => (
                <li key={balance.currency}>
                  <p className="text-2xl font-semibold text-[var(--text-primary)]">
                    <MoneyText value={balance.balance} />
                  </p>
                  {balance.updated_at !== null ? (
                    <p className="mt-1 text-xs text-[var(--text-muted)]">
                      {t('wallet.updated', { when: formatDateTime(balance.updated_at, locale) })}
                    </p>
                  ) : (
                    <p className="mt-1 text-xs text-[var(--text-muted)]">{t('wallet.noMovement')}</p>
                  )}
                  {/*
                    Only worth saying when there is something to distinguish it
                    from: on a single-currency account it is the only balance
                    there is.
                  */}
                  {balances.length > 1 && balance.currency === accountCurrency ? (
                    <p className="mt-1 text-xs text-[var(--text-muted)]">
                      {t('wallet.accountCurrency')}
                    </p>
                  ) : null}
                </li>
              ))}
            </ul>
          )}

          {/*
            No top-up control. A customer cannot credit their own wallet: the
            balance is put there by a payment or by an administrator, and a
            button here would be a button that has to be refused server-side
            anyway.
          */}
          <p className="mt-4 text-xs text-[var(--text-muted)]">{t('wallet.howItFills')}</p>
        </Card>
      </div>

      {/*
        The ledger, which is where the balance above comes from.

        The portal does not add these rows up and compare the answer with the
        balance: the balance is the server's, the ledger is its history, and two
        sums that can disagree is how a customer stops trusting both. Each row
        carries the balance the platform recorded after it, so a customer can
        follow the money without arithmetic.
      */}
      <Card
        title={t('wallet.ledger')}
        description={t('wallet.ledgerBody')}
        className="mt-6"
      >
        <LoadFailure error={ledger.error} />

        {ledger.isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('wallet.ledger')}
              columns={ledgerColumns}
              rows={ledger.data?.data ?? []}
              rowKey={(entry) => entry.id}
              empty={t('wallet.noEntries')}
            />
            <Paginator
              page={ledger.data?.meta.page ?? 1}
              lastPage={ledger.data?.meta.last_page ?? 1}
              total={ledger.data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  )
}
