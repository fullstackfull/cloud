import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { EmptyState } from '@/components/EmptyState'
import { Field } from '@/components/Field'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import {
  useAddDnsRecord,
  useClaimDnsZone,
  useDnsRecords,
  useDnsZones,
  useReleaseDnsZone,
  useRemoveDnsRecord,
} from '@/lib/queries'
import type { DnsRecord, DnsZone } from '@/lib/types'
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
        <p className="py-8 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
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
          onClick={() => { remove.mutate({ zoneId: zone.id, recordId: r.id }); }}
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
        <p className="py-6 text-center text-sm text-[var(--text-muted)]">{t('common.loading')}</p>
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
