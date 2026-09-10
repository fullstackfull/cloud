import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { Loading } from '@/components/Loading'
import {
  useAddDnsRecord,
  useApplyZoneImport,
  useClaimDnsZone,
  useDnsRecords,
  useDnsZones,
  useExportZone,
  usePlanZoneImport,
  useReleaseDnsZone,
  useRemoveDnsRecord,
} from '@/lib/queries'
import type {
  DnsRecord,
  DnsZone,
  ZoneChangeKind,
  ZoneExport,
  ZoneImportEntry,
  ZoneImportMode,
  ZoneImportPlan,
} from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/** The six the platform publishes. A provider accepting SRV is not a reason to offer it. */
const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA'] as const

/**
 * A customer's domains and what they say.
 *
 * The screen is arranged around one fact that is easy to design away: **a zone
 * here serves nothing until the domain is delegated to these nameservers at
 * the registrar.** Nothing on this platform can check that the account owns
 * the domain, and nothing pretends to — so the nameservers are given the most
 * prominent place on the zone, above the records, with the plain sentence that
 * says what has to happen next. A screen that led with a green "active" badge
 * would be telling a customer their domain was working when the platform has
 * no idea whether it is.
 */
export function DnsPage() {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const { data: zones, isPending, error: readError } = useDnsZones()
  const [selected, setSelected] = useState<string | null>(null)
  const [releasing, setReleasing] = useState<DnsZone | null>(null)

  const rows = zones?.data ?? []

  useEffect(() => {
    // Select the first zone once, and re-select if the current one goes — a
    // domain given up while this page was open.
    if (rows.length === 0) return
    if (selected !== null && rows.some((zone) => zone.id === selected)) return

    setSelected(rows[0]?.id ?? null)
  }, [rows, selected])

  const zone = rows.find((row) => row.id === selected)

  const claim = useClaimDnsZone()
  const release = useReleaseDnsZone()

  const [name, setName] = useState('')

  const claimFailure = describeError(claim.error)
  const releaseFailure = describeError(release.error)

  return (
    <>
      <PageHeader title={t('nav.dns')} description={t('dns.subtitle')} />

      <LoadFailure error={readError} />

      <Card>
        <form
          className="flex flex-wrap items-end gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (name.trim() === '') return

            claim.mutate({ name: name.trim() }, { onSuccess: () => { setName('') } })
          }}
        >
          <div className="min-w-[16rem] flex-1">
            <Field
              label={t('dns.domain')}
              dir="ltr"
              placeholder="example.com"
              value={name}
              onChange={(event) => { setName(event.target.value) }}
              hint={t('dns.domainHint')}
            />
          </div>

          <Button type="submit" loading={claim.isPending}>
            {t('dns.claim')}
          </Button>
        </form>

        {claimFailure === null ? null : (
          <div className="mt-3">
            <Alert tone="error" requestId={claimFailure.requestId}>
              {claimFailure.message}
            </Alert>
          </div>
        )}
      </Card>

      {isPending ? (
        <Loading />
      ) : rows.length === 0 ? (
        <EmptyState>{t('dns.noZones')}</EmptyState>
      ) : (
        <div className="mt-4 flex flex-col gap-4">
          <Card>
            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium">{t('dns.zone')}</span>
              <select
                value={selected ?? ''}
                onChange={(event) => { setSelected(event.target.value) }}
                dir="ltr"
                className="technical h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
              >
                {rows.map((row) => (
                  <option key={row.id} value={row.id}>
                    {row.name}
                  </option>
                ))}
              </select>
            </label>
          </Card>

          {zone === undefined ? null : (
            <>
              <ZoneDelegation zone={zone} />

              {releaseFailure === null ? null : (
                <Alert tone="error" requestId={releaseFailure.requestId}>
                  {releaseFailure.message}
                </Alert>
              )}

              <ZoneRecords zone={zone} />

              <ZoneTransfer key={zone.id} zone={zone} />

              <Card>
                <h2 className="text-sm font-medium">{t('dns.releaseTitle')}</h2>
                <p className="mt-1 text-sm text-[var(--text-muted)]">{t('dns.releaseExplainer')}</p>
                <div className="mt-3">
                  <Button
                    variant="danger"
                    disabled={zone.is_being_deleted}
                    onClick={() => { setReleasing(zone); }}
                  >
                    {t('dns.release')}
                  </Button>
                </div>
              </Card>
            </>
          )}
        </div>
      )}

      {/*
        * Mounted only while open. A closed <dialog> stays in the document, and
        * its confirm button — disabled, hidden, and also labelled "Give up" —
        * is still a button anything counting the controls on this page finds.
        */}
      {releasing === null ? null : (
        <ConfirmDialog
          open
          title={t('dns.releaseTitle')}
          body={t('dns.releaseWarning', { zone: releasing.name })}
          requiredPhrase={releasing.name}
          requiredPhraseLabel={t('dns.releaseConfirmLabel', { zone: releasing.name })}
          confirmLabel={t('dns.release')}
          loading={release.isPending}
          {...(releaseFailure === null ? {} : { error: releaseFailure.message })}
          onCancel={() => {
            setReleasing(null)
            release.reset()
          }}
          onConfirm={(confirmation) => {
            release.mutate(
              { zoneId: releasing.id, confirmation },
              { onSuccess: () => { setReleasing(null); setSelected(null); } },
            )
          }}
        />
      )}
    </>
  )
}

