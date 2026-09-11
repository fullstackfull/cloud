import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { FileField } from '@/components/FileField'
import { SelectField } from '@/components/SelectField'
import { TextareaField } from '@/components/TextareaField'
import {
  useApplyZoneImport,
  useExportZone,
  usePlanZoneImport,
} from '@/lib/queries'
import type {
  DnsZone,
  ZoneChangeKind,
  ZoneExport,
  ZoneImportEntry,
  ZoneImportMode,
  ZoneImportPlan,
} from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/** The server refuses anything over this; the screen says so before uploading it. */
const MAX_ZONE_FILE_BYTES = 262_144

const KIND_TONES: Record<ZoneChangeKind, 'success' | 'info' | 'danger' | 'neutral' | 'warning'> = {
  add: 'success',
  update: 'info',
  remove: 'danger',
  unchanged: 'neutral',
  refused: 'danger',
  ignored: 'warning',
}

/**
 * A zone in and out as a file.
 *
 * The import is two requests on purpose: a preview that writes nothing and a
 * confirmation that applies exactly what was previewed. The apply carries the
 * preview's fingerprint, so a zone that changed in between — a record added
 * from another tab — is refused with "preview again" rather than applied
 * against a state nobody saw. A plan with one refused line applies nothing:
 * the line is shown with the reason, and the button stays off until the file
 * is fixed. Replace mode is opt-in and says what it removes before it does.
 */
