import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { CheckboxField } from '@/components/CheckboxField'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { Loading } from '@/components/Loading'
import { StatusBadge } from '@/components/StatusBadge'
import { useWatchOperations } from '@/features/operations/watchChannel'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatBytes, formatDate } from '@/lib/format'
import {
  useBackupFileRestores,
  useBackupFiles,
  useIssueBackupFileDownload,
  useRestoreBackupFiles,
} from '@/lib/queries'
import type { Backup, BackupFileEntry, VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

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
export function BackupFileBrowser({
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
  const { acknowledge } = useWatchOperations()

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
        <CheckboxField
          label={t('backups.browser.select', { name: e.name })}
          labelHidden
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
                  acknowledge(`file-restore:${backup.id}`, {
                    actionKey: 'operations.actions.fileRestore',
                  })

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
