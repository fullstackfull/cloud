import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useHostingAccounts } from '@/lib/queries'
import type { HostingAccount } from '@/lib/types'
import { useUrlPage } from '@/lib/urlState'

import { HostingPanelButton } from './hosting/HostingPanelButton'

/**
 * The hosting accounts this account has: an index, since Wave 3.
 *
 * The plan column shows the catalogue name rather than the slug — a slug is a
 * join key and it was on screen where a plan name belonged — and the domain
 * links to the account's own page, where the panel type, the quotas and the
 * real usage reading live.
 */
export function HostingPage() {
  const { t } = useTranslation()
  // W5.7: in the address bar rather than in component state, so a refresh
  // stays on this page and Back returns to it from whatever the customer
  // opened. The one mechanism is in `useUrlPage`.
  const [page, setPage] = useUrlPage()
  const { data, isPending, error: readError } = useHostingAccounts(page)

  const columns: Array<Column<HostingAccount>> = [
    {
      key: 'domain',
      header: t('hosting.domain'),
      ltr: true,
      cell: (account) => (
        <div>
          <Link
            to={`/hosting/${account.id}`}
            className="technical font-medium text-[var(--text-primary)] hover:underline"
          >
            {account.primary_domain ?? account.username}
          </Link>
          <p className="technical text-xs text-[var(--text-muted)]">{account.username}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: t('hosting.status'),
      cell: (account) => <StatusBadge status={account.status} />,
    },
    {
      key: 'package',
      header: t('hosting.package'),
      cell: (account) =>
        account.package === null ? (
          '—'
        ) : (
          <span className="text-sm text-[var(--text-primary)]">
            {account.package.plan_name ?? account.package.slug}
          </span>
        ),
    },
    {
      key: 'actions',
      header: '',
      cell: (account) => (
        <div className="flex flex-wrap items-center justify-end gap-3">
          <HostingPanelButton account={account} />
          <Link to={`/hosting/${account.id}`} className="text-sm underline">
            {t('resource.open')}
          </Link>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.hosting')} description={t('hosting.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.hosting')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(account) => account.id}
              empty={t('hosting.empty')}
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
