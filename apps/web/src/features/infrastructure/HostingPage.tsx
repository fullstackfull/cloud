import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useHostingAccounts, useHostingSso } from '@/lib/queries'
import type { HostingAccount } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function HostingPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const { data, isPending } = useHostingAccounts(page)
  const sso = useHostingSso()

  const displayed = describeError(sso.error)

  /**
   * The link is opened rather than displayed.
   *
   * It is a one-time credential: rendering it as text leaves it in the page for
   * anything that can read the DOM, and in the browser history if it is ever
   * navigated to directly. The server has already checked that it points at the
   * node's own host — that check lives there and not here, because a client is
   * not a place to enforce anything.
   */
  async function openPanel(account: HostingAccount) {
    const opened = await sso.mutateAsync(account.id).catch(() => null)

    if (opened !== null && opened.url !== '') {
      window.open(opened.url, '_blank', 'noopener,noreferrer')
    }
  }

  const columns: Array<Column<HostingAccount>> = [
    {
      key: 'domain',
      header: t('hosting.domain'),
      ltr: true,
      cell: (account) => (
        <div>
          <p className="technical font-medium text-[var(--text-primary)]">
            {account.primary_domain ?? '—'}
          </p>
          <p className="technical text-xs text-[var(--text-muted)]">{account.username}</p>
        </div>
      ),
    },
    { key: 'status', header: t('hosting.status'), cell: (account) => <StatusBadge status={account.status} /> },
    {
      key: 'package',
      header: t('hosting.package'),
      cell: (account) => account.package ?? '—',
    },
    {
      key: 'actions',
      header: '',
      cell: (account) => (
        <Button
          size="sm"
          variant="secondary"
          loading={sso.isPending && sso.variables === account.id}
          onClick={() => void openPanel(account)}
        >
          {t('hosting.openPanel')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.hosting')} description={t('hosting.subtitle')} />

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
