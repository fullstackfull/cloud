import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { ConfirmDialog } from '@/components/ConfirmDialog'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { Loading } from '@/components/Loading'
import { SelectField } from '@/components/SelectField'
import { StatusBadge } from '@/components/StatusBadge'
import {
  useAddDnsRecord,
  useDnsRecords,
  useRemoveDnsRecord,
  useUpdateDnsRecord,
} from '@/lib/queries'
import type { DnsRecord, DnsZone } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/** The six the platform publishes. A provider accepting SRV is not a reason to offer it. */
const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA'] as const

export function ZoneRecords({ zone }: { zone: DnsZone }) {
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

  /*
   * The record being changed. An edit goes through the endpoint's own PATCH
   * rather than a delete followed by an add: the second form drops the record
   * at the provider and creates a new one, so anything resolving in between
   * gets nothing at all, and a failure halfway leaves the customer with
   * neither the old value nor the new one.
   */
  const [editing, setEditing] = useState<DnsRecord | null>(null)

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
        <div className="flex justify-end gap-1">
          <Button
            size="sm"
            variant="ghost"
            disabled={r.is_being_deleted}
            onClick={() => { setEditing(r); }}
          >
            {t('common.edit')}
          </Button>

          <Button
            size="sm"
            variant="ghost"
            disabled={r.is_being_deleted}
            loading={remove.isPending && remove.variables.recordId === r.id}
            onClick={() => { setRemoving(r); }}
          >
            {t('common.remove')}
          </Button>
        </div>
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

      {/*
        * Keyed by record and mounted only while open, so the boxes start from
        * the row the customer clicked rather than from whatever they were
        * editing last.
        */}
      {editing === null ? null : (
        <EditRecordDialog
          key={editing.id}
          zone={zone}
          record={editing}
          onClose={() => { setEditing(null); }}
        />
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
        <SelectField
          label={t('dns.type')}
          value={type}
          onChange={(event) => { setType(event.target.value) }}
          dir="ltr"
          className="technical"
          options={TYPES.map((option) => ({ value: option, label: option }))}
        />

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
          <SelectField
            label={t('dns.caaTag')}
            value={caaTag}
            onChange={(event) => { setCaaTag(event.target.value) }}
            dir="ltr"
            className="technical"
            options={['issue', 'issuewild', 'iodef'].map((option) => ({
              value: option,
              label: option,
            }))}
          />
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

/**
 * Changing what one record says.
 *
 * Neither the name nor the type is editable, because the endpoint accepts
 * neither: a record with a different name is a different record. A customer who
 * wants one deletes this and adds that, and sees both steps happen.
 *
 * Not a typed confirmation. An edit is a change to a value that can be changed
 * back — the graded policy's middle case — and the dialogue instead shows the
 * record it is about to change so nobody edits the wrong row.
 */
function EditRecordDialog({
  zone,
  record,
  onClose,
}: {
  zone: DnsZone
  record: DnsRecord
  onClose: () => void
}) {
  const { t } = useTranslation()
  const describeError = useApiErrorMessage()
  const update = useUpdateDnsRecord()

  const [content, setContent] = useState(record.caa_value ?? record.content)
  const [ttl, setTtl] = useState(String(record.ttl))
  const [priority, setPriority] = useState(record.priority === null ? '' : String(record.priority))

  const failure = describeError(update.error)

  return (
    <ConfirmDialog
      open
      title={t('dns.editRecord.title')}
      body={
        <div className="flex flex-col gap-3">
          <p className="text-sm text-[var(--text-secondary)]">
            {t('dns.editRecord.body', { zone: zone.name })}
          </p>

          <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-sm">
            <dt className="text-[var(--text-muted)]">{t('dns.type')}</dt>
            <dd className="technical" dir="ltr">
              {record.type}
            </dd>

            <dt className="text-[var(--text-muted)]">{t('dns.name')}</dt>
            <dd className="technical break-all" dir="ltr">
              {record.name}
            </dd>
          </dl>

          <Field
            label={t('dns.value')}
            dir="ltr"
            value={content}
            onChange={(event) => { setContent(event.target.value); }}
            error={failure?.fields?.['content']?.[0] ?? failure?.fields?.['data.value']?.[0]}
          />

          <Field
            label={t('dns.ttl')}
            dir="ltr"
            inputMode="numeric"
            value={ttl}
            onChange={(event) => { setTtl(event.target.value); }}
            hint={t('dns.ttlHint')}
            error={failure?.fields?.['ttl']?.[0]}
          />

          {record.type === 'MX' ? (
            <Field
              label={t('dns.priority')}
              dir="ltr"
              inputMode="numeric"
              value={priority}
              onChange={(event) => { setPriority(event.target.value); }}
              error={failure?.fields?.['priority']?.[0]}
            />
          ) : null}
        </div>
      }
      confirmLabel={t('common.save')}
      loading={update.isPending}
      {...(failure === null || failure.fields !== null ? {} : { error: failure.message })}
      onCancel={() => {
        update.reset()
        onClose()
      }}
      onConfirm={() => {
        const parsedTtl = Number.parseInt(ttl, 10)

        update.mutate(
          {
            zoneId: zone.id,
            recordId: record.id,
            changes: {
              /*
               * A CAA record's value travels in `data` with the flags and tag
               * it already has: sending it as `content` would ask the
               * provider to publish the assembled string as an opaque value.
               */
              ...(record.type === 'CAA'
                ? {
                    data: {
                      flags: record.caa_flags ?? 0,
                      tag: record.caa_tag ?? 'issue',
                      value: content.trim(),
                    },
                  }
                : { content: content.trim() }),
              ...(Number.isNaN(parsedTtl) ? {} : { ttl: parsedTtl }),
              ...(record.type === 'MX' && priority.trim() !== ''
                ? { priority: Number.parseInt(priority, 10) }
                : {}),
            },
          },
          { onSuccess: onClose },
        )
      }}
    />
  )
}
