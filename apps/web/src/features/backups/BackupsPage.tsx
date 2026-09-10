import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatBytes, formatDate } from '@/lib/format'
import {
  useBackupFileRestores,
  useBackupFiles,
  useBackups,
  useCreateBackup,
  useDeleteBackup,
  useIssueBackupFileDownload,
  useKeepBackup,
  useRestoreBackup,
  useRestoreBackupFiles,
  useVirtualMachines,
} from '@/lib/queries'
import type { Backup, BackupFileEntry, VirtualMachine } from '@/lib/types'
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
  const [deleting, setDeleting] = useState<Backup | null>(null)
  const [browsing, setBrowsing] = useState<Backup | null>(null)

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
  const remove = useDeleteBackup()
  const keep = useKeepBackup()

  // Both are DisplayableError | null: a translated message, the request id to
  // quote to support, and any field errors. Rendering the object itself is
  // what a component test caught here.
  const createFailure = describeError(create.error)
  const restoreFailure = describeError(restore.error)
  const removeFailure = describeError(remove.error)
  const keepFailure = describeError(keep.error)

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
      key: 'kept',
      header: t('backups.kept'),
      cell: (b) => (
        /*
         * What retention has decided about this row, in one place. A pending
         * deletion outranks an expiry date: once one has been asked for, when
         * the sweep *would* have taken it is no longer the fact that matters.
         */
        <span className="text-xs text-[var(--text-muted)]">
          {b.is_being_deleted
            ? t('backups.deletionRequestedOn', {
                date: formatDate(b.deletion_requested_at ?? b.created_at, locale),
              })
            : b.deleted_at !== null
              ? t('backups.deleted')
              : b.protected_until !== null
                ? t('backups.keptUntil', { date: formatDate(b.protected_until, locale) })
                : b.expires_at !== null
                  ? t('backups.expires', { date: formatDate(b.expires_at, locale) })
                  : '—'}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      cell: (b) => (
        <div className="flex justify-end gap-1">
          {/*
            * Disabled with the reason as its title when the provider cannot
            * open the archive: a button that is there and says why beats one
            * that is missing and leaves the customer wondering.
            */}
          <Button
            size="sm"
            variant="ghost"
            disabled={! b.files.supported}
            title={b.files.supported ? undefined : (b.files.reason ?? t('backups.filesUnsupported'))}
            onClick={() => { setBrowsing(b); }}
          >
            {t('backups.files')}
          </Button>

          <Button
            size="sm"
            variant="ghost"
            disabled={! b.is_restorable}
            onClick={() => { setRestoring(b); }}
          >
            {t('backups.restore')}
          </Button>

          {/*
            * Keep and Delete are never both offered for the same row: one is
            * for a deletion that has been asked for and not yet acted on, the
            * other for a backup nobody has asked to remove. Showing both would
            * be offering to undo something that is not happening.
            */}
          {b.is_being_deleted ? (
            <Button
              size="sm"
              variant="ghost"
              loading={keep.isPending && keep.variables.backupId === b.id}
              onClick={() => {
                if (selected === null) return
                keep.mutate({ vmId: selected, backupId: b.id })
              }}
            >
              {t('backups.keep')}
            </Button>
          ) : (
            <Button
              size="sm"
              variant="ghost"
              disabled={b.deleted_at !== null || b.is_in_flight}
              onClick={() => { setDeleting(b); }}
            >
              {t('backups.delete')}
            </Button>
          )}
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.backups')} description={t('backups.subtitle')} />

      <LoadFailure error={machinesError ?? readError} />

      {! machinesPending && rows.length === 0 ? (
        <EmptyState>
          {t('backups.noMachines')} {t('backups.noMachinesHint')}
        </EmptyState>
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

          {/*
            * Keeping a backup has no dialog of its own — it is the safe
            * direction — so its refusals have nowhere else to be said. A
            * grace period that has already run out is exactly the case a
            * customer needs told.
            */}
          {keepFailure === null ? null : (
            <div className="mb-3">
              <Alert tone="error" requestId={keepFailure.requestId}>
                {keepFailure.message}
              </Alert>
            </div>
          )}

          {isPending ? (
            <Loading />
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

      {browsing === null || selected === null || machine === undefined ? null : (
        <BackupFileBrowser
          key={browsing.id}
          vmId={selected}
          machine={machine}
          backup={browsing}
          onClose={() => { setBrowsing(null) }}
        />
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
        /*
         * Spread rather than passed as undefined. Under
         * exactOptionalPropertyTypes an optional prop must be absent, not
         * present-and-undefined — and the distinction is real here: a
         * ConfirmDialog with no requiredPhrase confirms on one click, so
         * "I could not find the machine" must never quietly become "no
         * confirmation needed".
         */
        {...(machine === undefined ? {} : { requiredPhrase: machine.hostname })}
        requiredPhraseLabel={t('backups.restoreConfirmLabel', { hostname: machine?.hostname ?? '' })}
        confirmLabel={t('backups.restore')}
        loading={restore.isPending}
        {...(restoreFailure === null ? {} : { error: restoreFailure.message })}
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

      {deleting === null ? null : (
      <ConfirmDialog
        open
        title={t('backups.deleteTitle')}
        body={
          <>
            <p>{t('backups.deleteWarning', { hostname: machine?.hostname ?? '' })}</p>
            <p className="technical mt-2 text-xs break-all select-all" dir="ltr">
              {deleting.id}
            </p>
          </>
        }
        /*
         * The archive's own reference, typed. The server compares the same
         * string against the row named in the URL, so a customer who deletes
         * from the wrong open tab is refused rather than obeyed.
         */
        requiredPhrase={deleting.id}
        requiredPhraseLabel={t('backups.deleteConfirmLabel')}
        confirmLabel={t('backups.delete')}
        loading={remove.isPending}
        {...(removeFailure === null ? {} : { error: removeFailure.message })}
        onCancel={() => {
          setDeleting(null)
          remove.reset()
        }}
        onConfirm={(confirmation) => {
          if (selected === null) return

          remove.mutate(
            { vmId: selected, backupId: deleting.id, confirmation },
            { onSuccess: () => { setDeleting(null); } },
          )
        }}
      />
      )}
    </>
  )
}

const KIND_TONES: Record<BackupFileEntry['kind'], 'neutral' | 'info' | 'warning'> = {
  file: 'neutral',
  directory: 'info',
  symlink: 'warning',
  other: 'neutral',
}

/**
 * One backup, opened file by file.
 *
 * Three things a customer can do here, in rising order of consequence:
 * look, take one file away, put some files back. The first two are reads
 * of the archive. The third replaces what the server holds at those paths,
 * so it clears the same bar as a whole-machine restore — the hostname typed
 * back — and says what it replaces before it does.
 *
 * A symlink is listed with its kind and offered for nothing: not opened,
 * not downloaded, not selected. The server refuses it too; the screen
 * simply does not pretend.
 *
 * A download link is opened rather than displayed. It is a one-time
 * credential: rendering it as text leaves it in the DOM for anything that
 * can read the page.
 */
function BackupFileBrowser({
  vmId,
  machine,
  backup,
  onClose,
}: {
  vmId: string
  machine: VirtualMachine
  backup: Backup
  onClose: () => void
}) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [path, setPath] = useState('/')
  const [chosen, setChosen] = useState<string[]>([])
  const [confirming, setConfirming] = useState(false)

  const { data, isPending, error: listError } = useBackupFiles(vmId, backup.id, path)
  const { data: restores } = useBackupFileRestores(vmId, backup.id)
  const download = useIssueBackupFileDownload()
  const restore = useRestoreBackupFiles()

  const listFailure = describeError(listError)
  const downloadFailure = describeError(download.error)
  const restoreFailure = describeError(restore.error)

  const listing = data?.data

  const toggle = (entry: BackupFileEntry) => {
    setChosen((current) =>
      current.includes(entry.path) ? current.filter((p) => p !== entry.path) : [...current, entry.path],
    )
  }

  async function fetchFile(entry: BackupFileEntry) {
    const issued = await download.mutateAsync({ vmId, backupId: backup.id, path: entry.path }).catch(() => null)

    if (issued !== null && issued.data.url !== '') {
      window.open(issued.data.url, '_blank', 'noopener,noreferrer')
    }
  }

  const columns: Array<Column<BackupFileEntry>> = [
    {
      key: 'select',
      header: '',
      cell: (e) => (
        <input
          type="checkbox"
          aria-label={t('backups.browser.select', { name: e.name })}
          disabled={! e.restorable}
          checked={chosen.includes(e.path)}
          onChange={() => { toggle(e) }}
        />
      ),
    },
    {
      key: 'name',
      header: t('backups.browser.name'),
      ltr: true,
      cell: (e) =>
        e.browsable ? (
          <button
            type="button"
            className="technical text-xs underline break-all"
            onClick={() => { setPath(e.path) }}
          >
            {e.name}/
          </button>
        ) : (
          <span className="technical text-xs break-all">{e.name}</span>
        ),
    },
    {
      key: 'kind',
      header: t('backups.browser.kind'),
      cell: (e) => <Badge tone={KIND_TONES[e.kind]}>{t(`backups.browser.kinds.${e.kind}`)}</Badge>,
    },
    {
      key: 'size',
      header: t('backups.browser.size'),
      ltr: true,
      cell: (e) => (
        <span className="technical text-xs">{e.size_bytes === null ? '—' : formatBytes(e.size_bytes, locale)}</span>
      ),
    },
    {
      key: 'actions',
      header: '',
      cell: (e) =>
        e.downloadable ? (
          <div className="flex justify-end">
            <Button
              size="sm"
              variant="ghost"
              loading={download.isPending && download.variables.path === e.path}
              onClick={() => { void fetchFile(e) }}
            >
              {t('backups.browser.download')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <Card>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-sm font-medium">
            {t('backups.browser.title', { date: formatDate(backup.finished_at ?? backup.created_at, locale) })}
          </h2>
          <p className="mt-1 max-w-prose text-sm text-[var(--text-muted)]">{t('backups.browser.explainer')}</p>
        </div>
        <Button size="sm" variant="ghost" onClick={onClose}>
          {t('backups.browser.close')}
        </Button>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <span className="technical text-xs break-all" dir="ltr" data-testid="backup-file-path">
          {path}
        </span>
        {listing?.parent === null || listing?.parent === undefined ? null : (
          <Button size="sm" variant="secondary" onClick={() => { setPath(listing.parent ?? '/') }}>
            {t('backups.browser.up')}
          </Button>
        )}
      </div>

      {listFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={listFailure.requestId}>
            {listFailure.message}
          </Alert>
        </div>
      )}

      {downloadFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={downloadFailure.requestId}>
            {downloadFailure.message}
          </Alert>
        </div>
      )}

      <div className="mt-3">
        {isPending ? (
          <Loading className="py-6" />
        ) : (
          <DataTable
            caption={t('backups.files')}
            columns={columns}
            rows={listing?.entries ?? []}
            rowKey={(e) => e.path}
            empty={t('backups.browser.empty')}
          />
        )}
      </div>

      {listing?.truncated === true ? (
        <p className="mt-2 text-xs text-[var(--text-muted)]">{t('backups.browser.truncated')}</p>
      ) : null}

      <p className="mt-2 text-xs text-[var(--text-muted)]">{t('backups.browser.downloadHint')}</p>

      <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-[var(--border-subtle)] pt-4">
        <span className="text-sm text-[var(--text-muted)]">{t('backups.browser.selected', { count: chosen.length })}</span>
        <Button disabled={chosen.length === 0} onClick={() => { setConfirming(true) }}>
          {t('backups.browser.restoreSelected')}
        </Button>
      </div>

      {restoreFailure === null || confirming ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={restoreFailure.requestId}>
            {restoreFailure.message}
          </Alert>
        </div>
      )}

      <h3 className="mt-5 text-sm font-medium">{t('backups.browser.restores')}</h3>
      {restores === undefined || restores.data.length === 0 ? (
        <p className="mt-1 text-sm text-[var(--text-muted)]">{t('backups.browser.noRestores')}</p>
      ) : (
        <ul className="mt-2 flex flex-col gap-2">
          {restores.data.map((r) => (
            <li key={r.id} className="flex flex-col gap-1 text-sm" data-testid="file-restore-row">
              <div className="flex flex-wrap items-center gap-2">
                <StatusBadge status={r.state} />
                <span className="text-[var(--text-muted)]">
                  {t('backups.browser.restoreRow', { count: r.path_count, date: formatDate(r.created_at, locale) })}
                </span>
              </div>
              <span className="technical text-xs break-all" dir="ltr">
                {r.paths.join('  ')}
              </span>
              {r.failure_reason === null || r.needs_attention ? null : <span className="text-xs">{r.failure_reason}</span>}
              {r.needs_attention ? <Alert tone="warning">{t('backups.browser.needsReview')}</Alert> : null}
            </li>
          ))}
        </ul>
      )}

      {confirming ? (
        <ConfirmDialog
          open
          title={t('backups.browser.restoreTitle', { hostname: machine.hostname })}
          body={
            <>
              <p>{t('backups.browser.restoreWarning', { hostname: machine.hostname })}</p>
              <ul className="technical mt-2 flex flex-col gap-0.5 text-xs break-all" dir="ltr">
                {chosen.map((p) => (
                  <li key={p}>{p}</li>
                ))}
              </ul>
            </>
          }
          requiredPhrase={machine.hostname}
          requiredPhraseLabel={t('backups.browser.restoreConfirmLabel', { hostname: machine.hostname })}
          confirmLabel={t('backups.browser.restore')}
          loading={restore.isPending}
          {...(restoreFailure === null ? {} : { error: restoreFailure.message })}
          onCancel={() => {
            setConfirming(false)
            restore.reset()
          }}
          onConfirm={(confirmation) => {
            restore.mutate(
              { vmId, backupId: backup.id, paths: chosen, confirmation },
              {
                onSuccess: () => {
                  setConfirming(false)
                  setChosen([])
                },
              },
            )
          }}
        />
      ) : null}
    </Card>
  )
}
