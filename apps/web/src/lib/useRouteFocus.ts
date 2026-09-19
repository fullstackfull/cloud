import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router'

/**
 * Where focus goes when the customer arrives at a new page.
 *
 * ## What is wrong without it
 *
 * A single-page application navigates by swapping the contents of one
 * document, and the browser has no reason to think anything happened. Two
 * consequences, both measured in Chromium against the real portal:
 *
 *  - **Nothing is announced.** Following a sidebar link replaced the whole
 *    page and a screen reader said nothing at all: no title, no heading, no
 *    landmark. The customer is left pressing Tab to find out whether the link
 *    worked.
 *
 *  - **Focus stays where it was**, which is in the navigation column — so the
 *    next Tab continues through the remaining twenty destinations rather than
 *    into the page that was just opened. The skip link exists for exactly this
 *    twenty-one-stop walk, and it should not have to be used after every
 *    navigation.
 *
 * The browser also keeps the scroll offset across a client-side navigation, so
 * leaving the foot of a long activity feed for the dashboard arrived at the
 * dashboard scrolled halfway down it.
 *
 * ## The strategy, and why this one
 *
 * Move focus to the new page's `<h1>` — or the `<main>` landmark if a page has
 * no heading — once per navigation.
 *
 * One deliberate action with three effects, rather than three mechanisms: the
 * heading is announced *because* focus moved to it, the tab order continues
 * from the content, and the browser scrolls the heading into view, which is
 * where the page starts.
 *
 * Deliberately *not* a live region announcing the page name. A polite region
 * updated on navigation announces on every render that touches it, which is
 * how a portal ends up saying "Dashboard" three times while a poll lands, and
 * it still leaves focus in the navigation column.
 *
 * Three things it does not do, each of them a decision:
 *
 *  - **Not on the first load.** The document has just been handed to the
 *    customer at the top; moving focus for them would skip the skip link they
 *    are about to reach, and a page that steals focus while it loads is a page
 *    that loses whatever was being typed into an autofocused field.
 *
 *  - **Not on a query-string or fragment change.** Filters, paging and sorting
 *    live in the query string, and a customer typing into a filter box that
 *    writes to the URL must not have the focus pulled out from under them
 *    between keystrokes. The pathname is what "a new page" means here.
 *
 *  - **Not while a modal dialogue is open.** The dialogue owns focus for as
 *    long as it is up; it is also inert-adjacent, so the call would either do
 *    nothing or fight the trap in `useModalDialog`.
 */
export function useRouteFocus(): void {
  const { pathname } = useLocation()

  // The first render is an arrival, not a navigation.
  const arrived = useRef(false)

  useEffect(() => {
    if (! arrived.current) {
      arrived.current = true

      return
    }

    if (document.querySelector('dialog[open]') !== null) return

    const main = document.querySelector('main')

    if (main === null) return

    const target = main.querySelector('h1') ?? main

    /*
     * A heading is not focusable by default, and it must not become a tab
     * stop: -1 is what makes it a place focus can be *sent* without adding a
     * stop everybody then has to walk past.
     */
    if (! target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1')

    target.focus()
  }, [pathname])
}
