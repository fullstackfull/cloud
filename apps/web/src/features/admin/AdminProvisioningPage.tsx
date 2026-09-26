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
  useAdoptProvisioningJob,
  useJobsNeedingReview,
  useRepointProvisioningJob,
  useRetryProvisioningJob,
  type AdminProvisioningJob,
} from '@/lib/adminQueries'

/**
 * The reference an adoption would attach: what the job found, or else the
 * identity it reserved before calling — the place its build would be.
 *
 * Except where the job's current finding is that somebody else's machine, by
 * name, holds that identity: then the machine there is the stranger's, and
 * this page does not propose adopting it, whatever the server would say.
 */
function adoptableReference(job: AdminProvisioningJob): string | null {
  if (job.provider_reference != null) return job.provider_reference
  if (job.error_reason === 'named_otherwise') return null

  return job.reserved_provider_id ?? null
}

/**
 * The provisioning queue.
 *
 * A timed-out job is never retried automatically — the platform stopped
 * waiting, which is not the same as the provider having stopped — so every one
 * of them needs a human to decide. That is why the jobs needing review sit at
 * the top of this page rather than behind a filter somebody has to think to
 * apply.
 *
 * Each carries what the runbook tells the operator to read (F-15): the finding
 * and its reason, the whole error rather than a truncation of it, and for a
 * VPS create the provider identity it reserved, with every node an attempt
 * under it was placed on and every name a create under it was sent with.
 * And the three ways out — retry, adopt and repoint — are offered wherever
 * the page has what the act needs. Retry is offered on every job. Adopt is
 * labelled for the machine rather than for "what it built", since it is
 * offered, with the reserved id, on jobs that may have built nothing — right
 * after a repoint, say — and attaches a reference rather than asking for
 * one, so it is offered only where the page has one to attach (see
 * adoptableReference): not on a job that has neither found a provider
 * resource nor reserved an identity — which, until something is found, is
 * every job but a VPS create; a shared-hosting create whose answer was lost
 * is one, and the API can adopt it where this page cannot. Repoint is
 * offered only on a job that holds a reserved identity, since that is all it
 * can move. Beyond that, which of them a job may take is the server's to say,
 * and the page shows its refusal when it gives one. The one thing the page
 * withholds on its own reading of a finding is Adopt with an identity the
 * job's current finding says somebody else's machine holds.
 */
export function AdminProvisioningPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const [retrying, setRetrying] = useState<AdminProvisioningJob | null>(null)
  const [adopting, setAdopting] = useState<AdminProvisioningJob | null>(null)
  const [repointing, setRepointing] = useState<AdminProvisioningJob | null>(null)

  const { data, isPending, error: jobsError } = useAdminProvisioningJobs(page)
  const { data: review, error: reviewError } = useJobsNeedingReview()
  const retry = useRetryProvisioningJob()
  const adopt = useAdoptProvisioningJob()
  const repoint = useRepointProvisioningJob()

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
                  <div dir="ltr" className="technical flex min-w-0 flex-col gap-1">
                    <span>
                      {job.id} · {job.kind} · {job.failure_class ?? '—'}
                      {job.error_code == null ? '' : ` · ${job.error_code}`}
                      {job.error_reason == null ? '' : ` (${job.error_reason})`}
                    </span>
                    {/*
                      Whole, not cut at 120 characters: the part of a
                      taken-identity message that says whose machine it is
                      comes after that.
                    */}
                    {job.last_error === null ? null : <span className="break-words">{job.last_error}</span>}
                    {job.reserved_provider_id == null ? null : (
                      <span>
                        {t('admin.provisioning.reservedIdentity', {
                          id: job.reserved_provider_id,
                          nodes: (job.reserved_provider_nodes ?? []).join(', ') || '—',
                          names: (job.reserved_provider_hostnames ?? []).join(', ') || '—',
                        })}
                      </span>
                    )}
                  </div>
                  {/*
                    Offered on every job here, and refused by the API for the
                    ones where a second run would build a second machine or
                    destroy a disk again. The refusal is shown rather than
                    pre-empted: the reason is the useful part, and only the
                    server knows it.
                  */}
                  <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="secondary" onClick={() => { setRetrying(job); }}>
                      {t('admin.provisioning.retry')}
                    </Button>
                    {adoptableReference(job) === null ? null : (
                      <Button size="sm" variant="secondary" onClick={() => { setAdopting(job); }}>
                        {t('admin.provisioning.adopt')}
                      </Button>
                    )}
                    {job.reserved_provider_id == null ? null : (
                      <Button size="sm" variant="secondary" onClick={() => { setRepointing(job); }}>
                        {t('admin.provisioning.repoint')}
                      </Button>
                    )}
                  </div>
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

      <ConfirmDialog
        open={adopting !== null}
        title={t('admin.provisioning.adoptTitle')}
        body={
          <div className="flex flex-col gap-2">
            <p>{t('admin.provisioning.adoptBody', { reference: adopting === null ? '' : (adoptableReference(adopting) ?? '') })}</p>
          </div>
        }
        evidenceLabel={t('admin.provisioning.adoptEvidence')}
        evidenceHint={t('admin.provisioning.adoptEvidenceHint')}
        confirmLabel={t('admin.provisioning.adopt')}
        loading={adopt.isPending}
        error={adopt.error === null ? undefined : adopt.error.message}
        onCancel={() => { setAdopting(null); adopt.reset(); }}
        onConfirm={(_phrase, evidence) => {
          const reference = adopting === null ? null : adoptableReference(adopting)
          if (adopting === null || reference === null) return

          adopt.mutate(
            { id: adopting.id, providerReference: reference, evidence },
            { onSuccess: () => { setAdopting(null); } },
          )
        }}
      />

      <ConfirmDialog
        open={repointing !== null}
        title={t('admin.provisioning.repointTitle')}
        body={
          <div className="flex flex-col gap-2">
            <p>{t('admin.provisioning.repointBody')}</p>
          </div>
        }
        evidenceLabel={t('admin.provisioning.repointEvidence')}
        evidenceHint={t('admin.provisioning.repointEvidenceHint')}
        confirmLabel={t('admin.provisioning.repoint')}
        loading={repoint.isPending}
        error={repoint.error === null ? undefined : repoint.error.message}
        onCancel={() => { setRepointing(null); repoint.reset(); }}
        onConfirm={(_phrase, evidence) => {
          if (repointing === null) return

          repoint.mutate(
            { id: repointing.id, evidence },
            { onSuccess: () => { setRepointing(null); } },
          )
        }}
      />
    </>
  )
}
