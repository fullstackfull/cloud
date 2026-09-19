import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import { useDrift, useReviewDrift, type AdminDrift } from '@/lib/adminQueries'

const SEVERITY_TONES: Record<string, 'danger' | 'warning' | 'info'> = {
  critical: 'danger',
  warning: 'warning',
  info: 'info',
}

/**
 * Where the platform's records and a provider's reality disagree.
 *
 * The reconciler, the severity ladder and the de-duplication were all built
 * and none of it could be looked at: a machine that had gone missing from a
 * hypervisor produced a row nobody would ever read. This is that screen.
 *
 * What it deliberately does not offer is anything that changes a provider.
 * There is no "delete the orphan" and no button that makes a red row green —
 * a screen full of findings is exactly where a one-click remedy gets pressed
 * on the wrong row. An operator can say "seen", or say "no longer true" and
 * write down why.
 */
export function AdminDriftPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const [openOnly, setOpenOnly] = useState(true)
  const [resolving, setResolving] = useState<AdminDrift | null>(null)

  const { data, isPending, error } = useDrift(page, openOnly ? 'open' : '')
  const review = useReviewDrift()

  const columns: Array<Column<AdminDrift>> = [
    {
      key: 'what',
      header: t('admin.drift.what'),
      cell: (drift) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">
            {t(`admin.drift.kinds.${drift.kind}`, { defaultValue: drift.kind.replace(/_/g, ' ') })}
          </p>
          {/*
            What disagreed, not only who reported it. The kind above says
            "missing at the provider" for a virtual machine and for a hosting
            account alike, and an operator triaging a page of findings needs
            to know which before they open anything.
          */}
          <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
            {drift.resource_type ?? '—'} · {drift.provider ?? '—'}
            {drift.provider_reference === null ? '' : ` · ${drift.provider_reference}`}
          </p>
        </div>
      ),
    },
    {
      key: 'severity',
      header: t('admin.drift.severity'),
      cell: (drift) => (
        <Badge tone={SEVERITY_TONES[drift.severity] ?? 'neutral'}>
          {t(`admin.drift.severities.${drift.severity}`, { defaultValue: drift.severity })}
        </Badge>
      ),
    },
    {
      key: 'status',
      header: t('admin.drift.status'),
      cell: (drift) => (
        <span className="text-sm">
          {t(`admin.drift.statuses.${drift.status}`, { defaultValue: drift.status })}
        </span>
      ),
    },
    {
      key: 'seen',
      header: t('admin.drift.seen'),
      ltr: true,
      cell: (drift) => (
        <div className="text-xs">
          <p className="technical">{drift.occurrences}×</p>
          <p className="text-[var(--text-muted)]">
            {drift.last_seen_at === null ? '—' : formatDateTime(drift.last_seen_at, locale)}
          </p>
        </div>
      ),
    },
    {
      key: 'sides',
      header: t('admin.drift.sides'),
      ltr: true,
      cell: (drift) => (
        <div className="technical flex flex-col gap-0.5 text-xs">
          <span className="text-[var(--text-muted)]">
            {t('admin.drift.expected')}: {JSON.stringify(drift.expected ?? {})}
          </span>
          <span className="text-[var(--text-muted)]">
            {t('admin.drift.observed')}: {JSON.stringify(drift.observed ?? {})}
          </span>
        </div>
      ),
    },
    {
      key: 'verdict',
      header: t('admin.drift.verdict'),
      cell: (drift) =>
        drift.status === 'resolved' ? (
          <span className="text-xs text-[var(--text-muted)]">{drift.resolution ?? '—'}</span>
        ) : (
          <div className="flex gap-2">
            <Button
              size="sm"
              variant="ghost"
              loading={review.isPending}
              onClick={() => { review.mutate({ id: drift.id, verdict: 'acknowledged' }); }}
            >
              {t('admin.drift.acknowledge')}
            </Button>
            <Button size="sm" variant="secondary" onClick={() => { setResolving(drift); }}>
              {t('admin.drift.resolve')}
            </Button>
          </div>
        ),
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.drift.title')} description={t('admin.drift.subtitle')} />

      <LoadFailure error={error} />

      <div className="mb-4">
        <Button
          variant={openOnly ? 'primary' : 'ghost'}
          onClick={() => { setOpenOnly((value) => ! value); setPage(1); }}
        >
          {t('admin.drift.openOnly')}
        </Button>
      </div>

      <Card>
        {isPending ? (
          <Loading />
        ) : (
          <>
            <DataTable
              caption={t('admin.drift.title')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(drift) => drift.id}
              empty={t('admin.drift.empty')}
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
        open={resolving !== null}
        title={t('admin.drift.resolveTitle')}
        body={<p>{t('admin.drift.resolveBody')}</p>}
        evidenceLabel={t('admin.drift.resolution')}
        evidenceHint={t('admin.drift.resolutionHint')}
        confirmLabel={t('admin.drift.resolve')}
        loading={review.isPending}
        error={review.error === null ? undefined : review.error.message}
        onCancel={() => { setResolving(null); review.reset(); }}
        onConfirm={(_phrase, resolution) => {
          if (resolving === null) return

          review.mutate(
            { id: resolving.id, verdict: 'resolved', resolution },
            { onSuccess: () => { setResolving(null); } },
          )
        }}
      />
    </>
  )
}
