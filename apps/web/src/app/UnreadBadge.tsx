import { useTranslation } from 'react-i18next'

import { useActiveLocale } from '@/i18n/useActiveLocale'
import { formatNumber } from '@/lib/format'
import { useUnreadNotificationCount } from '@/lib/queries'

/**
 * How many notifications are waiting, beside the link that opens them.
 *
 * AS-11. The inbox had no count anywhere in the shell, so the only way to
 * discover that something had happened was to visit the page and look — which
 * is the same as not being told. The number comes from an endpoint that
 * returns one integer rather than from a page of the inbox, and it is keyed
 * under `notifications` so marking one read moves it.
 *
 * Two details make it readable rather than decorative:
 *
 *  - The count is part of the link's accessible name. A badge that is only a
 *    coloured circle with a digit in it announces as "Notifications 3", which
 *    a screen reader user has to guess at; "Notifications, 3 unread" is the
 *    sentence. The visible digit is `aria-hidden` and the sentence is
 *    screen-reader text, so neither reader hears it twice.
 *
 *  - It caps at 99+. Not for space — a four-digit badge fits — but because
 *    the difference between 142 and 143 unread notifications is not a
 *    difference anybody acts on, and rendering it suggests it is.
 */
const CAP = 99

export function UnreadBadge() {
  const { t } = useTranslation()
  const locale = useActiveLocale()
  const { data: unread } = useUnreadNotificationCount()

  // Nothing while the count is unknown, and nothing at zero: an empty badge is
  // a thing to look at that says nothing happened.
  if (unread === undefined || unread === 0) return null

  const shown = unread > CAP ? `${formatNumber(CAP, locale)}+` : formatNumber(unread, locale)

  return (
    <span className="ms-auto inline-flex items-center">
      <span
        aria-hidden="true"
        className="inline-flex min-w-5 items-center justify-center rounded-full bg-brand-600 px-1.5 py-0.5 text-xs font-semibold text-white"
      >
        {shown}
      </span>

      {/*
        No punctuation. The leading comma this used to carry was added against
        a joining problem that the `aria-hidden` digit above had already
        solved: with the digit excluded from the name computation, the label
        and this text are the two remaining nodes and the algorithm joins them
        with a space of its own. The comma therefore arrived *on top of* that
        space, and the link's real accessible name — read out of the browser's
        own accessibility tree, not guessed — was "Notifications , 1 unread",
        which a screen reader announces with an audible "comma" in it.

        Measured after the change: "Notifications 1 unread".
      */}
      <span className="sr-only">{t('notifications.unreadCount', { count: unread })}</span>
    </span>
  )
}