/**
 * What has to happen at the registrar, said before anything else.
 *
 * This is the only part of the screen a customer needs on the day they claim a
 * domain, and the part they come back for when their site does not resolve.
 */
function ZoneDelegation({ zone }: { zone: DnsZone }) {
  const { t } = useTranslation()

  return (
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="technical text-base font-medium" dir="ltr">
          {zone.name}
        </h2>
        <StatusBadge status={zone.state} />
      </div>

      <p className="mt-2 text-sm text-[var(--text-muted)]">{t('dns.delegationExplainer')}</p>

      {zone.nameservers.length === 0 ? (
        <p className="mt-3 text-sm text-[var(--text-muted)]">{t('dns.noNameservers')}</p>
      ) : (
        <ul className="mt-3 flex flex-col gap-1">
          {zone.nameservers.map((host) => (
            <li key={host} className="technical text-sm select-all" dir="ltr">
              {host}
            </li>
          ))}
        </ul>
      )}

      {zone.failure_reason === null ? null : (
        <div className="mt-3">
          <Alert tone="error">{zone.failure_reason}</Alert>
        </div>
      )}

      {zone.needs_attention ? (
        <div className="mt-3">
          {/*
            * Not an error and deliberately not styled as one. The zone may be
            * perfectly fine; what the platform is saying is that it does not
            * know, and telling somebody to try again would be the one thing
            * that could make it worse.
            */}
          <Alert tone="warning">{t('dns.needsAttention')}</Alert>
        </div>
      ) : null}
    </Card>
  )
}

