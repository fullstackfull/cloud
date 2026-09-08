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
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useReinstallOperations,
  useResolveReinstall,
  type AdminReinstallOperation,
} from '@/lib/adminQueries'

/**
 * The rebuilds queue.
 *
 * Both reinstall lifecycles wrote a complete record of every phase and neither
 * had a screen, which meant the platform's only deliberately destructive
 * operations were the ones an operator could not see. "Was my server wiped?"
 * was a question answered with SQL.
 *
 * Two things are deliberately prominent. `data_destroyed` is the answer to the
 * question a customer actually rings about, and it is a fact from a timestamp
 * rather than a guess from a state. And the only action offered is a verdict
 * on an operation that is waiting for one — there is no retry here, because a
 * retry of a rebuild is a second rebuild.
 */
export function AdminOperationsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const [page, setPage] = useState(1)
  const [onlyWaiting, setOnlyWaiting] = useState(false)
  const [deciding, setDeciding] = useState<{ operation: AdminReinstallOperation; verdict: 'completed' | 'failed' } | null>(null)

  const { data, isPending, error } = useReinstallOperations(page, onlyWaiting)
  const resolve = useResolveReinstall()

  const columns: Array<Column<AdminReinstallOperation>> = [
    {
      key: 'machine',
      header: t('admin.operations.machine'),
      cell: (operation) => (
        <div>
          <p className="font-medium text-[var(--text-primary)]">
            {t(`admin.operations.types.${operation.type}`, { defaultValue: operation.type })}
          </p>
          <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
            {operation.provider_resource_id ?? operation.subject_id ?? operation.id}
            {operation.provider_node === null ? '' : ` · ${operation.provider_node}`}
          </p>
        </div>
      ),
    },
    {
      key: 'state',
      header: t('admin.operations.state'),
      cell: (operation) => <StatusBadge status={operation.state} />,
    },
    {
      key: 'data',
      header: t('admin.operations.data'),
      cell: (operation) =>
        operation.data_destroyed ? (
          <Badge tone="danger">{t('admin.operations.dataGone')}</Badge>
        ) : (
          <Badge tone="success">{t('admin.operations.dataIntact')}</Badge>
        ),
    },
    {
      key: 'failure',
      header: t('admin.operations.failure'),
      cell: (operation) =>
        operation.failure_code === null ? (
          '—'
        ) : (
          <span className="technical text-xs" dir="ltr">
            {operation.failure_code}
          </span>
        ),
    },
    {
      key: 'requested',
      header: t('admin.operations.requested'),
      cell: (operation) =>
        operation.requested_at === null ? '—' : formatDateTime(operation.requested_at, locale),
    },
    {
      key: 'decide',
      header: t('admin.operations.decide'),
      cell: (operation) =>
        operation.needs_attention ? (
          <div className="flex gap-2">
            <Button
              variant="secondary"
              onClick={() => { setDeciding({ operation, verdict: 'completed' }); }}
            >
              {t('admin.operations.confirm')}
            </Button>
            <Button
              variant="ghost"
              onClick={() => { setDeciding({ operation, verdict: 'failed' }); }}
            >
              {t('admin.operations.abandon')}
            </Button>
          </div>
        ) : (
          '—'
        ),
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.operations.title')} description={t('admin.operations.subtitle')} />

      <LoadFailure error={error} />

      <div className="mb-4">
        <Button
          variant={onlyWaiting ? 'primary' : 'ghost'}
          onClick={() => { setOnlyWaiting((value) => ! value); setPage(1); }}
        >
          {t('admin.operations.onlyWaiting')}
        </Button>
      </div>

      <Card>
        {isPending ? (
          <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : (
          <>
            <DataTable
              caption={t('admin.operations.title')}
              columns={columns}
              rows={data?.data ?? []}
              rowKey={(operation) => `${operation.type}:${operation.id}`}
              empty={t('admin.operations.empty')}
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
        open={deciding !== null}
        title={
          deciding?.verdict === 'failed'
            ? t('admin.operations.abandonTitle')
            : t('admin.operations.confirmTitle')
        }
        body={
          <div className="flex flex-col gap-2">
            <p>
              {deciding?.verdict === 'failed'
                ? t('admin.operations.abandonBody')
                : t('admin.operations.confirmBody')}
            </p>
            {deciding?.operation.failure_message === null ||
            deciding?.operation.failure_message === undefined ? null : (
              <p className="technical text-xs text-[var(--text-muted)]" dir="ltr">
                {deciding.operation.failure_message}
              </p>
            )}
          </div>
        }
        evidenceLabel={t('admin.operations.evidence')}
        evidenceHint={t('admin.operations.evidenceHint')}
        confirmLabel={
          deciding?.verdict === 'failed'
            ? t('admin.operations.abandon')
            : t('admin.operations.confirm')
        }
        loading={resolve.isPending}
        error={resolve.error === null ? undefined : resolve.error.message}
        onCancel={() => { setDeciding(null); resolve.reset(); }}
        onConfirm={(_phrase, evidence) => {
          if (deciding === null) return

          resolve.mutate(
            {
              type: deciding.operation.type,
              id: deciding.operation.id,
              verdict: deciding.verdict,
              evidence,
            },
            { onSuccess: () => { setDeciding(null); } },
          )
        }}
      />
    </>
  )
}