export function ZoneTransfer({ zone }: { zone: DnsZone }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const plan = usePlanZoneImport()
  const apply = useApplyZoneImport()
  const exporter = useExportZone()

  const [text, setText] = useState('')
  const [mode, setMode] = useState<ZoneImportMode>('merge')
  const [fileTooLarge, setFileTooLarge] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [exported, setExported] = useState<ZoneExport | null>(null)
  const [copied, setCopied] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const planFailure = describeError(plan.error)
  const applyFailure = describeError(apply.error)
  const exportFailure = describeError(exporter.error)

  const previewed: ZoneImportPlan | null = plan.data?.data ?? null
  const result = apply.data?.data ?? null

  // The mode is part of the plan: a merge previewed and then applied as a
  // replace would remove records the customer never saw listed.
  const stale = previewed !== null && previewed.mode !== mode

  const readFile = (file: File | undefined) => {
    setFileTooLarge(false)
    if (file === undefined) return

    if (file.size > MAX_ZONE_FILE_BYTES) {
      setFileTooLarge(true)
      return
    }

    const reader = new FileReader()
    reader.onload = () => {
      setText(typeof reader.result === 'string' ? reader.result : '')
      plan.reset()
      apply.reset()
    }
    reader.readAsText(file)
  }

  const columns: Array<Column<ZoneImportEntry>> = [
    {
      key: 'kind',
      header: t('dns.import.change'),
      cell: (e) => <Badge tone={KIND_TONES[e.kind]}>{t(`dns.import.kinds.${e.kind}`)}</Badge>,
    },
    {
      key: 'line',
      header: t('dns.import.line'),
      ltr: true,
      cell: (e) => <span className="technical text-xs">{e.line ?? '—'}</span>,
    },
    {
      key: 'record',
      header: t('dns.import.record'),
      ltr: true,
      cell: (e) => (
        <span className="technical text-xs break-all">
          {e.type === null
            ? e.content
            : `${e.name ?? ''} ${e.type} ${e.priority === null ? '' : `${e.priority} `}${e.content ?? ''}`}
        </span>
      ),
    },
    {
      key: 'reason',
      header: t('dns.import.reason'),
      cell: (e) => <span className="text-xs">{e.reason ?? ''}</span>,
    },
  ]

  return (
    <Card>
      <h2 className="text-sm font-medium">{t('dns.import.title')}</h2>
      <p className="mt-1 text-sm text-[var(--text-muted)]">{t('dns.import.explainer')}</p>

      <form
        className="mt-4 flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          if (text.trim() === '') return

          apply.reset()
          plan.mutate({ zoneId: zone.id, text, mode })
        }}
      >
        <FileField
          ref={fileInput}
          label={t('dns.import.file')}
          hint={t('dns.import.fileHint')}
          accept=".zone,.txt,.db,text/plain"
          onChange={(event) => { readFile(event.target.files?.[0]) }}
        />

        {fileTooLarge ? <Alert tone="error">{t('dns.import.tooLarge')}</Alert> : null}

        {/*
          A zone file is a technical value, so it stays left to right even on
          an Arabic page: the records in it are not prose.
        */}
        <TextareaField
          label={t('dns.import.text')}
          dir="ltr"
          spellCheck={false}
          rows={10}
          className="technical text-xs"
          value={text}
          onChange={(event) => {
            setText(event.target.value)
            plan.reset()
            apply.reset()
          }}
        />

        <div className="flex flex-wrap items-end gap-3">
          <SelectField
            label={t('dns.import.mode')}
            value={mode}
            onChange={(event) => { setMode(event.target.value as ZoneImportMode) }}
            options={(['merge', 'replace'] as const).map((option) => ({
              value: option,
              label: t(`dns.import.modes.${option}`),
            }))}
          />
          <p className="max-w-prose text-xs text-[var(--text-muted)]">{t(`dns.import.modeHint.${mode}`)}</p>

          <Button type="submit" variant="secondary" loading={plan.isPending} disabled={text.trim() === ''}>
            {t('dns.import.preview')}
          </Button>
        </div>
      </form>

      {planFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={planFailure.requestId}>
            {planFailure.message}
          </Alert>
        </div>
      )}

      {previewed === null || result !== null ? null : (
        <div className="mt-4 border-t border-[var(--border-subtle)] pt-4" data-testid="zone-import-plan">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h3 className="text-sm font-medium">{t('dns.import.planTitle')}</h3>
            <ul className="flex flex-wrap gap-2 text-xs text-[var(--text-muted)]">
              {(['add', 'update', 'remove', 'unchanged', 'refused', 'ignored', 'kept'] as const).map((key) => (
                <li key={key} className="technical">
                  {previewed.counts[key]} {t(`dns.import.counts.${key}`)}
                </li>
              ))}
            </ul>
          </div>

          {previewed.applicable ? null : (
            <div className="mt-3">
              <Alert tone="error">{t('dns.import.notApplicable')}</Alert>
            </div>
          )}

          <div className="mt-3">
            <DataTable
              caption={t('dns.import.planTitle')}
              columns={columns}
              rows={previewed.entries}
              rowKey={(e) => `${e.kind}-${e.line ?? 'plan'}-${e.name ?? ''}-${e.type ?? ''}-${e.content ?? ''}`}
              empty={t('dns.import.empty')}
            />
          </div>

          <div className="mt-3">
            <Button
              disabled={! previewed.applicable || stale || (previewed.counts.add + previewed.counts.update + previewed.counts.remove) === 0}
              onClick={() => { setConfirming(true) }}
            >
              {t('dns.import.apply')}
            </Button>
          </div>
        </div>
      )}

      {applyFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={applyFailure.requestId}>
            {applyFailure.message}
          </Alert>
        </div>
      )}

      {result === null ? null : (
        <div className="mt-3">
          <Alert tone="success">
            {t('dns.import.applied', {
              added: result.added,
              updated: result.updated,
              removed: result.removed,
              unchanged: result.unchanged,
            })}
          </Alert>
        </div>
      )}

      <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-[var(--border-subtle)] pt-4">
        <Button
          variant="secondary"
          loading={exporter.isPending}
          onClick={() => {
            setCopied(false)
            exporter.mutate(zone.id, { onSuccess: (response) => { setExported(response.data) } })
          }}
        >
          {t('dns.export.action')}
        </Button>

        {exported === null ? null : (
          <>
            <span className="text-xs text-[var(--text-muted)]">
              {t('dns.export.ready', { count: exported.record_count })}
            </span>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                void navigator.clipboard.writeText(exported.content).then(() => { setCopied(true) })
              }}
            >
              {copied ? t('dns.export.copied') : t('dns.export.copy')}
            </Button>
            <a
              className="text-sm underline"
              download={exported.filename}
              href={`data:text/plain;charset=utf-8,${encodeURIComponent(exported.content)}`}
            >
              {t('dns.export.download', { filename: exported.filename })}
            </a>
          </>
        )}
      </div>

      {exportFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={exportFailure.requestId}>
            {exportFailure.message}
          </Alert>
        </div>
      )}

      {exported === null ? null : (
        <pre
          dir="ltr"
          data-testid="zone-export"
          className="technical mt-3 max-h-72 overflow-auto rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-3 text-xs"
        >
          {exported.content}
        </pre>
      )}

      {confirming && previewed !== null ? (
        <ConfirmDialog
          open
          title={t('dns.import.applyTitle', { zone: zone.name })}
          body={t('dns.import.applyBody', {
            add: previewed.counts.add,
            update: previewed.counts.update,
            remove: previewed.counts.remove,
          })}
          requiredPhrase={zone.name}
          requiredPhraseLabel={t('dns.import.applyConfirmLabel', { zone: zone.name })}
          confirmLabel={t('dns.import.apply')}
          loading={apply.isPending}
          {...(applyFailure === null ? {} : { error: applyFailure.message })}
          onCancel={() => {
            setConfirming(false)
            apply.reset()
          }}
          onConfirm={() => {
            apply.mutate(
              { zoneId: zone.id, text, mode, fingerprint: previewed.fingerprint },
              {
                onSuccess: () => {
                  setConfirming(false)
                  setText('')
                  if (fileInput.current !== null) fileInput.current.value = ''
                },
              },
            )
          }}
        />
      ) : null}
    </Card>
  )
}