function ZoneRecords({ zone }: { zone: DnsZone }) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()

  const { data, isPending } = useDnsRecords(zone.id)
  const add = useAddDnsRecord()
  const remove = useRemoveDnsRecord()

  const [type, setType] = useState<string>('A')
  const [name, setName] = useState('')
  const [content, setContent] = useState('')
  const [priority, setPriority] = useState('10')
  const [caaTag, setCaaTag] = useState('issue')

  /*
   * The record about to go. Held as the whole row so the dialogue can name
   * it — type, name and value — rather than asking "are you sure?" about a
   * button. A plain confirmation, not a typed one: a removed record can be
   * added back, but not before whatever relied on it has already stopped
   * working, which is what the dialogue says.
   */
  const [removing, setRemoving] = useState<DnsRecord | null>(null)

  const addFailure = describeError(add.error)
  const removeFailure = describeError(remove.error)

  const columns: Array<Column<DnsRecord>> = [
    { key: 'type', header: t('dns.type'), ltr: true, cell: (r) => <span className="technical text-xs">{r.type}</span> },
    {
      key: 'name',
      header: t('dns.name'),
      ltr: true,
      cell: (r) => <span className="technical text-xs break-all">{r.name}</span>,
    },
    {
      key: 'content',
      header: t('dns.value'),
      ltr: true,
      cell: (r) => (
        <span className="technical text-xs break-all">
          {r.priority === null ? r.content : `${r.priority} ${r.content}`}
        </span>
      ),
    },
    { key: 'ttl', header: t('dns.ttl'), ltr: true, cell: (r) => <span className="technical text-xs">{r.ttl === 1 ? t('dns.automatic') : r.ttl}</span> },
    { key: 'state', header: t('dns.state'), cell: (r) => <StatusBadge status={r.state} /> },
    {
      key: 'actions',
      header: '',
      cell: (r) => (
        <Button
          size="sm"
          variant="ghost"
          disabled={r.is_being_deleted}
          loading={remove.isPending && remove.variables.recordId === r.id}
          onClick={() => { setRemoving(r); }}
        >
          {t('common.remove')}
        </Button>
      ),
    },
  ]

  return (
    <Card>
      <h2 className="mb-3 text-sm font-medium">{t('dns.records')}</h2>

      {isPending ? (
        <Loading className="py-6" />
      ) : (
        <DataTable
          caption={t('dns.records')}
          columns={columns}
          rows={data?.data ?? []}
          rowKey={(r) => r.id}
          empty={t('dns.noRecords')}
        />
      )}

      {removeFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={removeFailure.requestId}>
            {removeFailure.message}
          </Alert>
        </div>
      )}

      <ConfirmDialog
        open={removing !== null}
        title={t('dns.removeRecord.title')}
        body={
          <p>
            {t('dns.removeRecord.body', {
              record:
                removing === null
                  ? ''
                  : `${removing.type} ${removing.name} → ${removing.priority === null ? removing.content : `${removing.priority} ${removing.content}`}`,
            })}
          </p>
        }
        confirmLabel={t('dns.removeRecord.confirmLabel')}
        loading={remove.isPending}
        onConfirm={() => {
          if (removing === null) return

          remove.mutate(
            { zoneId: zone.id, recordId: removing.id },
            { onSettled: () => { setRemoving(null); } },
          )
        }}
        onCancel={() => { setRemoving(null); }}
      />

      <form
        className="mt-4 flex flex-wrap items-end gap-3 border-t border-[var(--border-subtle)] pt-4"
        onSubmit={(event) => {
          event.preventDefault()

          add.mutate(
            {
              zoneId: zone.id,
              type,
              name: name.trim() === '' ? zone.name : `${name.trim()}.${zone.name}`,
              ...(type === 'CAA'
                ? { data: { flags: 0, tag: caaTag, value: content.trim() } }
                : { content: content.trim() }),
              ...(type === 'MX' ? { priority: Number(priority) } : {}),
            },
            { onSuccess: () => { setName(''); setContent('') } },
          )
        }}
      >
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium">{t('dns.type')}</span>
          <select
            value={type}
            onChange={(event) => { setType(event.target.value) }}
            dir="ltr"
            className="technical h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
          >
            {TYPES.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </label>

        <div className="min-w-[10rem]">
          {/*
            * The label carries the zone, because a box that says "name" and
            * silently appends `.example.com` is how somebody publishes
            * `www.example.com.example.com`.
            */}
          <Field
            label={t('dns.subdomain', { zone: zone.name })}
            dir="ltr"
            placeholder="www"
            value={name}
            onChange={(event) => { setName(event.target.value) }}
          />
        </div>

        {type === 'CAA' ? (
          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium">{t('dns.caaTag')}</span>
            <select
              value={caaTag}
              onChange={(event) => { setCaaTag(event.target.value) }}
              dir="ltr"
              className="technical h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              {['issue', 'issuewild', 'iodef'].map((option) => (
                <option key={option} value={option}>
                  {option}
                </option>
              ))}
            </select>
          </label>
        ) : null}

        {type === 'MX' ? (
          <div className="w-24">
            <Field
              label={t('dns.priority')}
              dir="ltr"
              inputMode="numeric"
              value={priority}
              onChange={(event) => { setPriority(event.target.value) }}
            />
          </div>
        ) : null}

        <div className="min-w-[14rem] flex-1">
          <Field
            label={t('dns.value')}
            dir="ltr"
            value={content}
            onChange={(event) => { setContent(event.target.value) }}
          />
        </div>

        <Button type="submit" loading={add.isPending}>
          {t('dns.addRecord')}
        </Button>
      </form>

      {addFailure === null ? null : (
        <div className="mt-3">
          <Alert tone="error" requestId={addFailure.requestId}>
            {addFailure.message}
          </Alert>
        </div>
      )}
    </Card>
  )
}

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
function ZoneTransfer({ zone }: { zone: DnsZone }) {
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
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium">{t('dns.import.file')}</span>
          <input
            ref={fileInput}
            type="file"
            accept=".zone,.txt,.db,text/plain"
            className="text-sm"
            onChange={(event) => { readFile(event.target.files?.[0]) }}
          />
          <span className="text-xs text-[var(--text-muted)]">{t('dns.import.fileHint')}</span>
        </label>

        {fileTooLarge ? <Alert tone="error">{t('dns.import.tooLarge')}</Alert> : null}

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium">{t('dns.import.text')}</span>
          <textarea
            dir="ltr"
            spellCheck={false}
            className="technical min-h-40 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 py-2 text-xs"
            value={text}
            onChange={(event) => {
              setText(event.target.value)
              plan.reset()
              apply.reset()
            }}
          />
        </label>

        <div className="flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium">{t('dns.import.mode')}</span>
            <select
              value={mode}
              onChange={(event) => { setMode(event.target.value as ZoneImportMode) }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              {(['merge', 'replace'] as const).map((option) => (
                <option key={option} value={option}>
                  {t(`dns.import.modes.${option}`)}
                </option>
              ))}
            </select>
          </label>
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
