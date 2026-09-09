import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { useDatacenters, useRacks, useRegions, useRegisterDatacenter, useRegisterRack } from '@/lib/controlCenterQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Where machines are. Registered, never inferred: the platform has no way to
 * know a building or a rack exists until somebody says so.
 */
export function SitesPage() {
  const { t } = useTranslation()
  const datacenters = useDatacenters()
  const racks = useRacks()
  const [addingDatacenter, setAddingDatacenter] = useState(false)
  const [addingRack, setAddingRack] = useState(false)

  return (
    <>
      <PageHeader
        title={t('admin.sites.title')}
        description={t('admin.sites.subtitle')}
        actions={
          <span className="flex gap-2">
            <Button variant="secondary" onClick={() => { setAddingDatacenter((open) => !open); }}>{t('admin.sites.registerDatacenter')}</Button>
            <Button onClick={() => { setAddingRack((open) => !open); }}>{t('admin.sites.registerRack')}</Button>
          </span>
        }
      />

      {addingDatacenter ? <DatacenterForm onDone={() => { setAddingDatacenter(false); }} /> : null}
      {addingRack ? <RackForm onDone={() => { setAddingRack(false); }} /> : null}

      <Card>
        {datacenters.isPending || racks.isPending ? (
          <p className="text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
        ) : datacenters.error ? (
          <LoadFailure error={datacenters.error} />
        ) : racks.error ? (
          <LoadFailure error={racks.error} />
        ) : datacenters.data.data.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">{t('admin.sites.empty')}</p>
        ) : (
          <ul className="flex flex-col gap-4" aria-label={t('admin.sites.title')}>
            {datacenters.data.data.map((datacenter) => (
              <li key={datacenter.id} className="rounded-lg border border-[var(--border-subtle)] p-3" aria-label={datacenter.name}>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p>
                    <span className="font-medium">{datacenter.name}</span>{' '}
                    <span className="technical text-xs text-[var(--text-muted)]">{datacenter.slug}{datacenter.facility === null ? '' : ` · ${datacenter.facility}`}</span>
                  </p>
                  <span className="text-xs text-[var(--text-muted)]">
                    {t('admin.sites.counts', { racks: datacenter.racks, machines: datacenter.machines })}
                  </span>
                </div>
                <ul className="mt-2 flex flex-col gap-1" aria-label={t('admin.sites.racksIn', { name: datacenter.name })}>
                  {racks.data.data.filter((rack) => rack.datacenter_id === datacenter.id).map((rack) => (
                    <li key={rack.id} className="flex flex-wrap items-center gap-2 text-sm" aria-label={rack.name}>
                      <span className="technical font-medium">{rack.name}</span>
                      {rack.row === null ? null : <span className="text-xs text-[var(--text-muted)]">{t('admin.sites.row', { row: rack.row })}</span>}
                      <span className="text-xs text-[var(--text-muted)]">{t('admin.sites.units', { units: rack.units, machines: rack.machines })}</span>
                      {rack.power_notes === null ? null : <span className="text-xs text-[var(--text-muted)]">⚡ {rack.power_notes}</span>}
                      {rack.network_notes === null ? null : <span className="text-xs text-[var(--text-muted)]">🔌 {rack.network_notes}</span>}
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}

function DatacenterForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const regions = useRegions()
  const register = useRegisterDatacenter()
  const [regionId, setRegionId] = useState('')
  const [slug, setSlug] = useState('')
  const [name, setName] = useState('')
  const [facility, setFacility] = useState('')
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.sites.registerDatacenter')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          register.mutate({ region_id: regionId, slug: slug.trim(), name: name.trim(), ...(facility.trim() === '' ? {} : { facility: facility.trim() }) }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="dc-region" className="text-sm font-medium">{t('admin.sites.region')}</label>
            <select id="dc-region" value={regionId} onChange={(e) => { setRegionId(e.target.value); }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm" required>
              <option value="">—</option>
              {(regions.data?.data ?? []).map((region) => <option key={region.id} value={region.id}>{region.name}</option>)}
            </select>
          </div>
          <Field label={t('admin.sites.slug')} value={slug} onChange={(e) => { setSlug(e.target.value); }} error={fieldError('slug')} dir="ltr" required />
          <Field label={t('admin.sites.name')} value={name} onChange={(e) => { setName(e.target.value); }} error={fieldError('name')} required />
          <Field label={t('admin.sites.facility')} value={facility} onChange={(e) => { setFacility(e.target.value); }} error={fieldError('facility')} />
        </div>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending}>{t('admin.sites.registerDatacenter')}</Button>
        </div>
      </form>
    </Card>
  )
}

function RackForm({ onDone }: { onDone: () => void }) {
  const { t } = useTranslation()
  const describe = useApiErrorMessage()
  const datacenters = useDatacenters()
  const register = useRegisterRack()
  const [datacenterId, setDatacenterId] = useState('')
  const [name, setName] = useState('')
  const [row, setRow] = useState('')
  const [units, setUnits] = useState('42')
  const [power, setPower] = useState('')
  const [network, setNetwork] = useState('')
  const failure = describe(register.error)
  const fieldError = (field: string): string | undefined => failure?.fields?.[field]?.[0]

  return (
    <Card>
      <form
        aria-label={t('admin.sites.registerRack')}
        className="flex flex-col gap-3"
        onSubmit={(event) => {
          event.preventDefault()
          register.mutate({
            datacenter_id: datacenterId,
            name: name.trim(),
            units: Number(units),
            ...(row.trim() === '' ? {} : { row: row.trim() }),
            ...(power.trim() === '' ? {} : { power_notes: power.trim() }),
            ...(network.trim() === '' ? {} : { network_notes: network.trim() }),
          }, { onSuccess: onDone })
        }}
      >
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="flex flex-col gap-1.5">
            <label htmlFor="rack-dc" className="text-sm font-medium">{t('admin.sites.datacenter')}</label>
            <select id="rack-dc" value={datacenterId} onChange={(e) => { setDatacenterId(e.target.value); }} className="h-10 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-base)] px-3 text-sm" required>
              <option value="">—</option>
              {(datacenters.data?.data ?? []).map((dc) => <option key={dc.id} value={dc.id}>{dc.name}</option>)}
            </select>
          </div>
          <Field label={t('admin.sites.rackName')} value={name} onChange={(e) => { setName(e.target.value); }} error={fieldError('name')} dir="ltr" required />
          <Field label={t('admin.sites.rowLabel')} value={row} onChange={(e) => { setRow(e.target.value); }} error={fieldError('row')} dir="ltr" />
          <Field label={t('admin.sites.unitsLabel')} type="number" min={1} max={60} value={units} onChange={(e) => { setUnits(e.target.value); }} error={fieldError('units')} dir="ltr" required />
          <Field label={t('admin.sites.powerNotes')} value={power} onChange={(e) => { setPower(e.target.value); }} error={fieldError('power_notes')} />
          <Field label={t('admin.sites.networkNotes')} value={network} onChange={(e) => { setNetwork(e.target.value); }} error={fieldError('network_notes')} />
        </div>
        {failure !== null && failure.fields === null ? <Alert tone="error">{failure.message}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onDone}>{t('common.cancel')}</Button>
          <Button type="submit" loading={register.isPending}>{t('admin.sites.registerRack')}</Button>
        </div>
      </form>
    </Card>
  )
}
