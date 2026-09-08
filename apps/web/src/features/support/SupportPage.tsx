import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Alert } from '@/components/Alert'
import { Badge } from '@/components/Badge'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { DataTable, type Column } from '@/components/DataTable'
import { Field } from '@/components/Field'
import { PageHeader } from '@/components/PageHeader'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime } from '@/lib/format'
import {
  useCloseTicket,
  useOpenTicket,
  useReplyToTicket,
  useTicket,
  useTickets,
} from '@/lib/queries'
import type { Ticket, TicketPriority, TicketStatus } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * A ticket waiting on the customer is the one they can do something about, and
 * it is the one the colour has to pick out. Everything else is either with the
 * support team or over.
 */
const STATUS_TONES: Record<TicketStatus, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
  open: 'info',
  waiting_for_support: 'info',
  waiting_for_customer: 'warning',
  resolved: 'success',
  closed: 'neutral',
}

/**
 * Support, from the customer's side.
 *
 * One page rather than a list and a detail route, because a support thread is
 * short and the thing a customer wants is to read the last reply and answer
 * it. A second navigation between those two is a second chance to lose them.
 */
export function SupportPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const { data: tickets, isPending, error: listError } = useTickets()
  const [openId, setOpenId] = useState<string | null>(null)
  const { data: opened } = useTicket(openId)

  const openTicket = useOpenTicket()
  const reply = useReplyToTicket()
  const close = useCloseTicket()

  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [category, setCategory] = useState('technical')
  const [priority, setPriority] = useState<TicketPriority>('normal')
  const [files, setFiles] = useState<File[]>([])
  const [replyBody, setReplyBody] = useState('')

  const displayed = describeError(listError ?? openTicket.error ?? reply.error ?? close.error)
  const ticket = opened?.data ?? null

  const columns: Array<Column<Ticket>> = [
    {
      key: 'reference',
      header: t('support.reference'),
      ltr: true,
      cell: (row) => <span className="technical">{row.reference}</span>,
    },
    {
      key: 'subject',
      header: t('support.subject'),
      cell: (row) => (
        <button
          type="button"
          className="text-start underline underline-offset-2"
          onClick={() => {
            setOpenId(row.id)
            setReplyBody('')
          }}
        >
          {row.subject}
        </button>
      ),
    },
    {
      key: 'status',
      header: t('support.status'),
      cell: (row) => <Badge tone={STATUS_TONES[row.status]}>{t(`support.statuses.${row.status}`)}</Badge>,
    },
    {
      key: 'priority',
      header: t('support.priority'),
      cell: (row) => t(`support.priorities.${row.priority}`),
    },
    {
      key: 'lastReply',
      header: t('support.lastReply'),
      ltr: true,
      cell: (row) =>
        row.last_reply_at === null ? '—' : formatDateTime(row.last_reply_at, locale),
    },
  ]

  return (
    <>
      <PageHeader title={t('nav.support')} description={t('support.subtitle')} />

      {displayed !== null ? (
        <div className="mb-4">
          <Alert tone="error" requestId={displayed.requestId}>
            {displayed.message}
          </Alert>
        </div>
      ) : null}

      <div className="flex flex-col gap-4">
        <Card title={t('support.yourTickets')}>
          <DataTable
            columns={columns}
            rows={tickets?.data ?? []}
            rowKey={(row) => row.id}
            empty={isPending ? t('common.loading') : t('support.noTickets')}
          />
        </Card>

        {ticket !== null ? (
          <Card title={ticket.subject} description={ticket.reference}>
            <ol className="mb-4 flex flex-col gap-3">
              {ticket.messages.map((message) => (
                <li
                  key={message.id}
                  className="rounded-md border border-[var(--border-subtle)] bg-[var(--surface-sunken)] p-3"
                >
                  <div className="mb-1 flex justify-between gap-4 text-xs text-[var(--text-muted)]">
                    <span>
                      {message.author_kind === 'operator'
                        ? t('support.fromSupport', { name: message.author ?? '' })
                        : (message.author ?? t('support.fromYou'))}
                    </span>
                    <span dir="ltr">{formatDateTime(message.created_at, locale)}</span>
                  </div>
                  <p className="whitespace-pre-wrap text-sm">{message.body}</p>
                  {message.attachments.length > 0 ? (
                    <ul className="mt-2 flex flex-wrap gap-2">
                      {message.attachments.map((attachment) => (
                        <li key={attachment.id}>
                          {/*
                            A normal link to an endpoint that checks who is
                            asking. Nothing about where the file lives is in
                            the response, so there is no path here to alter.
                          */}
                          <a
                            className="text-xs underline underline-offset-2"
                            href={`/api/v1/support/attachments/${attachment.id}`}
                          >
                            {attachment.name}
                          </a>
                        </li>
                      ))}
                    </ul>
                  ) : null}
                </li>
              ))}
            </ol>

            {ticket.status === 'closed' ? (
              <Alert tone="info">{t('support.closedExplanation')}</Alert>
            ) : (
              <form
                className="flex flex-col gap-3"
                noValidate
                onSubmit={(event) => {
                  event.preventDefault()
                  reply.mutate(
                    { id: ticket.id, body: replyBody, files: [] },
                    { onSuccess: () => { setReplyBody('') } },
                  )
                }}
              >
                <label className="flex flex-col gap-1 text-sm">
                  <span>{t('support.yourReply')}</span>
                  <textarea
                    className="min-h-24 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2"
                    value={replyBody}
                    onChange={(event) => { setReplyBody(event.target.value) }}
                    required
                  />
                </label>

                <div className="flex gap-2">
                  <Button type="submit" loading={reply.isPending}>
                    {t('support.send')}
                  </Button>
                  {/*
                    Closing is the customer's to do and resolving is not:
                    resolved is the support team's opinion that the problem is
                    solved, and closed is this account saying it is finished.
                  */}
                  <Button
                    type="button"
                    variant="ghost"
                    loading={close.isPending}
                    onClick={() => { close.mutate(ticket.id) }}
                  >
                    {t('support.close')}
                  </Button>
                </div>
              </form>
            )}
          </Card>
        ) : null}

        <Card title={t('support.newTitle')} description={t('support.newSubtitle')}>
          <form
            className="flex max-w-2xl flex-col gap-3"
            noValidate
            onSubmit={(event) => {
              event.preventDefault()
              openTicket.mutate(
                { subject, body, category, priority, files },
                {
                  onSuccess: (created) => {
                    setSubject('')
                    setBody('')
                    setFiles([])
                    setOpenId(created.data.id)
                  },
                },
              )
            }}
          >
            <Field
              label={t('support.subject')}
              value={subject}
              onChange={(event) => { setSubject(event.target.value) }}
              required
              error={displayed?.fields?.['subject']?.[0]}
            />

            <div className="flex flex-wrap gap-3">
              <label className="flex flex-col gap-1 text-sm">
                <span>{t('support.category')}</span>
                <select
                  className="rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2"
                  value={category}
                  onChange={(event) => { setCategory(event.target.value) }}
                >
                  {['technical', 'billing', 'provisioning', 'abuse', 'other'].map((option) => (
                    <option key={option} value={option}>
                      {t(`support.categories.${option}`)}
                    </option>
                  ))}
                </select>
              </label>

              <label className="flex flex-col gap-1 text-sm">
                <span>{t('support.priority')}</span>
                {/*
                  Three options, not four. Urgent is what pages somebody out of
                  hours, and a priority a customer can select for themselves
                  stops meaning anything within a month — so it is an
                  operator's judgement, and the backend refuses it here.
                */}
                <select
                  className="rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2"
                  value={priority}
                  onChange={(event) => { setPriority(event.target.value as TicketPriority) }}
                >
                  {(['low', 'normal', 'high'] as const).map((option) => (
                    <option key={option} value={option}>
                      {t(`support.priorities.${option}`)}
                    </option>
                  ))}
                </select>
              </label>
            </div>

            <label className="flex flex-col gap-1 text-sm">
              <span>{t('support.describe')}</span>
              <textarea
                className="min-h-32 rounded-md border border-[var(--border)] bg-[var(--surface)] px-3 py-2"
                value={body}
                onChange={(event) => { setBody(event.target.value) }}
                required
              />
            </label>

            <label className="flex flex-col gap-1 text-sm">
              <span>{t('support.attachments')}</span>
              <input
                type="file"
                multiple
                className="text-sm"
                onChange={(event) => { setFiles(Array.from(event.target.files ?? [])) }}
              />
              <span className="text-xs text-[var(--text-muted)]">{t('support.attachmentsHint')}</span>
            </label>

            <div>
              <Button type="submit" loading={openTicket.isPending}>
                {t('support.open')}
              </Button>
            </div>
          </form>
        </Card>
      </div>
    </>
  )
}
