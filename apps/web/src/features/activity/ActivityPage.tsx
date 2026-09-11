import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Button } from '@/components/Button'
import { Card } from '@/components/Card'
import { EmptyState } from '@/components/EmptyState'
import { LoadFailure } from '@/components/LoadFailure'
import { Loading } from '@/components/Loading'
import { PageHeader } from '@/components/PageHeader'
import { StatusBadge } from '@/components/StatusBadge'
import { pathForResource } from '@/features/resources/resourcePaths'
import { supportPathFor } from '@/features/support/supportContext'
import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatDateTime, formatRelative } from '@/lib/format'
import { useActivity } from '@/lib/queries'
import type { ActivityCategory, ActivityItem } from '@/lib/types'

/**
 * What has happened to this account.
 *
 * AR-13. Every history in the portal was a resource's own: to answer "who
 * rebooted the server at 3am" a customer had to open each machine in turn, and
 * to answer "what happened to my account last week" they had to open all of
 * them. The notification inbox was not that record either — it is what the
 * platform chose to send, it can be marked read, and marking a message read
 * must not erase the event.
 *
 * The feed is one server read over ten durable tables. What the browser does
 * not do:
 *
 *  - **It does not stitch.** No loop over resources collecting their events,
 *    which would be nine requests, an arbitrary merge order, and a different
 *    answer depending on how many machines the account has.
 *
 *  - **It does not filter.** The category is a query parameter, so the server
 *    reads one source family instead of ten and a filtered page is a full page
 *    of matching rows rather than whatever survived trimming an unfiltered
 *    one.
 *
 *  - **It does not number pages.** The feed is ordered by time over a union;
 *    "page 4" of something that gains rows as you read is not a stable
 *    address, and an offset into it costs the server the whole history to
 *    skip. The cursor is opaque, and walking forwards is the only direction
 *    offered — which is what the API supports rather than a limitation this
 *    page invented.
 *
 * Each row names the person who asked, where the platform recorded one. Not
 * inferred from who was signed in when the timestamp says: the actor is a
 * typed field on the source row, and `Not recorded` is the honest answer for
 * work that predates the column.
 */
export function ActivityPage() {
  const { t } = useTranslation()
  const locale = useActiveLocale()

  const [category, setCategory] = useState<ActivityCategory | null>(null)

  /*
   * The cursors walked so far, so "Show older" can go forward and the browser
   * back button is not the only way back. The last element is the page being
   * shown; `null` is the newest page.
   */
  const [trail, setTrail] = useState<Array<string | null>>([null])
  const cursor = trail[trail.length - 1] ?? null

  const { data, isPending, error } = useActivity(category, cursor)

  const rows = data?.data ?? []
  const hasMore = data?.meta.has_more ?? false
  const nextCursor = data?.meta.next_cursor ?? null

  function choose(next: ActivityCategory | null): void {
    setCategory(next)

    // A filter is a different feed, so it starts at its own newest page rather
    // than at a cursor that named a row in the unfiltered one.
    setTrail([null])
  }

  return (
    <>
      <PageHeader title={t('activity.feed.title')} description={t('activity.feed.subtitle')} />

      <LoadFailure error={error} />

      <Card>
        {/*
          The filter is a row of buttons rather than a select, because there
          are six categories and a customer choosing one is choosing where to
          look rather than submitting a form. `aria-pressed` says which is
          chosen without relying on the colour.
        */}
        <div className="mb-4 flex flex-wrap gap-2">
          <Button
            size="sm"
            variant={category === null ? 'primary' : 'ghost'}
            aria-pressed={category === null}
            onClick={() => { choose(null); }}
          >
            {t('activity.feed.all')}
          </Button>

          {CATEGORIES.map((value) => (
            <Button
              key={value}
              size="sm"
              variant={category === value ? 'primary' : 'ghost'}
              aria-pressed={category === value}
              onClick={() => { choose(value); }}
            >
              {t(`activity.categories.${value}`)}
            </Button>
          ))}
        </div>

        {isPending ? (
          <Loading />
        ) : rows.length === 0 ? (
          <EmptyState>
            {category === null ? t('activity.feed.empty') : t('activity.feed.emptyFiltered')}
          </EmptyState>
        ) : (
          <>
            <ul className="divide-y divide-[var(--border-subtle)]">
              {rows.map((item) => (
                <ActivityRow key={item.id} item={item} locale={locale} />
              ))}
            </ul>

            <div className="mt-4 flex items-center justify-between gap-3">
              <Button
                size="sm"
                variant="ghost"
                disabled={trail.length === 1}
                onClick={() => { setTrail((walked) => walked.slice(0, -1)); }}
              >
                {t('common.previous')}
              </Button>

              <Button
                size="sm"
                variant="ghost"
                disabled={!hasMore || nextCursor === null}
                onClick={() => {
                  if (nextCursor !== null) setTrail((walked) => [...walked, nextCursor])
                }}
              >
                {t('activity.feed.loadMore')}
              </Button>
            </div>
          </>
        )}
      </Card>
    </>
  )
}

