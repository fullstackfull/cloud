import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/Badge'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import {
  useAdminHostingNodes,
  useAdminNodes,
  type AdminHostingNode,
  type AdminNode,
} from '@/lib/adminQueries'

/**
 * Capacity, as the scheduler sees it.
 *
 * The bars measure allocation against what the placement engine will actually
 * hand out, not against the raw hardware: memory keeps a reserve the hypervisor
 * needs and CPU is overcommitted on purpose. A screen that showed the raw ratio
 * would have an operator believe a node is half empty while the scheduler
 * considers it full.
 */
export function AdminInfrastructurePage() {
  const { t } = useTranslation()
  const [page, setPage] = useState(1)

  const { data: nodes, isPending } = useAdminNodes(page)
  const { data: hosting } = useAdminHostingNodes(1)

  const nodeColumns: Array<Column<AdminNode>> = [
    {
      key: 'node',
      header: t('admin.infrastructure.node'),
      ltr: true,
      cell: (node) => (
        <div>
          <p className="technical font-medium text-[var(--text-primary)]">{node.name}</p>
          <p className="technical text-xs text-[var(--text-muted)]">
            {node.datacenter ?? '—'} / {node.cluster ?? '—'}
          </p>
        </div>
      ),
    },
    {
      key: 'status',
      header: t('admin.infrastructure.status'),
      cell: (node) => (
        <span className="flex items-center gap-2">
          <StatusBadge status={node.status} />
          {node.is_healthy ? null : <Badge tone="danger">{t('admin.infrastructure.unhealthy')}</Badge>}
        </span>
      ),
    },
    {
      key: 'cpu',
      header: t('admin.infrastructure.cpu'),
      ltr: true,
      cell: (node) => <Meter used={node.cpu.allocated} of={node.cpu.allocatable} unit="vCPU" />,
    },
    {
      key: 'memory',
      header: t('admin.infrastructure.memory'),
      ltr: true,
      cell: (node) => (
        <Meter
          used={Math.round(node.memory_mib.allocated / 1024)}
          of={Math.round(node.memory_mib.allocatable / 1024)}
          unit="GiB"
        />
      ),
    },
    { key: 'vms', header: t('admin.infrastructure.machines'), ltr: true, cell: (node) => node.vm_count },
  ]

  const hostingColumns: Array<Column<AdminHostingNode>> = [
    {
      key: 'node',
      header: t('admin.infrastructure.node'),
      ltr: true,
      cell: (node) => <span className="technical font-medium">{node.hostname}</span>,
    },
    {
      key: 'panel',
      header: t('admin.infrastructure.panel'),
      cell: (node) => (
        <span className="flex items-center gap-2">
          <span className="technical">{node.panel}</span>
          {node.panel_licensed ? null : (
            /*
              Licensing is not decoration on this screen: an unlicensed panel is
              one the platform must refuse to provision onto, and an operator
              watching accounts fail to create needs to see the reason here
              rather than in a job's error text.
            */
            <Badge tone="danger">{t('admin.infrastructure.unlicensed')}</Badge>
          )}
        </span>
      ),
    },
    { key: 'status', header: t('admin.infrastructure.status'), cell: (node) => <StatusBadge status={node.status} /> },
    {
      key: 'accounts',
      header: t('admin.infrastructure.accounts'),
      ltr: true,
      cell: (node) => <Meter used={node.account_count} of={node.max_accounts ?? 0} unit="" />,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('admin.infrastructure.title')}
        description={t('admin.infrastructure.subtitle')}
      />

      <div className="flex flex-col gap-4">
        <Card title={t('admin.infrastructure.computeNodes')}>
          {isPending ? (
            <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
          ) : (
            <>
              <DataTable
                caption={t('admin.infrastructure.computeNodes')}
                columns={nodeColumns}
                rows={nodes?.data ?? []}
                rowKey={(node) => node.id}
                empty={t('admin.infrastructure.noNodes')}
              />
              <Paginator
                page={nodes?.meta.page ?? 1}
                lastPage={nodes?.meta.last_page ?? 1}
                total={nodes?.meta.total ?? 0}
                onChange={setPage}
              />
            </>
          )}
        </Card>

        <Card title={t('admin.infrastructure.hostingNodes')}>
          <DataTable
            caption={t('admin.infrastructure.hostingNodes')}
            columns={hostingColumns}
            rows={hosting?.data ?? []}
            rowKey={(node) => node.id}
            empty={t('admin.infrastructure.noHostingNodes')}
          />
        </Card>
      </div>
    </>
  )
}

function Meter({ used, of, unit }: { used: number; of: number; unit: string }) {
  // A zero denominator is "no limit declared", not "completely full". Dividing
  // by it would paint every unlimited node red.
  const percent = of > 0 ? Math.min(100, Math.round((used / of) * 100)) : 0
  const tone = percent >= 90 ? 'bg-red-500' : percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500'

  return (
    <div className="min-w-28">
      <div className="technical mb-1 text-xs text-[var(--text-secondary)]">
        {used} / {of > 0 ? of : '∞'} {unit}
      </div>
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-[var(--surface-sunken)]">
        <div className={`h-full ${tone}`} style={{ width: `${percent}%` }} />
      </div>
    </div>
  )
}
