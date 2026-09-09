import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'
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
import { useCancelDeployment, useDeployments, useResolveDeployment, type DeploymentJob } from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Every run, the ones waiting for a person first.
 *
 * The one thing this screen must never do is make an indeterminate run look
 * like anything else. It is red, it is first, and the only control on it is
 * the one that asks a person what they found.
 */
export function DeploymentsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const { data, isPending, error } = useDeployments(page)
  const selected = data?.data.find((job) => job.id === selectedId) ?? null

  const columns: Array<Column<DeploymentJob>> = [
    { key: 'machine', header: t('admin.deployments.machine'), ltr: true, cell: (job) => <span className="technical">{job.server_name ?? job.server_id}</span> },
    { key: 'kind', header: t('admin.deployments.kind'), cell: (job) => t(`admin.deployments.kinds.${job.kind}`) },
    {
      key: 'state',
      header: t('admin.deployments.state'),
      cell: (job) => (
        <span className="flex flex-wrap items-center gap-2">
          <StatusBadge status={job.state} />
          {job.waits_for_somebody ? <Badge tone="danger">{t('admin.deployments.waiting')}</Badge> : null}
        </span>
      ),
    },
    { key: 'requested', header: t('admin.deployments.requested'), cell: (job) => (job.requested_at === null ? '—' : formatDateTime(job.requested_at, locale)) },
    { key: 'finished', header: t('admin.deployments.finished'), cell: (job) => (job.finished_at === null ? '—' : formatDateTime(job.finished_at, locale)) },
    {
      key: 'open',
      header: '',
      cell: (job) => (
        <Button size="sm" variant="ghost" onClick={() => { setSelectedId(job.id === selectedId ? null : job.id); }}>
          {job.id === selectedId ? t('common.close') : t('admin.deployments.open')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.deployments.title')} description={t('admin.deployments.subtitle')} />

      <Card>
        {isPending ? (
          <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : error ? (
          <LoadFailure error={error} />
        ) : (
          <>
            <DataTable columns={columns} rows={data.data} rowKey={(job) => job.id} empty={t('admin.deployments.empty')} caption={t('admin.deployments.title')} />
            <Paginator page={page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />
          </>
        )}
      </Card>

      {selected === null ? null : <DeploymentDetail job={selected} />}
    </>
  )
}

function DeploymentDetail({ job }: { job: DeploymentJob }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const [resolving, setResolving] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  const [outcome, setOutcome] = useState<'completed' | 'failed'>('failed')
  const resolve = useResolveDeployment()
  const cancel = useCancelDeployment()
  const cancellable = job.state === 'queued' || job.state === 'requested' || job.state === 'awaiting_approval'

  return (
    <Card>
      <div className="flex flex-col gap-3" aria-label={job.server_name ?? job.id}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="technical text-lg font-semibold">{job.server_name ?? job.server_id}</h2>
          <span className="flex items-center gap-2">
            <span className="text-sm">{t(`admin.deployments.kinds.${job.kind}`)}</span>
            <StatusBadge status={job.state} />
          </span>
        </div>

        {job.failure_detail === null ? null : (
          <Alert tone={job.waits_for_somebody ? 'error' : 'warning'}>
            {job.failure_class === null ? job.failure_detail : t('admin.deployments.failure', { class: job.failure_class, detail: job.failure_detail })}
          </Alert>
        )}

        <section aria-labelledby={`steps-${job.id}`}>
          <h3 id={`steps-${job.id}`} className="text-sm font-semibold">{t('admin.deployments.stepsHeading')}</h3>
          {job.steps.length === 0 ? (
            <p className="text-sm text-[var(--text-muted)]">{t('admin.deployments.noSteps')}</p>
          ) : (
            <ol className="technical mt-1 flex flex-col gap-1 text-xs" dir="ltr">
              {job.steps.map((step, index) => (
                <li key={`${step.name}-${index}`}>
                  {step.outcome === 'passed' ? '✓' : step.outcome === 'timed_out' ? '⏱' : '✗'} {step.name}{step.detail === undefined ? '' : ` — ${step.detail}`}
                </li>
              ))}
            </ol>
          )}
        </section>

        <div className="flex flex-wrap gap-2">
          {job.waits_for_somebody ? <Button size="sm" variant="danger" onClick={() => { setResolving(true); }}>{t('admin.deployments.resolve')}</Button> : null}
          {cancellable ? <Button size="sm" variant="ghost" onClick={() => { setCancelling(true); }}>{t('admin.deployments.cancel')}</Button> : null}
        </div>
      </div>

      <ConfirmDialog
        open={resolving}
        title={t('admin.deployments.resolveTitle', { name: job.server_name ?? job.server_id })}
        body={
          <div className="flex flex-col gap-2">
            <p>{t('admin.deployments.resolveBody')}</p>
            <label className="flex items-center gap-2 text-sm">
              <input type="radio" name={`outcome-${job.id}`} checked={outcome === 'completed'} onChange={() => { setOutcome('completed'); }} />
              {t('admin.deployments.resolveCompleted')}
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input type="radio" name={`outcome-${job.id}`} checked={outcome === 'failed'} onChange={() => { setOutcome('failed'); }} />
              {t('admin.deployments.resolveFailed')}
            </label>
          </div>
        }
        evidenceLabel={t('admin.deployments.resolveReason')}
        confirmLabel={t('admin.deployments.resolve')}
        loading={resolve.isPending}
        error={describe(resolve.error)?.message}
        onCancel={() => { setResolving(false); resolve.reset(); }}
        onConfirm={(_phrase, reason) => { resolve.mutate({ id: job.id, outcome, reason }, { onSuccess: () => { setResolving(false); } }); }}
      />

      <ConfirmDialog
        open={cancelling}
        title={t('admin.deployments.cancelTitle', { name: job.server_name ?? job.server_id })}
        body={<p>{t('admin.deployments.cancelBody')}</p>}
        evidenceLabel={t('admin.deployments.cancelReason')}
        confirmLabel={t('admin.deployments.cancel')}
        loading={cancel.isPending}
        error={describe(cancel.error)?.message}
        onCancel={() => { setCancelling(false); cancel.reset(); }}
        onConfirm={(_phrase, reason) => { cancel.mutate({ id: job.id, reason }, { onSuccess: () => { setCancelling(false); } }); }}
      />
    </Card>
  )
}
