import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import { useBackups, useCreateBackup, useRestoreBackup, useVirtualMachines } from '@/lib/queries'
import type { Backup, VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * A customer's backups, per machine.
 *
 * The machine picker is not decoration. The API nests backups under a machine
 * because a backup is *of* something, and this screen keeps that shape rather
 * than presenting one flat list across an account — a flat list is where a
 * customer with two servers restores the wrong one.
 *
 * Sizes are shown in GiB from bytes rather than formatted by a locale number
 * helper: what matters is that "2 GiB" reads the same in both languages.
 */
export function BackupsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data: machines, isPending: machinesPending, error: machinesError } = useVirtualMachines(1)
  const [selected, setSelected] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [restoring, setRestoring] = useState<Backup | null>(null)

  const rows = machines?.data ?? []

  useEffect(() => {
    // Select the first machine once, and re-select if the current one
    // disappears — a machine that was terminated while this page was open.
    if (rows.length === 0) return
    if (selected !== null && rows.some((vm) => vm.id === selected)) return

    setSelected(rows[0]?.id ?? null)
    setPage(1)
  }, [rows, selected])

  const machine: VirtualMachine | undefined = rows.find((vm) => vm.id === selected)
  const { data, isPending, error: readError } = useBackups(selected, page)

  const create = useCreateBackup()
  const restore = useRestoreBackup()

  // Both are DisplayableError | null: a translated message, the request id to
  // quote to support, and any field errors. Rendering the object itself is
  // what a component test caught here.
  const createFailure = describeError(create.error)
  const restoreFailure = describeError(restore.error)

  const columns: Array<Column<Backup>> = [
    {
      key: 'taken',
      header: t('backups.taken'),
      cell: (b) => formatDate(b.finished_at ?? b.created_at, locale),
    },
    { key: 'state', header: t('backups.state'), cell: (b) => <StatusBadge status={b.state} /> },
    {
      key: 'size',
      header: t('backups.size'),
      ltr: true,
      cell: (b) => (
        <span className="technical text-xs">
          {b.size_bytes === null ? '—' : `${(b.size_bytes / 1024 ** 3).toFixed(1)} GiB`}
        </span>
      ),
    },
    {
      key: 'verified',
      header: t('backups.verified'),
      cell: (b) => (
        /*
         * Shown as its own column and never folded into the state badge. A
         * backup that completed and a backup that has been proven restorable
         * are different facts, and the platform must not let the first read
         * as the second.
         */
        <span className="text-xs text-[var(--text-muted)]">
          {b.verified === true ? t('backups.verifiedYes') : t('backups.verifiedNo')}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      cell: (b) => (
        <Button
          size="sm"
          variant="ghost"
          disabled={! b.is_restorable}
          onClick={() => { setRestoring(b); }}
        >
          {t('backups.restore')}
        </Button>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.backups')} description={t('backups.subtitle')} />

      <LoadFailure error={machinesError ?? readError} />

      {! machinesPending && rows.length === 0 ? (
        <EmptyState title={t('backups.noMachines')} description={t('backups.noMachinesHint')} />
      ) : (
        <Card>
          <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium">{t('backups.machine')}</span>
              <select
                value={selected ?? ''}
                onChange={(event) => {
                  setSelected(event.target.value)
                  setPage(1)
                }}
                dir="ltr"
                className="technical h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
              >
                {rows.map((vm) => (
                  <option key={vm.id} value={vm.id}>
                    {vm.hostname}
                  </option>
                ))}
              </select>
            </label>

            <Button
              loading={create.isPending}
              disabled={selected === null}
              onClick={() => { if (selected !== null) create.mutate({ vmId: selected }); }}
            >
              {t('backups.takeOne')}
            </Button>
          </div>

          {createFailure === null ? null : (
            <div className="mb-3">
              <Alert tone="error" requestId={createFailure.requestId}>
                {createFailure.message}
              </Alert>
            </div>
          )}

          {isPending ? (
            <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
          ) : (
            <>
              <DataTable
                caption={t('nav.backups')}
                columns={columns}
                rows={data?.data ?? []}
                rowKey={(b) => b.id}
                empty={t('backups.empty')}
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
      )}

      {/*
        * Mounted only while it is open. A closed <dialog> stays in the
        * document, and its confirm button — disabled, hidden, and also
        * labelled "Restore" — is still a button in the DOM. Anything counting
        * the restore controls on this page finds one more than the customer
        * can see.
        */}
      {restoring === null ? null : (
      <ConfirmDialog
        open
        title={t('backups.restoreTitle')}
        body={t('backups.restoreWarning', { hostname: machine?.hostname ?? '' })}
        // The server compares this too, and is what decides. This copy exists
        // so the customer finds out before the request rather than after.
        requiredPhrase={machine?.hostname}
        requiredPhraseLabel={t('backups.restoreConfirmLabel', { hostname: machine?.hostname ?? '' })}
        confirmLabel={t('backups.restore')}
        loading={restore.isPending}
        error={restoreFailure?.message}
        onCancel={() => {
          setRestoring(null)
          restore.reset()
        }}
        onConfirm={(confirmation) => {
          // Only `selected` needs checking now: the dialog is mounted inside
          // the branch where `restoring` is known to be a backup.
          if (selected === null) return

          restore.mutate(
            { vmId: selected, backupId: restoring.id, confirmation },
            { onSuccess: () => { setRestoring(null); } },
          )
        }}
      />
      )}
    </>
  )
}
