import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Alert } from '@/components/Alert'
import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { PageHeader } from '@/components/PageHeader'
import { Paginator } from '@/components/Paginator'
import { Loading } from '@/components/Loading'
import { pathForResource } from '@/features/resources/resourcePaths'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { cn } from '@/lib/cn'
import { formatDate } from '@/lib/format'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from '@/lib/queries'
import type { AppNotification } from '@/lib/types'
import { useApiErrorMessage } from '@/lib/useApiErrorMessage'

/**
 * Everything the platform has told this account.
 *
 * The title and body come from the API already rendered in the reader's
 * language — nothing here assembles a sentence. That is not laziness: Arabic
 * orders the words differently and inflects around them, so a client that
 * built "Your {name} is ready" from pieces would produce something no
 * translator could repair.
 *
 * There is no delete. A customer who could remove the record of their service
 * being terminated would be deleting the only copy they have of what happened
 * to their account.
 *
 * Since Wave 3 a row opens the thing it is about rather than the list it is
 * on: the API resolves its stored subject into a `{kind, id}` handle and this
 * page owns the kind-to-route map. Where the API published no handle — a
 * notification about the account itself, or one whose subject the platform can
 * no longer resolve — the collection link it always sent is used instead.
 * Nothing here infers a destination from the title or the body: a deep link
 * guessed from text is a link to somebody else's resource waiting to happen.
 */
export function NotificationsPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const describeError = useApiErrorMessage()

  const [page, setPage] = useState(1)
  const [unreadOnly, setUnreadOnly] = useState(false)

  const { data, isPending, error: readError } = useNotifications(page, unreadOnly)
  const markRead = useMarkNotificationRead()
  const markAll = useMarkAllNotificationsRead()

  const rows = data?.data ?? []
  const unread = data?.meta.unread ?? 0
  const failure = describeError(markRead.error ?? markAll.error)

  return (
    <>
      <PageHeader
        title={t('nav.notifications')}
        description={t('notifications.subtitle')}
      />

      <LoadFailure error={readError} />

      {failure === null ? null : (
        <div className="mb-3">
          <Alert tone="error" requestId={failure.requestId}>{failure.message}</Alert>
        </div>
      )}

      <Card>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant={unreadOnly ? 'primary' : 'ghost'}
              onClick={() => {
                setUnreadOnly((only) => ! only)
                setPage(1)
              }}
              aria-pressed={unreadOnly}
            >
              {t('notifications.unreadOnly')}
            </Button>
            <span className="text-sm text-[var(--text-muted)]">
              {t('notifications.unreadCount', { count: unread })}
            </span>
          </div>

          <Button
            size="sm"
            variant="ghost"
            disabled={unread === 0}
            loading={markAll.isPending}
            onClick={() => { markAll.mutate(); }}
          >
            {t('notifications.markAllRead')}
          </Button>
        </div>

        {isPending ? (
          <Loading />
        ) : rows.length === 0 ? (
          <EmptyState>{t('notifications.empty')}</EmptyState>
        ) : (
          <>
            <ul className="divide-y divide-[var(--border-subtle)]">
              {rows.map((n: AppNotification) => {
                const destination = destinationFor(n)

                return (
                  <li key={n.id} className="flex items-start gap-3 py-4">
                    {/*
                      * An unread marker that is not colour alone: the dot is
                      * accompanied by the row's own aria-label, so a reader who
                      * cannot see it is still told.
                      */}
                    <span
                      aria-hidden="true"
                      className={cn(
                        'mt-1.5 size-2 shrink-0 rounded-full',
                        n.read_at === null
                          ? n.is_failure ? 'bg-red-500' : 'bg-[var(--accent)]'
                          : 'bg-transparent',
                      )}
                    />

                    <div className="min-w-0 flex-1">
                      <p
                        className={cn(
                          'text-sm',
                          n.read_at === null ? 'font-medium text-[var(--text-primary)]' : 'text-[var(--text-secondary)]',
                        )}
                      >
                        {n.title}
                      </p>
                      <p className="mt-0.5 text-sm text-[var(--text-muted)]">{n.body}</p>
                      <p className="mt-1 text-xs text-[var(--text-muted)]">
                        {formatDate(n.created_at, locale)}
                        {n.read_at === null ? ` · ${t('notifications.unread')}` : ''}
                      </p>
                    </div>

                    <div className="flex shrink-0 gap-1">
                      {destination === null ? null : (
                        // A real link, not a button that navigates: the row
                        // points at a service or an invoice, and a customer
                        // should be able to open it in a new tab like anything
                        // else in the portal.
                        <Link
                          to={destination}
                          className="rounded-lg px-2 py-1 text-sm text-[var(--accent)] hover:underline"
                        >
                          {t('notifications.open')}
                        </Link>
                      )}
                      {n.read_at === null ? (
                        <Button
                          size="sm"
                          variant="ghost"
                          loading={markRead.isPending && markRead.variables === n.id}
                          onClick={() => { markRead.mutate(n.id); }}
                        >
                          {t('notifications.markRead')}
                        </Button>
                      ) : null}
                    </div>
                  </li>
                )
              })}
            </ul>

            <Paginator
              page={data?.meta.page ?? 1}
              lastPage={data?.meta.last_page ?? 1}
              total={data?.meta.total ?? 0}
              onChange={setPage}
            />
          </>
        )}
      </Card>
    </>
  )
}

/**
 * Where one notification's "Open" goes.
 *
 * The resource handle first, because it is the exact thing; the collection
 * link second, because it is at least true. A kind this portal has no page for
 * falls through to the link as well, so a family added to the API before the
 * portal has a screen for it does not produce a link to nowhere.
 */
function destinationFor(notification: AppNotification): string | null {
  if (notification.resource !== null) {
    const path = pathForResource(notification.resource.kind, notification.resource.id)

    if (path !== null) return path
  }

  return notification.link
}