const CATEGORIES: readonly ActivityCategory[] = [
  'cloud',
  'hosting',
  'domains',
  'billing',
  'support',
  'backups',
]

function ActivityRow({ item, locale }: { item: ActivityItem; locale: 'en' | 'ar' }) {
  const { t } = useTranslation()

  const destination =
    item.resource === null ? null : pathForResource(item.resource.kind, item.resource.id)

  return (
    <li className="flex flex-col gap-1 py-4 sm:flex-row sm:items-start sm:gap-4">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-[var(--text-primary)]">
          {/*
            The server sent a key, not a sentence. Arabic orders these words
            differently and inflects around them, so a client that assembled
            "Your {thing} was {verb}" from pieces would produce something no
            translator could repair.
          */}
          {t(item.message_code)}
        </p>

        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-[var(--text-muted)]">
          {/*
            The identity the customer knows the thing by, and a link to it
            where the portal has a page. `dir="ltr"` because a hostname or a
            domain is a technical identifier and must not be reordered inside
            an Arabic line.
          */}
          {item.resource === null ? null : destination === null ? (
            <span className="technical" dir="ltr">
              {item.resource.identity}
            </span>
          ) : (
            <Link to={destination} className="text-[var(--accent)] hover:underline">
              <span className="technical" dir="ltr">
                {item.resource.identity ?? item.resource.id}
              </span>
            </Link>
          )}

          <span>{actorName(item, t)}</span>

          {item.reference === null ? null : (
            <span className="technical" dir="ltr">
              {item.reference}
            </span>
          )}
        </p>
      </div>

      <div className="flex shrink-0 flex-col items-start gap-1 sm:items-end">
        <StatusBadge status={item.state} />

        {/*
          Relative, because "3 hours ago" is what a feed is read for, with the
          exact instant in the title and in `<time dateTime>` for anybody who
          needs it. The exact one is in the customer's own time zone.
        */}
        <time
          dateTime={item.occurred_at}
          title={formatDateTime(item.occurred_at, locale)}
          className="text-xs text-[var(--text-muted)]"
        >
          {formatRelative(item.occurred_at, locale)}
        </time>

        {/*
          AS-14. The way out of a row that stopped, prefilled from the row
          itself — and only on the rows where there is something to ask about.
          A link to a form, never a submission: nobody opens a ticket by
          reading their own history.
        */}
        {item.needs_attention ? (
          <Link
            to={supportPathFor({
              subjectKey: item.message_code,
              ...(item.resource === null ? {} : { resource: item.resource }),
              ...(item.reference === null ? {} : { reference: item.reference }),
            })}
            className="text-xs font-medium text-[var(--accent)] hover:underline"
          >
            {t('activity.feed.askSupport')}
          </Link>
        ) : null}
      </div>
    </li>
  )
}

/**
 * Who asked for this, in words.
 *
 * Three answers and no fourth. `customer_user` carries a name, which is the
 * whole point of the actor column — a team of three can read back which of
 * them rebooted the server. `system` is the platform's own scheduled work, and
 * saying so is better than leaving a blank that reads as a missing name.
 * `unknown` is work the source row records no requester for, and it is
 * published as itself rather than attributed to whoever happens to be reading.
 */
function actorName(item: ActivityItem, t: (key: string) => string): string {
  if (item.actor.type === 'customer_user' && item.actor.display_name !== null) {
    return item.actor.display_name
  }

  return item.actor.type === 'system'
    ? t('activity.feed.actorSystem')
    : t('activity.feed.actorUnknown')
}
