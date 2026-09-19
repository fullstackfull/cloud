import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router'

import { Button } from '@/components/Button'
import { buttonClasses } from '@/components/buttonStyles'
import { useCurrentUser } from '@/features/auth/useAuth'
import { watchSessionExpiry } from '@/lib/sessionExpiry'
import { useModalDialog } from '@/lib/useModalDialog'

/**
 * What happens when the session ends while somebody is reading.
 *
 * The old behaviour was nothing at all: every query on the page failed with
 * "You are not signed in", the guard did not re-evaluate because the profile
 * query was not refetched, and the customer sat on a screen of red boxes.
 *
 * **The one thing this must never do is resubmit.** A customer who was part
 * way through paying an invoice, rebooting a machine or renewing a domain when
 * their session expired has a request whose fate the portal does not know: it
 * may have been refused before it reached the action, or it may have been
 * accepted and the *next* request refused. Replaying it after they sign in
 * again would be the portal deciding, on their behalf, that it did not happen.
 * So the dialogue says what to do — sign in, then do it again deliberately —
 * and the portal holds nothing to replay.
 *
 * What it does keep is the route, so signing in returns them to the page they
 * were on rather than to the dashboard. The form state on that page survives
 * as long as they sign in in another tab and come back; a full navigation
 * loses it, which the copy does not pretend otherwise.
 *
 * It only appears to somebody who *had* a session. A 401 from the profile
 * probe on the sign-in page is the normal way of finding out that nobody is
 * signed in, and announcing it would show an expiry notice to every visitor.
 */
export function SessionExpiryNotice() {
  const { t } = useTranslation()
  const location = useLocation()
  const { data: user } = useCurrentUser()

  const [expired, setExpired] = useState(false)
  const dialog = useRef<HTMLDialogElement>(null)

  // Opens, closes, and returns focus to whatever it interrupted.
  useModalDialog(dialog, expired, () => { setExpired(false); })

  // Read inside the subscription without re-subscribing on every profile
  // refetch, which would drop and re-add the listener on a timer.
  const signedIn = useRef(false)
  signedIn.current = user !== null && user !== undefined

  useEffect(
    () =>
      watchSessionExpiry(() => {
        if (signedIn.current) setExpired(true)
      }),
    [],
  )

  const returnTo = `${location.pathname}${location.search}`

  return (
    /*
     * A native `<dialog>`, opened with `showModal()`, rather than a div
     * wearing `role="alertdialog"` and `aria-modal="true"`.
     *
     * The div version was a lie told to assistive technology. `aria-modal`
     * announces "nothing outside this matters" and enforces nothing: focus
     * stayed wherever it had been, Tab walked straight out into a page the
     * customer could no longer use, Escape did nothing, and a screen-reader
     * user was told a dialogue had appeared while their cursor sat behind it.
     * The browser gives all of that for free from `showModal()` — the focus
     * trap, the inertness of the page behind, Escape, the top layer, and
     * returning focus where it came from — which is why every other dialogue
     * in this portal is built on it.
     *
     * `alertdialog` stays as the role: this interrupts rather than waits, and
     * it is the one dialogue the customer did not ask for.
     */
    <dialog
      ref={dialog}
      role="alertdialog"
      aria-labelledby="session-expired-title"
      aria-describedby="session-expired-body"
      className={[
        'w-full max-w-md rounded-xl border p-6 backdrop:bg-black/50',
        'border-[var(--border-subtle)] bg-[var(--surface-raised)]',
      ].join(' ')}
    >
      <div>
        <h2 id="session-expired-title" className="text-lg font-semibold text-[var(--text-primary)]">
          {t('session.expiredTitle')}
        </h2>

        <p id="session-expired-body" className="mt-2 text-sm text-[var(--text-secondary)]">
          {t('session.expiredBody')}
        </p>

        {/*
          Said plainly, because it is the part a customer would otherwise
          assume the other way: nothing they started has been repeated, and
          anything they meant to do has to be asked for again.
        */}
        <p className="mt-2 text-sm text-[var(--text-secondary)]">{t('session.expiredNotRepeated')}</p>

        <div className="mt-4 flex flex-wrap justify-end gap-2">
          <Button variant="ghost" onClick={() => { setExpired(false); }}>
            {t('session.expiredDismiss')}
          </Button>

          <a
            href={`/sign-in?return=${encodeURIComponent(returnTo)}`}
            className={buttonClasses('primary')}
          >
            {t('session.expiredSignIn')}
          </a>
        </div>
      </div>
    </dialog>
  )
}
