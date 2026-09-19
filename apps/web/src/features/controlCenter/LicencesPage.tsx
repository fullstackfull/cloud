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
import { Loading } from '@/components/Loading'
import {
  ENVIRONMENTS,
  useInvalidateLicence,
  useLicences,
  useRecordLicence,
  useRefreshLicences,
  useRenewLicence,
  type Environment,
  type Licence,
} from '@/lib/controlCenterQueries'
import { formatDate } from '@/lib/format'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * What was bought, what it covers, and when it stops being true.
 *
 * The state on each row comes from the calendar and a nightly sweep; the
 * "Refresh now" control runs that same sweep for an operator who will not
 * wait until morning. The operator's one override is to say the vendor
 * rejected a licence — there is no control here that declares an expired one
 * active, because nothing an operator types makes that so.
 */
export function LicencesPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describe = useApiErrorMessage()
  const [page, setPage] = useState(1)
  const [environment, setEnvironment] = useState<Environment | ''>('')
  const [recording, setRecording] = useState(false)
  const [renewing, setRenewing] = useState<Licence | null>(null)
  const [invalidating, setInvalidating] = useState<Licence | null>(null)

  const { data, isPending, error } = useLicences(page, environment)
  const refresh = useRefreshLicences()
  const invalidate = useInvalidateLicence()

  const columns: Array<Column<Licence>> = [
    {
      key: 'product',
      header: t('admin.licences.product'),
      ltr: true,
      cell: (licence) => (
        <div>
          <p className="technical font-medium text-[var(--text-primary)]">
            {licence.product}
            {licence.licence_type === null ? '' : ` · ${licence.licence_type}`}
          </p>
          <p className="text-xs text-[var(--text-muted)]">
            {licence.server === null ? t('admin.licences.noMachine') : licence.server.server_name}
            {licence.external_reference === null ? '' : ` · ${licence.external_reference}`}
          </p>
        </div>
      ),
    },
    {
      key: 'environment',
      header: t('admin.licences.environment'),
      cell: (licence) => t(`admin.controlCenter.environments.${licence.environment}`),
    },
    {
      key: 'state',
      header: t('admin.licences.state'),
      cell: (licence) => (
        <span className="flex flex-wrap items-center gap-2">
          <StatusBadge status={licence.state} />
          {licence.needs_attention && licence.state !== 'invalid' ? (
            <Badge tone="warning">{t('admin.licences.needsAttention')}</Badge>
          ) : null}
        </span>
      ),
    },
    {
      key: 'expires',
      header: t('admin.licences.expires'),
      cell: (licence) =>
        licence.expires_on === null ? (
          t('admin.licences.noExpiry')
        ) : (
          <span>
            {formatDate(licence.expires_on, locale)}
            {licence.days_remaining === null ? null : (
              <span className="block text-xs text-[var(--text-muted)]">
                {licence.days_remaining < 0
                  ? t('admin.licences.daysAgo', { count: Math.abs(licence.days_remaining) })
                  : t('admin.licences.daysLeft', { count: licence.days_remaining })}
              </span>
            )}
          </span>
        ),
    },
    {
      key: 'usage',
      header: t('admin.licences.usage'),
      cell: (licence) => t('admin.licences.usageProviders', { count: licence.usage.providers }),
    },
    {
      key: 'actions',
      header: '',
      cell: (licence) => (
        <div className="flex flex-col gap-1">
          {licence.state === 'invalid' && licence.invalidated_reason !== null ? (
            <p className="text-xs text-[var(--text-muted)]">{licence.invalidated_reason}</p>
          ) : null}
          <span className="flex gap-2">
            <Button size="sm" variant="ghost" onClick={() => { setRenewing(licence); }}>
              {t('admin.licences.renew')}
            </Button>
            {licence.state === 'invalid' ? null : (
              <Button size="sm" variant="danger" onClick={() => { setInvalidating(licence); }}>
                {t('admin.licences.invalidate')}
              </Button>
            )}
          </span>
        </div>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('admin.licences.title')}
        description={t('admin.licences.subtitle')}
        actions={
          <span className="flex gap-2">
            <Button variant="ghost" loading={refresh.isPending} onClick={() => { refresh.mutate(); }}>
              {refresh.isSuccess
                ? t('admin.licences.refreshed', { count: refresh.data.data.changed })
                : t('admin.licences.refresh')}
            </Button>
            <Button onClick={() => { setRecording((open) => !open); }}>{t('admin.licences.record')}</Button>
          </span>
        }
      />

      {recording ? <RecordLicenceForm onDone={() => { setRecording(false); }} /> : null}

      <Card>
        <div className="mb-3 flex items-center gap-3">
          <label className="text-sm text-[var(--text-muted)]" htmlFor="licence-environment">
            {t('admin.licences.environment')}
          </label>
          <select
            id="licence-environment"
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
          <Loading />
        ) : error ? (
          <LoadFailure error={error} />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={data.data}
              rowKey={(licence) => licence.id}
              empty={t('admin.licences.empty')}
              caption={t('admin.licences.title')}
            />
            <Paginator page={page} lastPage={data.meta.last_page} total={data.meta.total} onChange={setPage} />
          </>
        )}
      </Card>

      <ConfirmDialog
        open={invalidating !== null}
        title={t('admin.licences.invalidateTitle', { product: invalidating?.product ?? '' })}
        body={<p>{t('admin.licences.invalidateBody')}</p>}
        evidenceLabel={t('admin.licences.invalidateReason')}
        evidenceHint={t('admin.licences.invalidateReasonHint')}
        confirmLabel={t('admin.licences.invalidate')}
        loading={invalidate.isPending}
        error={describe(invalidate.error)?.message}
        onCancel={() => { setInvalidating(null); invalidate.reset(); }}
        onConfirm={(_phrase, reason) => {
          if (invalidating === null) return
          invalidate.mutate({ id: invalidating.id, reason }, { onSuccess: () => { setInvalidating(null); } })
        }}
      />

      {renewing === null ? null : <RenewDialog licence={renewing} onClose={() => { setRenewing(null); }} />}
    </>
  )
}

