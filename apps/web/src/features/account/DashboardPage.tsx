import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { PageHeader } from '@/components/PageHeader'
import { CountryCurrencySection } from '@/features/account/CountryCurrencySection'
import { useCurrentUser } from '@/features/auth/useAuth'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'

export function DashboardPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data: user } = useCurrentUser()

  if (user === null || user === undefined) return null

  return (
    <>
      <PageHeader
        title={t('account.welcome', { name: user.name })}
        description={t('account.dashboardSubtitle')}
      />

      <div className="grid gap-4 sm:grid-cols-2">
        <Card title={t('account.accountsTitle')} description={t('account.accountsSubtitle')}>
          {user.customers.length === 0 ? (
            <p className="text-sm text-[var(--text-muted)]">{t('account.noAccounts')}</p>
          ) : (
            <ul className="flex flex-col gap-3">
              {user.customers.map((customer) => (
                <li
                  key={customer.id}
                  className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-[var(--border-subtle)] p-3"
                >
                  <div className="min-w-0">
                    <p className="truncate font-medium text-[var(--text-primary)]">
                      {customer.display_name}
                    </p>
                    <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
                      {customer.currency}
                      {customer.country !== null ? ` · ${customer.country}` : ''}
                    </p>
                  </div>
                  <Badge tone={customer.status === 'active' ? 'success' : 'warning'}>
                    {t(`status.${customer.status}`, { defaultValue: customer.status })}
                  </Badge>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title={t('account.securityTitle')} description={t('account.securitySubtitle')}>
          <dl className="flex flex-col gap-3 text-sm">
            <div className="flex items-center justify-between gap-3">
              <dt className="text-[var(--text-secondary)]">{t('security.twoFactor')}</dt>
              <dd>
                {user.two_factor_enabled ? (
                  <Badge tone="success">{t('security.enabled')}</Badge>
                ) : (
                  <Badge tone="warning">{t('security.disabled')}</Badge>
                )}
              </dd>
            </div>

            <div className="flex items-center justify-between gap-3">
              <dt className="text-[var(--text-secondary)]">{t('account.emailVerified')}</dt>
              <dd>
                {user.email_verified ? (
                  <Badge tone="success">{t('common.yes')}</Badge>
                ) : (
                  <Badge tone="warning">{t('common.no')}</Badge>
                )}
              </dd>
            </div>

            {user.last_login_at !== null ? (
              <div className="flex items-center justify-between gap-3">
                <dt className="text-[var(--text-secondary)]">{t('account.lastSignIn')}</dt>
                <dd className="text-[var(--text-primary)]">
                  {formatDateTime(user.last_login_at, locale)}
                </dd>
              </div>
            ) : null}
          </dl>

          <Link
            to="/security"
            className="mt-4 inline-block text-sm font-medium text-brand-600 hover:underline"
          >
            {t('account.reviewSecurity')}
          </Link>
        </Card>
      </div>

      {/*
        * One billing account is the ordinary case, and its country and
        * currency are shown with the request to change them. Several
        * accounts each get their own card: what one is billed in says
        * nothing about another.
        */}
      {user.customers.map((customer) => (
        <div key={customer.id} className="mt-4">
          <CountryCurrencySection customer={customer} />
        </div>
      ))}
    </>
  )
}
