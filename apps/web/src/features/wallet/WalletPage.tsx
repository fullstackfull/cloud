import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useWallet } from '@/lib/queries'

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
  const { data: wallet, isPending } = useWallet()

  const balances = wallet?.data ?? []
  const accountCurrency = wallet?.meta.account_currency

  return (
    <>
      <PageHeader title={t('nav.wallet')} description={t('wallet.subtitle')} />

      <div className="max-w-sm">
        <Card title={t('wallet.balance')}>
          {isPending ? (
            <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
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
    </>
  )
}
