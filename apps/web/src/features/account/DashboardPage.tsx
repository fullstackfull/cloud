import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { MoneyText } from '@/components/MoneyText'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { CountryCurrencySection } from '@/features/account/CountryCurrencySection'
import { useCurrentUser } from '@/features/auth/useAuth'
import { pathForResource } from '@/features/resources/resourcePaths'
import { supportPathFor } from '@/features/support/supportContext'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate, formatNumber, formatRelative } from '@/lib/format'
import { useAccountOverview } from '@/lib/queries'
import type { AccountOverview, ActivityItem, AttentionItem } from '@/lib/types'

/**
 * The first page of the portal.
 *
 * AR-6 and BD-4. What was here before was two cards — the account's name and
 * currency, and whether two-factor was on — plus a country-change form. No
 * service counts, no invoices due, no renewals, no tickets, no unread count,
 * nothing that had happened. It rendered `null` while the user loaded and had
 * no error state at all, so a failed read was a blank page.
 *
 * Attention first, and that is the whole layout decision. A dashboard that
 * leads with "you have 4 services" leads with a number nobody logged in to
 * read; a dashboard that leads with "an invoice is overdue" and "a rebuild
 * stopped and we are looking at it" leads with the reason they logged in. The
 * order inside that list is the server's, so an Arabic dashboard and an
 * English one agree about what matters most, and so the priority rules live in
 * one place rather than in every client.
 *
 * One request answers all of it. The alternative the audit floated — four
 * existing list endpoints — would mean the browser deciding between an unpaid
 * invoice and an expiring domain, and a phone paying for four list payloads to
 * draw six numbers.
 *
 * Money is grouped by currency and never totalled. An account billed in KWD
 * and USD is owed two amounts; `10 KWD + 20 USD = 30` is not a number that
 * exists, and a dashboard that printed it would be wrong in a way a customer
 * would believe.
 */
export function DashboardPage() {
  const { t } = useTranslation()
  const { data: user } = useCurrentUser()
  const { data: overview, isPending, error } = useAccountOverview()

  return (
    <>
      <PageHeader
        title={
          user === null || user === undefined
            ? t('dashboard.title')
            : t('account.welcome', { name: user.name })
        }
        description={t('dashboard.subtitle')}
      />

      {/*
        AS-15. A read that fails says so. The old page returned `null` for a
        missing user, which is the same pixels as a working page with nothing
        on it.
      */}
      <LoadFailure error={error} />

      {isPending ? (
        <Loading />
      ) : overview === undefined ? null : (
        <Overview overview={overview} />
      )}

      {/*
        One billing account is the ordinary case, and its country and currency
        are shown with the request to change them. Several accounts each get
        their own card: what one is billed in says nothing about another.
      */}
      {(user?.customers ?? []).map((customer) => (
        <div key={customer.id} className="mt-4">
          <CountryCurrencySection customer={customer} />
        </div>
      ))}
    </>
  )
}

function Overview({ overview }: { overview: AccountOverview }) {
  const nothingYet =
    overview.services.total === 0 &&
    overview.attention.length === 0 &&
    overview.recent.activity.length === 0

  if (nothingYet) return <NewAccount />

  return (
    <div className="flex flex-col gap-4">
      <Attention items={overview.attention} />

      <div className="grid gap-4 lg:grid-cols-3">
        <Services services={overview.services} />
        <Owed due={overview.billing.due} />
        <Renewals renewals={overview.renewals} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <RecentServices services={overview.recent.services} />
        <RecentActivity items={overview.recent.activity} />
      </div>
    </div>
  )
}

/**
 * An account with nothing in it.
 *
 * §61. Not an empty dashboard with six empty cards, which reads as a broken
 * dashboard, and not a wall of zeros. One sentence about why it is empty and
 * one way out of that state.
 */
function NewAccount() {
  const { t } = useTranslation()

  return (
    <Card title={t('dashboard.newAccountTitle')}>
      <p className="text-sm text-[var(--text-secondary)]">{t('dashboard.newAccountBody')}</p>

      <Link
        to="/catalogue"
        className="mt-3 inline-block text-sm font-medium text-[var(--accent)] hover:underline"
      >
        {t('dashboard.servicesBrowse')}
      </Link>
    </Card>
  )
}

const SEVERITY_TONES = {
  critical: 'danger',
  warning: 'warning',
  info: 'info',
} as const

