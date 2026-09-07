import { useTranslation } from 'react-i18next'

import { Card } from '@/components/Card'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useWallet } from '@/lib/queries'

export function WalletPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data: wallet, isPending } = useWallet()

  return (
    <>
      <PageHeader title={t('nav.wallet')} description={t('wallet.subtitle')} />

      <div className="max-w-sm">
        <Card title={t('wallet.balance')}>
          {isPending ? (
            <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
          ) : wallet === undefined ? (
            <p className="text-sm text-[var(--text-muted)]">{t('wallet.none')}</p>
          ) : (
            <>
              <p className="text-2xl font-semibold text-[var(--text-primary)]">
                <MoneyText value={wallet.balance} />
              </p>
              {wallet.updated_at !== null ? (
                <p className="mt-1 text-xs text-[var(--text-muted)]">
                  {t('wallet.updated', { when: formatDateTime(wallet.updated_at, locale) })}
                </p>
              ) : null}
            </>
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
