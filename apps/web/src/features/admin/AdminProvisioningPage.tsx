import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useAdminProvisioningJobs,
  useJobsNeedingReview,
  useRetryProvisioningJob,
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
  const [retrying, setRetrying] = useState<AdminProvisioningJob | null>(null)

  const { data, isPending, error: jobsError } = useAdminProvisioningJobs(page)
  const { data: review, error: reviewError } = useJobsNeedingReview()
  const retry = useRetryProvisioningJob()

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
            <ul className="flex flex-col gap-2 text-xs">
              {needsReview.slice(0, 5).map((job) => (
                <li key={job.id} className="flex flex-wrap items-center justify-between gap-2">
                  <span dir="ltr" className="technical">
                    {job.id} · {job.kind} · {job.failure_class ?? '—'}
                    {job.last_error !== null ? ` · ${job.last_error.slice(0, 120)}` : ''}
                  </span>
                  {/*
                    Offered on every job here, and refused by the API for the
                    ones where a second run would build a second machine or
                    destroy a disk again. The refusal is shown rather than
                    pre-empted: the reason is the useful part, and only the
                    server knows it.
                  */}
                  <Button size="sm" variant="secondary" onClick={() => { setRetrying(job); }}>
                    {t('admin.provisioning.retry')}
                  </Button>
                </li>
              ))}
            </ul>
          </Alert>
        </div>
      ) : null}

      <Card>
        {isPending ? (
          <Loading />
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

      <ConfirmDialog
        open={retrying !== null}
        title={t('admin.provisioning.retryTitle')}
        body={
          <div className="flex flex-col gap-2">
            <p>{t('admin.provisioning.retryBody')}</p>
            {retrying?.last_error === null || retrying?.last_error === undefined ? null : (
              <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
                {retrying.last_error}
              </p>
            )}
          </div>
        }
        evidenceLabel={t('admin.provisioning.retryEvidence')}
        evidenceHint={t('admin.provisioning.retryEvidenceHint')}
        confirmLabel={t('admin.provisioning.retry')}
        loading={retry.isPending}
        error={retry.error === null ? undefined : retry.error.message}
        onCancel={() => { setRetrying(null); retry.reset(); }}
        onConfirm={(_phrase, evidence) => {
          if (retrying === null) return

          retry.mutate(
            { id: retrying.id, evidence },
            { onSuccess: () => { setRetrying(null); } },
          )
        }}
      />
    </>
  )
}
