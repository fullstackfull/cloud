import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Loading } from '@/components/Loading'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useCloseTicketAsOperator,
  useOperatorTicket,
  useReopenTicket,
  useReplyToTicketAsOperator,
  useResolveTicket,
  useSetTicketPriority,
  useSupportQueue,
  type OperatorTicket,
} from '@/lib/adminQueries'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

const PRIORITY_TONES: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
  urgent: 'danger',
  high: 'warning',
  normal: 'neutral',
  low: 'neutral',
}

/**
 * The support queue.
 *
 * Worst first, longest untouched first within that — the server decides the
 * order, because a queue sorted client-side is a queue that sorts only the
 * page it happens to have.
 *
 * The two things this screen exists to make hard to get wrong: an internal
 * note must be visibly different from a reply before it is sent, and an urgent
 * ticket must be visible without reading a column.
 */
export function AdminSupportPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [status, setStatus] = useState('')
  const [openId, setOpenId] = useState<string | null>(null)
  const [body, setBody] = useState('')
  const [isNote, setIsNote] = useState(false)

  const queue = useSupportQueue(status)
  const { data: opened } = useOperatorTicket(openId)

  const reply = useReplyToTicketAsOperator()
  const prioritise = useSetTicketPriority()
  const resolve = useResolveTicket()
  const close = useCloseTicketAsOperator()
  const reopen = useReopenTicket()

  const displayed = describeError(
    queue.error ?? reply.error ?? prioritise.error ?? resolve.error ?? close.error ?? reopen.error,
  )
  const ticket = opened?.data ?? null

  const columns: Array<Column<OperatorTicket>> = [
    {
      key: 'priority',
      header: t('admin.support.priority'),
      cell: (row) => (
        <Badge tone={PRIORITY_TONES[row.priority] ?? 'neutral'}>
          {t(`support.priorities.${row.priority}`)}
        </Badge>
      ),
    },
    {
      key: 'reference',
      header: t('support.reference'),
      ltr: true,
      cell: (row) => (
        <button
          type="button"
          className="technical text-start underline underline-offset-2"
          onClick={() => {
            setOpenId(row.id)
            setBody('')
            setIsNote(false)
          }}
        >
          {row.reference}
        </button>
      ),
    },
    { key: 'subject', header: t('support.subject'), cell: (row) => row.subject },
    {
      key: 'customer',
      header: t('admin.support.customer'),
      cell: (row) => row.customer_name ?? '—',
    },
    {
      key: 'assignee',
      header: t('admin.support.assignee'),
      cell: (row) =>
        row.assigned_to ?? <span className="text-[var(--text-muted)]">{t('admin.support.unassigned')}</span>,
    },
    {
      key: 'waiting',
      header: t('admin.support.waitingSince'),
      ltr: true,
      cell: (row) => (row.last_reply_at === null ? '—' : formatDateTime(row.last_reply_at, locale)),
    },
  ]

  return (
    <>
      <PageHeader title={t('admin.support.title')} description={t('admin.support.subtitle')} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <div className="flex flex-col gap-4">
        <Card
          title={t('admin.support.queue')}
          description={t('admin.support.waitingCount', {
            count: queue.data?.meta.waiting_on_support ?? 0,
          })}
        >
          <div className="mb-3">
            <label className="flex max-w-xs flex-col gap-1 text-sm">
              <span>{t('support.status')}</span>
              <select
                className="rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2"
                value={status}
                onChange={(event) => { setStatus(event.target.value) }}
              >
                <option value="">{t('admin.support.waitingOnUs')}</option>
                {['open', 'waiting_for_support', 'waiting_for_customer', 'resolved', 'closed'].map(
                  (option) => (
                    <option key={option} value={option}>
                      {t(`support.statuses.${option}`)}
                    </option>
                  ),
                )}
              </select>
            </label>
          </div>

          {queue.isPending ? (
            <Loading />
          ) : (
            <DataTable
              columns={columns}
              rows={queue.data?.data ?? []}
              rowKey={(row) => row.id}
              empty={t('admin.support.queueEmpty')}
            />
          )}
        </Card>

        {ticket !== null ? (
          <Card title={ticket.subject} description={`${ticket.reference} · ${ticket.customer_name ?? ''}`}>
            {ticket.reopened_count > 0 ? (
              <div className="mb-3">
                {/*
                  A ticket resolved and reopened four times was never fixed.
                  Worth seeing without reading the whole thread.
                */}
                <Alert tone="warning">
                  {t('admin.support.reopenedTimes', { count: ticket.reopened_count })}
                </Alert>
              </div>
            ) : null}

            <ol className="mb-4 flex flex-col gap-3">
              {ticket.messages.map((message) => (
                <li
                  key={message.id}
                  className={
                    message.is_internal_note
                      ? 'rounded-md border border-amber-500/40 bg-amber-500/10 p-3'
                      : 'rounded-md border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-3'
                  }
                >
                  <div className="mb-1 flex justify-between gap-4 text-xs text-[var(--text-muted)]">
                    <span>
                      {message.author ?? '—'}
                      {message.is_internal_note ? ` · ${t('admin.support.internalNote')}` : ''}
                    </span>
                    <span>{formatDateTime(message.created_at, locale)}</span>
                  </div>
                  <p className="whitespace-pre-wrap text-sm">{message.body}</p>
                </li>
              ))}
            </ol>

            <form
              className="flex flex-col gap-3"
              noValidate
              onSubmit={(event) => {
                event.preventDefault()
                reply.mutate(
                  { id: ticket.id, body, internal_note: isNote },
                  { onSuccess: () => { setBody('') } },
                )
              }}
            >
              <textarea
                aria-label={isNote ? t('admin.support.internalNote') : t('admin.support.reply')}
                className={
                  isNote
                    ? 'min-h-24 rounded-md border border-amber-500/50 bg-amber-500/5 px-3 py-2'
                    : 'min-h-24 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2'
                }
                value={body}
                onChange={(event) => { setBody(event.target.value) }}
                required
              />

              {/*
                The one control on this screen that must never be pressed by
                accident: a note the customer was meant to read is a
                non-answer, and a reply that was meant to be a note is a
                disclosure. The box changes colour with it.
              */}
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={isNote}
                  onChange={(event) => { setIsNote(event.target.checked) }}
                />
                <span>{t('admin.support.sendAsNote')}</span>
              </label>

              {isNote ? <Alert tone="warning">{t('admin.support.noteWarning')}</Alert> : null}

              <div className="flex flex-wrap gap-2">
                <Button type="submit" loading={reply.isPending}>
                  {isNote ? t('admin.support.addNote') : t('admin.support.reply')}
                </Button>

                <select
                  aria-label={t('admin.support.priority')}
                  className="rounded-md border border-[var(--border)] bg-[var(--surface)] px-2 py-1 text-sm"
                  value={ticket.priority}
                  onChange={(event) => {
                    prioritise.mutate({ id: ticket.id, priority: event.target.value })
                  }}
                >
                  {['low', 'normal', 'high', 'urgent'].map((option) => (
                    <option key={option} value={option}>
                      {t(`support.priorities.${option}`)}
                    </option>
                  ))}
                </select>

                {ticket.status === 'closed' || ticket.status === 'resolved' ? (
                  <Button
                    type="button"
                    variant="ghost"
                    loading={reopen.isPending}
                    onClick={() => { reopen.mutate(ticket.id) }}
                  >
                    {t('admin.support.reopen')}
                  </Button>
                ) : (
                  <Button
                    type="button"
                    variant="ghost"
                    loading={resolve.isPending}
                    onClick={() => { resolve.mutate(ticket.id) }}
                  >
                    {t('admin.support.resolve')}
                  </Button>
                )}

                {ticket.status !== 'closed' ? (
                  <Button
                    type="button"
                    variant="ghost"
                    loading={close.isPending}
                    onClick={() => { close.mutate(ticket.id) }}
                  >
                    {t('admin.support.close')}
                  </Button>
                ) : null}
              </div>
            </form>
          </Card>
        ) : null}
      </div>
    </>
  )
}
