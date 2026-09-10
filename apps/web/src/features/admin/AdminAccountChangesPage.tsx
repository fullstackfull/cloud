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
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useAdminCountryCurrencyChanges,
  useDecideCountryCurrencyChange,
  type AdminCountryCurrencyChange,
} from '@/lib/adminQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { useHasPermission } from './useIsOperator'

const STATES = ['', 'needs_review', 'awaiting_approval', 'scheduled', 'blocked', 'applied', 'rejected', 'withdrawn'] as const

/**
 * The queue of requests to change what an account is billed in.
 *
 * The decision is the operator's; the check is the platform's. Approving
 * re-runs the analysis and is refused past a blocker, so the button cannot
 * do what the customer's screen said could not be done. The blockers and
 * the customer's reason are on the row, in words, so the decision is made
 * from the same facts the customer saw.
 */
export function AdminAccountChangesPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const [state, setState] = useState<string>('')
  const [deciding, setDeciding] = useState<{ change: AdminCountryCurrencyChange; verdict: 'approve' | 'reject' } | null>(null)
  const [note, setNote] = useState('')
  const [applyAt, setApplyAt] = useState('')

  const { data, isPending, error: readError } = useAdminCountryCurrencyChanges(page, state)
  const decide = useDecideCountryCurrencyChange()
  const mayDecide = useHasPermission('customer.update')

  const failure = describeError(decide.error)
  const where = (code: string | null) => (code === null || code === '' ? '' : ` · ${code}`)

  const columns: Array<Column<AdminCountryCurrencyChange>> = [
    {
      key: 'account',
      header: t('admin.accountChanges.account'),
      cell: (row) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">{row.customer.display_name ?? row.customer_id}</p>
          <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
            {row.customer.billing_email ?? '—'}
          </p>
        </div>
      ),
    },
    {
      key: 'change',
      header: t('admin.accountChanges.change'),
      ltr: true,
      cell: (row) => (
        <span className="technical text-xs">
          {row.from_currency}
          {where(row.from_country)} → {row.to_currency}
          {where(row.to_country)}
        </span>
      ),
    },
    { key: 'state', header: t('admin.accountChanges.state'), cell: (row) => <StatusBadge status={row.state} /> },
    {
      key: 'asked',
      header: t('admin.accountChanges.asked'),
      cell: (row) => (
        <div className="text-xs">
          <p>{formatDateTime(row.created_at, locale)}</p>
          <p className="text-[var(--text-muted)]">{row.reason}</p>
        </div>
      ),
    },
    {
      key: 'facts',
      header: t('admin.accountChanges.blockers'),
      cell: (row) =>
        row.impact.blockers.length === 0 ? (
          <span className="text-xs text-[var(--text-muted)]">—</span>
        ) : (
          <ul className="list-disc ps-4 text-xs">
            {row.impact.blockers.map((line) => (
              <li key={line}>{line}</li>
            ))}
          </ul>
        ),
    },
    {
      key: 'decide',
      header: t('admin.accountChanges.decide'),
      cell: (row) =>
        mayDecide && row.is_open ? (
          <div className="flex gap-2">
            <Button
              size="sm"
              variant="secondary"
              disabled={row.state === 'blocked'}
              onClick={() => { setDeciding({ change: row, verdict: 'approve' }); setNote(''); setApplyAt('') }}
            >
              {t('admin.accountChanges.approve')}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => { setDeciding({ change: row, verdict: 'reject' }); setNote('') }}>
              {t('admin.accountChanges.reject')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.accountChanges.title')} description={t('admin.accountChanges.subtitle')} />

      <LoadFailure error={readError} />

      <div className="mb-4 max-w-xs">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium">{t('admin.accountChanges.filter')}</span>
          <select
            value={state}
            onChange={(event) => { setState(event.target.value); setPage(1) }}
            className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
          >
            {STATES.map((option) => (
              <option key={option} value={option}>
                {option === '' ? t('admin.accountChanges.allOpen') : t(`status.${option}`, { defaultValue: option })}
              </option>
            ))}
          </select>
        </label>
      </div>

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('admin.accountChanges.title')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(row) => row.id}
              empty={t('admin.accountChanges.empty')}
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

      {deciding === null ? null : (
        <div className="mt-4 max-w-md">
          <Card
            title={t(deciding.verdict === 'approve' ? 'admin.accountChanges.approveTitle' : 'admin.accountChanges.rejectTitle', {
              name: deciding.change.customer.display_name ?? deciding.change.customer_id,
            })}
            description={
              deciding.verdict === 'approve'
                ? t('admin.accountChanges.approveBody', {
                    currency: deciding.change.to_currency,
                    country: where(deciding.change.to_country),
                  })
                : t('admin.accountChanges.rejectBody')
            }
          >
            <form
              className="flex flex-col gap-3"
              noValidate
              onSubmit={(event) => {
                event.preventDefault()
                decide.mutate(
                  { id: deciding.change.id, verdict: deciding.verdict, note, applyAt },
                  { onSuccess: () => { setDeciding(null) } },
                )
              }}
            >
              <Field
                label={t('admin.accountChanges.note')}
                value={note}
                onChange={(event) => { setNote(event.target.value) }}
                required
                error={failure?.fields?.['note']?.[0]}
              />

              {deciding.verdict === 'approve' ? (
                <Field
                  label={t('admin.accountChanges.applyAt')}
                  type="datetime-local"
                  dir="ltr"
                  value={applyAt}
                  onChange={(event) => { setApplyAt(event.target.value) }}
                  hint={t('admin.accountChanges.applyAtHint')}
                  error={failure?.fields?.['apply_at']?.[0]}
                />
              ) : null}

              {failure === null || failure.fields !== null ? null : (
                <Alert tone="error" requestId={failure.requestId}>
                  {failure.message}
                </Alert>
              )}

              <div className="flex gap-2">
                <Button type="submit" variant={deciding.verdict === 'approve' ? 'primary' : 'danger'} loading={decide.isPending}>
                  {t('common.confirm')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => { setDeciding(null); decide.reset() }}>
                  {t('common.cancel')}
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}
    </>
  )
}
