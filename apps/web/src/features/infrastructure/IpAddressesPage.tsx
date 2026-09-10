import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useIpAssignments, useSetReverseDns } from '@/lib/queries'
import type { IpAssignment } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

export function IpAddressesPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<string | null>(null)
  const [hostname, setHostname] = useState('')

  const { data, isPending, error: readError } = useIpAssignments(page)
  const setRdns = useSetReverseDns()

  const displayed = describeError(setRdns.error)

  const columns: Array<Column<IpAssignment>> = [
    {
      key: 'address',
      header: t('ips.address'),
      ltr: true,
      cell: (assignment) => (
        <span className="technical font-medium">
          {assignment.ip_address}
          {assignment.is_primary ? (
            <span className="ms-2 text-xs text-[var(--text-muted)]">{t('ips.primary')}</span>
          ) : null}
        </span>
      ),
    },
    {
      key: 'rdns',
      header: t('ips.reverseDns'),
      ltr: true,
      cell: (assignment) =>
        assignment.reverse_dns === null ? (
          <span className="text-[var(--text-muted)]">—</span>
        ) : (
          <span className="technical">
            {assignment.reverse_dns.hostname}{' '}
            <StatusBadge status={assignment.reverse_dns.status} />
          </span>
        ),
    },
    {
      key: 'actions',
      header: '',
      cell: (assignment) => (
        <Button
          size="sm"
          variant="ghost"
          onClick={() => {
            setEditing(assignment.id)
            setHostname(assignment.reverse_dns?.hostname ?? '')
          }}
        >
          {t('ips.setReverseDns')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.ips')} description={t('ips.subtitle')} />

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
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('nav.ips')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(assignment) => assignment.id}
              empty={t('ips.empty')}
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

      {editing !== null ? (
        <div className="mt-4 max-w-md">
          <Card title={t('ips.setReverseDns')} description={t('ips.reverseDnsHint')}>
            <form
              className="flex flex-col gap-3"
              noValidate
              onSubmit={(event) => {
                event.preventDefault()
                setRdns.mutate(
                  { id: editing, hostname },
                  { onSuccess: () => { setEditing(null); } },
                )
              }}
            >
              <Field
                label={t('ips.hostname')}
                value={hostname}
                dir="ltr"
                onChange={(event) => { setHostname(event.target.value); }}
                error={displayed?.fields?.['hostname']?.[0]}
                required
              />

              <div className="flex gap-2">
                <Button type="submit" loading={setRdns.isPending}>
                  {t('common.save')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => { setEditing(null); }}>
                  {t('common.cancel')}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      ) : null}
    </>
  )
}
