import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import { useWatchOperations } from '@/features/operations/watchChannel'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDate } from '@/lib/format'
import {
  useBackups,
  useCreateBackup,
  useDeleteBackup,
  useKeepBackup,
  useRestoreBackup,
} from '@/lib/queries'
import type { Backup, VirtualMachine } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

import { BackupFileBrowser } from './BackupFileBrowser'

/**
 * One machine's backups: the archives, and the four things a customer does
 * with one.
 *
 * Extracted in Wave 3 so that the machine's own page and the cross-machine
 * `/backups` screen are the same implementation. The audit's finding was that
 * backups were unreachable from the thing they protect; the risk in fixing it
 * was two screens with two copies of a restore dialogue, which is how one of
 * them ends up missing the typed confirmation. There is one copy, and the
 * global page is now a machine picker in front of it.
 *
 * Sizes are shown in GiB from bytes rather than through a locale number
 * helper: what matters is that "2 GiB" reads the same in both languages.
 */
export function BackupsForMachine({ vm }: { vm: VirtualMachine }) {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const [restoring, setRestoring] = useState<Backup | null>(null)
  const [deleting, setDeleting] = useState<Backup | null>(null)
  const [browsing, setBrowsing] = useState<Backup | null>(null)

  const { data, isPending, error: readError } = useBackups(vm.id, page)

  const create = useCreateBackup()
  const { acknowledge } = useWatchOperations()
  const restore = useRestoreBackup()
  const remove = useDeleteBackup()
  const keep = useKeepBackup()

  // Each is DisplayableError | null: a translated message, the request id to
  // quote to support, and any field errors. Rendering the object itself is
  // what a component test caught here once.
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
            disabled={!b.files.supported}
            title={b.files.supported ? undefined : (b.files.reason ?? t('backups.filesUnsupported'))}
            onClick={() => { setBrowsing(b); }}
          >
            {t('backups.files')}
          </Button>

          <Button
            size="sm"
            variant="ghost"
            disabled={!b.is_restorable}
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
              onClick={() => { keep.mutate({ vmId: vm.id, backupId: b.id }); }}
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
      <Card
        title={t('nav.backups')}
        description={t('backups.forMachine', { hostname: vm.hostname })}
        actions={
          <Button
            loading={create.isPending}
            onClick={() => {
              create.mutate(
                { vmId: vm.id },
                {
                  /*
                   * Acknowledged rather than watched. A backup's progress is a
                   * property of the backup row, which this list reads and the
                   * mutation invalidates; there is no provisioning operation
                   * behind it to poll. "Backup requested" is the truth, and it
                   * is more than the silence there was before.
                   */
                  onSuccess: () => {
                    acknowledge(`backup:${vm.id}`, { actionKey: 'operations.actions.backup' })
                  },
                },
              )
            }}
          >
            {t('backups.takeOne')}
          </Button>
        }
      >
        <LoadFailure error={readError} />

        {createFailure === null ? null : (
          <div className="mb-3">
            <Alert tone="error" requestId={createFailure.requestId}>
              {createFailure.message}
            </Alert>
          </div>
        )}

        {/*
          * Keeping a backup has no dialogue of its own — it is the safe
          * direction — so its refusals have nowhere else to be said. A grace
          * period that has already run out is exactly the case a customer
          * needs told.
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

      {browsing === null ? null : (
        <BackupFileBrowser
          key={browsing.id}
          vmId={vm.id}
          machine={vm}
          backup={browsing}
          onClose={() => { setBrowsing(null); }}
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
          body={t('backups.restoreWarning', { hostname: vm.hostname })}
          requiredPhrase={vm.hostname}
          requiredPhraseLabel={t('backups.restoreConfirmLabel', { hostname: vm.hostname })}
          confirmLabel={t('backups.restore')}
          loading={restore.isPending}
          {...(restoreFailure === null ? {} : { error: restoreFailure.message })}
          onCancel={() => {
            setRestoring(null)
            restore.reset()
          }}
          onConfirm={(confirmation) => {
            restore.mutate(
              { vmId: vm.id, backupId: restoring.id, confirmation },
              {
                onSuccess: () => {
                  acknowledge(`restore:${restoring.id}`, {
                    actionKey: 'operations.actions.restore',
                  })

                  setRestoring(null)
                },
              },
            )
          }}
        />
      )}

      {deleting === null ? null : (
        <ConfirmDialog
          open
          title={t('backups.deleteTitle')}
          body={
            <div className="flex flex-col gap-2">
              <p>{t('backups.deleteWarning', { hostname: vm.hostname })}</p>

              {/*
                * Which archive, in terms a person can check: when it was
                * taken, how big it is, and whether it has been proven
                * restorable. It used to be a twenty-six character ULID, and
                * the phrase below used to be that same ULID — an identifier
                * nobody can verify by reading it, standing in for intent.
                */}
              <div className="rounded-lg border border-[var(--border-subtle)] p-3 text-sm">
                <p>
                  {t('backups.taken')}: {formatDate(deleting.finished_at ?? deleting.created_at, locale)}
                </p>
                <p>
                  {t('backups.size')}:{' '}
                  <span className="technical" dir="ltr">
                    {deleting.size_bytes === null
                      ? '—'
                      : `${(deleting.size_bytes / 1024 ** 3).toFixed(1)} GiB`}
                  </span>
                </p>
                <p>
                  {t('backups.verified')}:{' '}
                  {deleting.verified === true ? t('backups.verifiedYes') : t('backups.verifiedNo')}
                </p>
              </div>
            </div>
          }
          /*
           * The machine's hostname, typed. Which archive is destroyed is
           * settled by the row the customer clicked; this establishes that a
           * person meant to destroy a copy of that machine's data. The server
           * compares the same string.
           */
          requiredPhrase={vm.hostname}
          requiredPhraseLabel={t('backups.deleteConfirmLabel', { hostname: vm.hostname })}
          confirmLabel={t('backups.delete')}
          loading={remove.isPending}
          {...(removeFailure === null ? {} : { error: removeFailure.message })}
          onCancel={() => {
            setDeleting(null)
            remove.reset()
          }}
          onConfirm={(confirmation) => {
            remove.mutate(
              { vmId: vm.id, backupId: deleting.id, confirmation },
              { onSuccess: () => { setDeleting(null); } },
            )
          }}
        />
      )}
    </>
  )
}