function Attention({ items }: { items: AttentionItem[] }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()

  return (
    <Card title={t('attention.title')} description={t('attention.body')}>
      {items.length === 0 ? (
        // A real answer rather than a hidden card: "nothing needs your
        // attention" is information, and a dashboard whose first section
        // disappears when things are fine teaches nobody where to look when
        // they are not.
        <EmptyState>{t('attention.none')}</EmptyState>
      ) : (
        <ul className="flex flex-col divide-y divide-[var(--border-subtle)]">
          {items.map((item) => {
            const destination =
              item.resource === null ? null : pathForResource(item.resource.kind, item.resource.id)

            return (
              <li key={item.id} className="flex flex-wrap items-start gap-3 py-3 first:pt-0">
                <Badge tone={SEVERITY_TONES[item.severity]}>
                  {t(`attention.severity.${item.severity}`)}
                </Badge>

                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-[var(--text-primary)]">{t(item.kind)}</p>

                  <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-[var(--text-muted)]">
                    {item.resource?.identity === undefined ||
                    item.resource.identity === null ? null : (
                      <span className="technical" dir="ltr">
                        {item.resource.identity}
                      </span>
                    )}

                    {item.reference === null ? null : (
                      <span className="technical" dir="ltr">
                        {item.reference}
                      </span>
                    )}

                    <time dateTime={item.occurred_at}>
                      {formatRelative(item.occurred_at, locale)}
                    </time>
                  </p>
                </div>

                <div className="flex shrink-0 flex-col items-start gap-1 sm:items-end">
                  {/*
                    The exact thing, not its list. §36: a dashboard row that
                    linked to `/invoices` would make the customer find the
                    overdue one again, which is the work the row exists to
                    save.
                  */}
                  {destination === null ? null : (
                    <Link
                      to={destination}
                      className="text-sm font-medium text-[var(--accent)] hover:underline"
                    >
                      {t('resource.open')}
                    </Link>
                  )}

                  <Link
                    to={supportPathFor({
                      subjectKey: item.kind,
                      ...(item.resource === null ? {} : { resource: item.resource }),
                      ...(item.reference === null ? {} : { reference: item.reference }),
                    })}
                    className="text-xs text-[var(--text-muted)] hover:underline"
                  >
                    {t('activity.feed.askSupport')}
                  </Link>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

function Services({ services }: { services: AccountOverview['services'] }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()

  const states = Object.entries(services.by_state)

  return (
    <Card title={t('dashboard.servicesTitle')}>
      {services.total === 0 ? (
        <>
          <EmptyState>{t('dashboard.servicesEmpty')}</EmptyState>
          <Link
            to="/catalogue"
            className="text-sm font-medium text-[var(--accent)] hover:underline"
          >
            {t('dashboard.servicesBrowse')}
          </Link>
        </>
      ) : (
        <>
          <p className="text-sm text-[var(--text-secondary)]">
            {t('dashboard.servicesTotal', { count: services.total })}
          </p>

          <ul className="mt-3 flex flex-wrap gap-2">
            {states.map(([state, count]) => (
              <li key={state} className="flex items-center gap-1.5">
                <StatusBadge status={state} />
                <span className="text-sm text-[var(--text-muted)] tabular-nums">
                  {formatNumber(count, locale)}
                </span>
              </li>
            ))}
          </ul>

          <Link
            to="/services"
            className="mt-4 inline-block text-sm font-medium text-[var(--accent)] hover:underline"
          >
            {t('nav.allServices')}
          </Link>
        </>
      )}
    </Card>
  )
}

function Owed({ due }: { due: AccountOverview['billing']['due'] }) {
  const { t } = useTranslation()

  return (
    <Card title={t('dashboard.billingTitle')}>
      {due.length === 0 ? (
        <EmptyState>{t('dashboard.billingNothing')}</EmptyState>
      ) : (
        <ul className="flex flex-col gap-2">
          {/*
            One row per currency, and no total. Two currencies owing is two
            facts; a sum of them is arithmetic nobody can perform.
          */}
          {due.map((entry) => (
            <li key={entry.amount.currency} className="flex items-baseline justify-between gap-3">
              <span className="text-base font-semibold text-[var(--text-primary)]">
                <MoneyText value={entry.amount} />
              </span>
              <span className="text-sm text-[var(--text-muted)]">
                {t('dashboard.billingInvoices', { count: entry.invoices })}
              </span>
            </li>
          ))}
        </ul>
      )}

      <Link
        to="/invoices"
        className="mt-4 inline-block text-sm font-medium text-[var(--accent)] hover:underline"
      >
        {t('dashboard.billingOpen')}
      </Link>
    </Card>
  )
}

function Renewals({ renewals }: { renewals: AccountOverview['renewals'] }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()

  return (
    <Card title={t('dashboard.renewalsTitle')}>
      {renewals.length === 0 ? (
        <EmptyState>{t('dashboard.renewalsNone')}</EmptyState>
      ) : (
        <ul className="flex flex-col gap-3">
          {renewals.map((renewal) => {
            const destination =
              renewal.resource.kind === null
                ? null
                : pathForResource(renewal.resource.kind, renewal.resource.id)

            return (
              <li key={`${renewal.kind}:${renewal.resource.id}`} className="text-sm">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  {destination === null ? (
                    <span className="technical" dir="ltr">
                      {renewal.resource.identity ?? t('resource.notAvailable')}
                    </span>
                  ) : (
                    <Link
                      to={destination}
                      className="text-[var(--accent)] hover:underline"
                    >
                      <span className="technical" dir="ltr">
                        {renewal.resource.identity ?? t('resource.open')}
                      </span>
                    </Link>
                  )}

                  {/*
                    Null where the platform has no authoritative amount. A
                    domain's renewal price comes from the catalogue at the
                    moment of renewal, so printing today's would be quoting a
                    guess as a commitment.
                  */}
                  {renewal.amount === null ? (
                    <span className="text-xs text-[var(--text-muted)]">
                      {t('dashboard.renewalsPriceAtRenewal')}
                    </span>
                  ) : (
                    <MoneyText value={renewal.amount} />
                  )}
                </div>

                <time dateTime={renewal.at} className="text-xs text-[var(--text-muted)]">
                  {formatDate(renewal.at, locale)}
                </time>
              </li>
            )
          })}
        </ul>
      )}

      <Link
        to="/subscriptions"
        className="mt-4 inline-block text-sm font-medium text-[var(--accent)] hover:underline"
      >
        {t('nav.subscriptions')}
      </Link>
    </Card>
  )
}

function RecentServices({ services }: { services: AccountOverview['recent']['services'] }) {
  const { t } = useTranslation()

  return (
    <Card title={t('dashboard.recentTitle')}>
      {services.length === 0 ? (
        <EmptyState>{t('dashboard.servicesEmpty')}</EmptyState>
      ) : (
        <ul className="flex flex-col divide-y divide-[var(--border-subtle)]">
          {services.map((service) => {
            const destination = pathForResource(service.kind, service.id)

            return (
              <li
                key={`${service.kind}:${service.id}`}
                className="flex items-center justify-between gap-3 py-2 first:pt-0"
              >
                {destination === null ? (
                  <span className="technical truncate" dir="ltr">
                    {service.identity ?? service.id}
                  </span>
                ) : (
                  <Link
                    to={destination}
                    className="truncate text-sm text-[var(--accent)] hover:underline"
                  >
                    <span className="technical" dir="ltr">
                      {service.identity ?? service.id}
                    </span>
                  </Link>
                )}

                <StatusBadge status={service.state} />
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

function RecentActivity({ items }: { items: ActivityItem[] }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()

  return (
    <Card title={t('dashboard.activityTitle')}>
      {items.length === 0 ? (
        <EmptyState>{t('activity.feed.empty')}</EmptyState>
      ) : (
        <ul className="flex flex-col divide-y divide-[var(--border-subtle)]">
          {items.map((item) => (
            <li key={item.id} className="flex items-start justify-between gap-3 py-2 first:pt-0">
              <div className="min-w-0">
                <p className="truncate text-sm text-[var(--text-primary)]">
                  {t(item.message_code)}
                </p>
                <time dateTime={item.occurred_at} className="text-xs text-[var(--text-muted)]">
                  {formatRelative(item.occurred_at, locale)}
                </time>
              </div>

              <StatusBadge status={item.state} />
            </li>
          ))}
        </ul>
      )}

      {/*
        The dashboard shows five rows from the same feed `/activity` reads, so
        this is a way further into one history rather than a link to a second
        one.
      */}
      <Link
        to="/activity"
        className="mt-4 inline-block text-sm font-medium text-[var(--accent)] hover:underline"
      >
        {t('dashboard.activityAll')}
      </Link>
    </Card>
  )
}
