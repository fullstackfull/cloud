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
import { useAdminCustomers, useSetCustomerStatus, type AdminCustomer } from '@/lib/adminQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { useHasPermission } from './useIsOperator'

export function AdminCustomersPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [suspending, setSuspending] = useState<AdminCustomer | null>(null)
  const [reason, setReason] = useState('')

  const { data, isPending, error: readError } = useAdminCustomers(page, search)
  const setStatus = useSetCustomerStatus()
  const maySuspend = useHasPermission('customer.suspend')

  const displayed = describeError(setStatus.error)

  const columns: Array<Column<AdminCustomer>> = [
    {
      key: 'name',
      header: t('admin.customers.name'),
      cell: (customer) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">{customer.display_name}</p>
          <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
            {customer.billing_email ?? '—'}
          </p>
        </div>
      ),
    },
    { key: 'status', header: t('admin.customers.status'), cell: (c) => <StatusBadge status={c.status} /> },
    {
      key: 'currency',
      header: t('admin.customers.currency'),
      ltr: true,
      cell: (c) => (
        <span className="technical">
          {c.currency}
          {c.country !== null ? ` · ${c.country}` : ''}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      cell: (customer) =>
        maySuspend ? (
          <Button
            size="sm"
            variant={customer.status === 'active' ? 'danger' : 'secondary'}
            onClick={() => {
              setSuspending(customer)
              setReason('')
            }}
          >
            {customer.status === 'active' ? t('admin.customers.suspend') : t('admin.customers.reactivate')}
          </Button>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.customers.title')} description={t('admin.customers.subtitle')} />

      <LoadFailure error={readError} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <div className="mb-4 max-w-sm">
        <Field
          label={t('admin.customers.search')}
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            setPage(1)
          }}
          hint={t('admin.customers.searchHint')}
        />
      </div>

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('admin.customers.title')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(customer) => customer.id}
              empty={t('admin.customers.empty')}
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

      {suspending !== null ? (
        <div className="mt-4 max-w-md">
          <Card
            title={
              suspending.status === 'active'
                ? t('admin.customers.suspendTitle', { name: suspending.display_name })
                : t('admin.customers.reactivateTitle', { name: suspending.display_name })
            }
            description={t('admin.customers.suspendExplains')}
          >
            <form
              className="flex flex-col gap-3"
              noValidate
              onSubmit={(event) => {
                event.preventDefault()
                setStatus.mutate(
                  {
                    id: suspending.id,
                    status: suspending.status === 'active' ? 'suspended' : 'active',
                    reason,
                  },
                  { onSuccess: () => { setSuspending(null); } },
                )
              }}
            >
              {/*
                The reason is required by the server, not merely encouraged
                here. An account taken offline without one is an account nobody
                can explain to the customer who rings about it.
              */}
              <Field
                label={t('admin.customers.reason')}
                value={reason}
                onChange={(event) => { setReason(event.target.value); }}
                required
                error={displayed?.fields?.['reason']?.[0]}
              />

              <div className="flex gap-2">
                <Button
                  type="submit"
                  variant={suspending.status === 'active' ? 'danger' : 'primary'}
                  loading={setStatus.isPending}
                >
                  {t('common.confirm')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => { setSuspending(null); }}>
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
