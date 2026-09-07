import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useAdminProvisioningJobs,
  useJobsNeedingReview,
  type AdminProvisioningJob,
} from '@/lib/adminQueries'

/**
 * The provisioning queue.
 *
 * A timed-out job is never retried automatically — the platform stopped
 * waiting, which is not the same as the provider having stopped — so every one
 * of them needs a human to decide. That is why the jobs needing review sit at
 * the top of this page rather than behind a filter somebody has to think to
 * apply.
 */
export function AdminProvisioningPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)

  const { data, isPending, error: jobsError } = useAdminProvisioningJobs(page)
  const { data: review, error: reviewError } = useJobsNeedingReview()

  const columns: Array<Column<AdminProvisioningJob>> = [
    {
      key: 'kind',
      header: t('admin.provisioning.kind'),
      cell: (job) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">
            {t(`admin.provisioning.kinds.${job.kind}`, { defaultValue: job.kind.replace(/_/g, ' ') })}
          </p>
          <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
            {job.id}
          </p>
        </div>
      ),
    },
    { key: 'status', header: t('admin.provisioning.status'), cell: (job) => <StatusBadge status={job.status} /> },
    {
      key: 'attempts',
      header: t('admin.provisioning.attempts'),
      ltr: true,
      cell: (job) => (
        <span className="technical">
          {job.attempts}
          {job.max_attempts !== undefined ? ` / ${job.max_attempts}` : ''}
        </span>
      ),
    },
    {
      key: 'failure',
      header: t('admin.provisioning.failure'),
      cell: (job) =>
        job.failure_class === null ? (
          '—'
        ) : (
          <Badge tone={job.failure_class === 'timeout' ? 'warning' : 'danger'}>
            {t(`admin.provisioning.failureClass.${job.failure_class}`, {
              defaultValue: job.failure_class,
            })}
          </Badge>
        ),
    },
    {
      key: 'created',
      header: t('admin.provisioning.created'),
      cell: (job) => (job.created_at === null ? '—' : formatDateTime(job.created_at, locale)),
    },
  ]

  const needsReview = review?.data ?? []

  return (
    <>
      <PageHeader
        title={t('admin.provisioning.title')}
        description={t('admin.provisioning.subtitle')}
      />

      {/*
        The needs-review count is the point of this screen. A failed read that
        rendered as zero would say the queue is clear when nobody has looked at
        it.
      */}
      <LoadFailure error={jobsError ?? reviewError} />

      {needsReview.length > 0 ? (
        <div className="mb-4">
          <Alert tone="warning" title={t('admin.provisioning.needsReviewTitle', { count: needsReview.length })}>
            <p className="mb-2">{t('admin.provisioning.needsReviewBody')}</p>
            <ul className="flex flex-col gap-1 text-xs">
              {needsReview.slice(0, 5).map((job) => (
                <li key={job.id} dir="ltr" className="technical">
                  {job.id} · {job.kind} · {job.failure_class ?? '—'}
                  {job.last_error !== null ? ` · ${job.last_error.slice(0, 120)}` : ''}
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : (
          <>
            <DataTable
              caption={t('admin.provisioning.title')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(job) => job.id}
              empty={t('admin.provisioning.empty')}
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
