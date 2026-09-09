import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { StatusBadge } from '@/components/StatusBadge'
import {
  ENVIRONMENTS,
  useCredentials,
  useMarkCredentialRotated,
  useRecordCredential,
  useRevokeCredential,
  type Credential,
  type Environment,
} from '@/lib/controlCenterQueries'
import { formatDateTime } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Where the secrets are — never what they are.
 *
 * This screen records references: the name of a variable on the deployment
 * controller. It has no field for a value and the API refuses one by name, so
 * the most a mistaken paste can do here is produce an error telling the
 * operator to rotate what they pasted. The form says that above the field
 * rather than in a tooltip, because the mistake it prevents is made by the
 * people who do not open tooltips.
 *
 * `present` is the one fact about a secret this screen may show: whether the
 * controller has a value behind the reference. It is what turns "configured"
 * from a hope into a state.
 */
export function CredentialsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describe = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const [environment, setEnvironment] = useState<Environment | ''>('')
  const [recording, setRecording] = useState(false)
  const [revoking, setRevoking] = useState<Credential | null>(null)
  const [rotating, setRotating] = useState<Credential | null>(null)

  const { data, isPending, error } = useCredentials(page, environment)
  const revoke = useRevokeCredential()
  const rotated = useMarkCredentialRotated()

  const columns: Array<Column<Credential>> = [
    {
      key: 'name',
      header: t('admin.credentials.name'),
      ltr: true,
      cell: (credential) => (
        <div>
          <p className="technical font-medium text-[var(--text-primary)]">{credential.name}</p>
          <p className="text-xs text-[var(--text-muted)]">{credential.purpose}</p>
          {credential.masked_hint === null ? null : (
            <p className="technical text-xs text-[var(--text-muted)]">…{credential.masked_hint}</p>
          )}
        </div>
      ),
    },
    {
      key: 'environment',
      header: t('admin.credentials.environment'),
      cell: (credential) => t(`admin.controlCenter.environments.${credential.environment}`),
    },
    {
      key: 'state',
      header: t('admin.credentials.state'),
      cell: (credential) => (
        <span className="flex flex-wrap items-center gap-2">
          <StatusBadge status={credential.state} />
          {credential.state === 'revoked' ? null : credential.present ? (
            <Badge tone="success">{t('admin.credentials.present')}</Badge>
          ) : (
            <Badge tone="warning">{t('admin.credentials.absent')}</Badge>
          )}
        </span>
      ),
    },
    {
      key: 'usage',
      header: t('admin.credentials.usage'),
      cell: (credential) => (
        <span className="text-sm">
          {t('admin.credentials.usageProviders', { count: credential.usage.providers })}
          {' · '}
          {t('admin.credentials.usageServers', { count: credential.usage.servers })}
        </span>
      ),
    },
    {
      key: 'tested',
      header: t('admin.credentials.lastTested'),
      cell: (credential) =>
        credential.last_tested_at === null
          ? t('admin.credentials.never')
          : formatDateTime(credential.last_tested_at, locale),
    },
    {
      key: 'actions',
      header: '',
      cell: (credential) =>
        credential.state === 'revoked' ? (
          <p className="text-xs text-[var(--text-muted)]">
            {t('admin.credentials.revokedOn', { when: formatDateTime(credential.revoked_at ?? '', locale) })}
            {credential.revoked_reason === null ? null : ` — ${credential.revoked_reason}`}
          </p>
        ) : (
          <span className="flex gap-2">
            <Button size="sm" variant="ghost" onClick={() => { setRotating(credential); }}>
              {t('admin.credentials.markRotated')}
            </Button>
            <Button size="sm" variant="danger" onClick={() => { setRevoking(credential); }}>
              {t('admin.credentials.revoke')}
            </Button>
          </span>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('admin.credentials.title')}
        description={t('admin.credentials.subtitle')}
        actions={
          <Button onClick={() => { setRecording((open) => !open); }}>
            {t('admin.credentials.record')}
          </Button>
        }
      />

      {recording ? <RecordCredentialForm onDone={() => { setRecording(false); }} /> : null}

      <Card>
        <div className="mb-3 flex items-center gap-3">
          <label className="text-sm text-[var(--text-muted)]" htmlFor="credential-environment">
            {t('admin.credentials.environment')}
          </label>
          <select
            id="credential-environment"
            value={environment}
            onChange={(event) => { setEnvironment(event.target.value as Environment | ''); setPage(1); }}
            className="h-9 rounded-md border border-[var(--border-subtle)] bg-[var(--surface-base)] px-2 text-sm"
          >
            <option value="">{t('admin.controlCenter.allEnvironments')}</option>
            {ENVIRONMENTS.map((value) => (
              <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>
            ))}
          </select>
        </div>

        {isPending ? (
          <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : error ? (
          <LoadFailure error={error} />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={data.data}
              rowKey={(credential) => credential.id}
              empty={t('admin.credentials.empty')}
              caption={t('admin.credentials.title')}
            />
            <Paginator
              page={page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              onChange={setPage}
            />
          </>
        )}
      </Card>

      <ConfirmDialog
        open={revoking !== null}
        title={t('admin.credentials.revokeTitle', { name: revoking?.name ?? '' })}
        body={<p>{t('admin.credentials.revokeBody')}</p>}
        evidenceLabel={t('admin.credentials.revokeReason')}
        evidenceHint={t('admin.credentials.revokeReasonHint')}
        confirmLabel={t('admin.credentials.revoke')}
        loading={revoke.isPending}
        error={describe(revoke.error)?.message}
        onCancel={() => { setRevoking(null); revoke.reset(); }}
        onConfirm={(_phrase, reason) => {
          if (revoking === null) return
          revoke.mutate({ id: revoking.id, reason }, { onSuccess: () => { setRevoking(null); } })
        }}
      />

      <ConfirmDialog
        open={rotating !== null}
        title={t('admin.credentials.markRotatedTitle', { name: rotating?.name ?? '' })}
        body={<p>{t('admin.credentials.markRotatedBody')}</p>}
        confirmLabel={t('admin.credentials.markRotated')}
        loading={rotated.isPending}
        error={describe(rotated.error)?.message}
        onCancel={() => { setRotating(null); rotated.reset(); }}
        onConfirm={() => {
          if (rotating === null) return
          rotated.mutate({ id: rotating.id }, { onSuccess: () => { setRotating(null); } })
        }}
      />
    </>
  )
}

function RecordCredentialForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const record = useRecordCredential()

  const [name, setName] = useState('')
  const [purpose, setPurpose] = useState('')
  const [environment, setEnvironment] = useState<Environment>('staging')
  const [reference, setReference] = useState('')
  const [maskedHint, setMaskedHint] = useState('')
  const [rotatesAt, setRotatesAt] = useState('')
  const [notes, setNotes] = useState('')

  const failure = describe(record.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  function submit(event: { preventDefault: () => void }) {
    event.preventDefault()

    record.mutate(
      {
        name: name.trim(),
        purpose: purpose.trim(),
        environment,
        backend_reference: reference.trim(),
        ...(maskedHint.trim() === '' ? {} : { masked_hint: maskedHint.trim() }),
        ...(rotatesAt === '' ? {} : { rotates_at: rotatesAt }),
        ...(notes.trim() === '' ? {} : { notes: notes.trim() }),
      },
      { onSuccess: onDone },
    )
  }

  return (
    <Card>
      <form onSubmit={submit} className="flex flex-col gap-4" aria-label={t('admin.credentials.recordTitle')}>
        <h2 className="text-base font-semibold">{t('admin.credentials.recordTitle')}</h2>

        {/*
          * Said above the field, in the operator's language, before they
          * type. This is the one mistake this screen exists to prevent, and a
          * hint under a field is read after the paste, not before it.
          */}
        <Alert tone="warning">{t('admin.credentials.neverPaste')}</Alert>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label={t('admin.credentials.name')}
            value={name}
            onChange={(event) => { setName(event.target.value); }}
            error={fieldError('name')}
            dir="ltr"
            autoComplete="off"
            required
          />
          <Field
            label={t('admin.credentials.purpose')}
            value={purpose}
            onChange={(event) => { setPurpose(event.target.value); }}
            error={fieldError('purpose')}
            required
          />

          <div className="flex flex-col gap-1.5">
            <label htmlFor="record-environment" className="text-sm font-medium">
              {t('admin.credentials.environment')}
            </label>
            <select
              id="record-environment"
              value={environment}
              onChange={(event) => { setEnvironment(event.target.value as Environment); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              {ENVIRONMENTS.map((value) => (
                <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>
              ))}
            </select>
          </div>

          <Field
            label={t('admin.credentials.reference')}
            hint={t('admin.credentials.referenceHint')}
            value={reference}
            onChange={(event) => { setReference(event.target.value.toUpperCase()); }}
            error={fieldError('backend_reference')}
            dir="ltr"
            autoComplete="off"
            spellCheck={false}
            className="technical"
            required
          />

          <Field
            label={t('admin.credentials.maskedHint')}
            hint={t('admin.credentials.maskedHintHint')}
            value={maskedHint}
            maxLength={4}
            onChange={(event) => { setMaskedHint(event.target.value); }}
            error={fieldError('masked_hint')}
            dir="ltr"
            autoComplete="off"
          />
          <Field
            label={t('admin.credentials.rotatesAt')}
            type="date"
            value={rotatesAt}
            onChange={(event) => { setRotatesAt(event.target.value); }}
            error={fieldError('rotates_at')}
          />
        </div>

        <Field
          label={t('admin.credentials.notes')}
          value={notes}
          onChange={(event) => { setNotes(event.target.value); }}
          error={fieldError('notes')}
        />

        {failure !== null && failure.fields === null ? (
          <Alert tone="error">{failure.message}</Alert>
        ) : null}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone} disabled={record.isPending}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" loading={record.isPending}>
            {t('admin.credentials.submit')}
          </Button>
        </div>
      </form>
    </Card>
  )
}