function RenewDialog({ licence, onClose }: { licence: Licence; onClose: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const renew = useRenewLicence()
  const [expiresOn, setExpiresOn] = useState('')
  const [reference, setReference] = useState('')

  return (
    <ConfirmDialog
      open
      title={t('admin.licences.renewTitle', { product: licence.product })}
      body={
        <div className="flex flex-col gap-3">
          <p>{t('admin.licences.renewBody')}</p>
          <Field
            label={t('admin.licences.newExpiry')}
            type="date"
            value={expiresOn}
            onChange={(event) => { setExpiresOn(event.target.value); }}
            required
          />
          <Field
            label={t('admin.licences.externalReference')}
            value={reference}
            onChange={(event) => { setReference(event.target.value); }}
            dir="ltr"
          />
        </div>
      }
      confirmLabel={t('admin.licences.renew')}
      // Not actionable until there is a date to send.
      ready={expiresOn !== ''}
      loading={renew.isPending}
      error={describe(renew.error)?.message}
      onCancel={onClose}
      onConfirm={() => {
        renew.mutate(
          { id: licence.id, expires_on: expiresOn, ...(reference.trim() === '' ? {} : { external_reference: reference.trim() }) },
          { onSuccess: onClose },
        )
      }}
    />
  )
}

function RecordLicenceForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const record = useRecordLicence()

  const [product, setProduct] = useState('')
  const [licenceType, setLicenceType] = useState('')
  const [environment, setEnvironment] = useState<Environment>('staging')
  const [startsOn, setStartsOn] = useState('')
  const [expiresOn, setExpiresOn] = useState('')
  const [seats, setSeats] = useState('')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')

  const failure = describe(record.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  function submit(event: { preventDefault: () => void }) {
    event.preventDefault()

    record.mutate(
      {
        product: product.trim(),
        environment,
        ...(licenceType.trim() === '' ? {} : { licence_type: licenceType.trim() }),
        ...(startsOn === '' ? {} : { starts_on: startsOn }),
        ...(expiresOn === '' ? {} : { expires_on: expiresOn }),
        ...(seats === '' ? {} : { seats: Number(seats) }),
        ...(reference.trim() === '' ? {} : { external_reference: reference.trim() }),
        ...(notes.trim() === '' ? {} : { notes: notes.trim() }),
      },
      { onSuccess: onDone },
    )
  }

  return (
    <Card>
      <form onSubmit={submit} className="flex flex-col gap-4" aria-label={t('admin.licences.recordTitle')}>
        <h2 className="text-base font-semibold">{t('admin.licences.recordTitle')}</h2>

        <Alert tone="info">{t('admin.licences.keysGoElsewhere')}</Alert>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('admin.licences.product')} value={product} onChange={(e) => { setProduct(e.target.value); }} error={fieldError('product')} dir="ltr" required />
          <Field label={t('admin.licences.licenceType')} value={licenceType} onChange={(e) => { setLicenceType(e.target.value); }} error={fieldError('licence_type')} dir="ltr" />

          <div className="flex flex-col gap-1.5">
            <label htmlFor="record-licence-environment" className="text-sm font-medium">{t('admin.licences.environment')}</label>
            <select
              id="record-licence-environment"
              value={environment}
              onChange={(event) => { setEnvironment(event.target.value as Environment); }}
              className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm"
            >
              {ENVIRONMENTS.map((value) => (
                <option key={value} value={value}>{t(`admin.controlCenter.environments.${value}`)}</option>
              ))}
            </select>
          </div>

          <Field label={t('admin.licences.seats')} type="number" min={1} value={seats} onChange={(e) => { setSeats(e.target.value); }} error={fieldError('seats')} />
          <Field label={t('admin.licences.startsOn')} type="date" value={startsOn} onChange={(e) => { setStartsOn(e.target.value); }} error={fieldError('starts_on')} />
          <Field label={t('admin.licences.expiresOn')} type="date" value={expiresOn} onChange={(e) => { setExpiresOn(e.target.value); }} error={fieldError('expires_on')} />
          <Field label={t('admin.licences.externalReference')} hint={t('admin.licences.externalReferenceHint')} value={reference} onChange={(e) => { setReference(e.target.value); }} error={fieldError('external_reference')} dir="ltr" />
          <Field label={t('admin.licences.notes')} value={notes} onChange={(e) => { setNotes(e.target.value); }} error={fieldError('notes')} />
        </div>

        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone} disabled={record.isPending}>{t('common.cancel')}</Button>
          <Button type="submit" loading={record.isPending}>{t('admin.licences.submit')}</Button>
        </div>
      </form>
    </Card>
  )
}
