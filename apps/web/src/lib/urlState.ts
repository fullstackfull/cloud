import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'

/**
 * List state that belongs in the address bar, and the reading that makes it
 * safe to put there.
 *
 * ## Why it is in the URL at all
 *
 * W5.7. Every customer list held its page number, and the activity feed held
 * its filter and its cursor, in component state. Four things a customer does
 * were therefore broken in the same way, and all four look like the portal
 * forgetting rather than like a defect:
 *
 *  - refreshing page three of the invoices returned page one;
 *  - opening an invoice from page three and pressing Back returned page one;
 *  - filtering the activity feed to "billing", opening the invoice it
 *    mentioned and pressing Back returned the unfiltered newest page;
 *  - sending somebody "the domains I have on page two" was not possible.
 *
 * The browser already has a mechanism for all of this. What the portal had to
 * do was stop holding the state somewhere the browser cannot see.
 *
 * ## What does not go in
 *
 * Anything private or transient: a password, a form draft, a token, the
 * phrase typed into a destructive confirmation, whether a dialogue is open, a
 * toast. A URL is copied, shared, logged by proxies and kept in history; it is
 * the least private place in the product. The rule this module follows is that
 * the URL carries *where the customer is looking*, never *what they are doing*.
 *
 * ## Reading is validation, not parsing
 *
 * Everything here comes off the wire: a customer edits it, a link is
 * truncated, a proxy re-encodes it. So every read is bounded — a page number
 * is a positive integer under a ceiling, a choice is a member of a declared
 * set, text has a maximum length — and an unreadable value reads as its
 * default rather than as an error. The one thing that must never happen is a
 * URL that produces a broken screen, because the customer cannot tell whether
 * they broke it or the portal did.
 */

/** The most pages a list can be asked for, so `?page=1e9` cannot ask for one. */
const MAX_PAGE = 10_000

/** The longest opaque cursor accepted, matching the server's own limit. */
const MAX_CURSOR = 512

interface UrlParams {
  /** A positive integer, or the fallback when absent or unreadable. */
  number: (name: string, fallback: number) => number
  /** A member of `allowed`, or null when absent or unrecognised. */
  choice: <T extends string>(name: string, allowed: readonly T[]) => T | null
  /** Present and not `0`/`false`. */
  flag: (name: string) => boolean
  /** Bounded free text — a search term — or null. */
  text: (name: string, maxLength?: number) => string | null
  /**
   * Writes several parameters at once.
   *
   * One call per customer action, because two calls would make two history
   * entries and Back would then need pressing twice to undo one click.
   * A null value removes the parameter.
   *
   * `replace` is for a correction the customer did not make — normalising a
   * value they never typed — which must not become a place Back returns to.
   */
  set: (updates: Record<string, string | number | boolean | null>, options?: { replace?: boolean }) => void
}

export function useUrlParams(): UrlParams {
  const [params, setParams] = useSearchParams()

  const set = useCallback(
    (
      updates: Record<string, string | number | boolean | null>,
      options: { replace?: boolean } = {},
    ) => {
      setParams(
        (current) => {
          // Copied rather than mutated: the setter is given the live object and
          // react-router compares what comes back.
          const next = new URLSearchParams(current)

          for (const [name, value] of Object.entries(updates)) {
            if (value === null || value === false || value === '') {
              next.delete(name)
            } else {
              next.set(name, String(value))
            }
          }

          return next
        },
        { replace: options.replace ?? false },
      )
    },
    [setParams],
  )

  return useMemo(
    () => ({
      number: (name, fallback) => {
        const raw = params.get(name)

        if (raw === null) return fallback

        // `Number.parseInt` accepts "3 pages" and "3abc"; a page number is the
        // whole value or it is nothing.
        if (! /^[0-9]{1,6}$/.test(raw)) return fallback

        const value = Number.parseInt(raw, 10)

        return value >= 1 && value <= MAX_PAGE ? value : fallback
      },

      choice: <T extends string>(name: string, allowed: readonly T[]): T | null => {
        const raw = params.get(name)

        return raw !== null && (allowed as readonly string[]).includes(raw) ? (raw as T) : null
      },

      flag: (name) => {
        const raw = params.get(name)

        return raw !== null && raw !== '0' && raw !== 'false'
      },

      text: (name, maxLength = 512) => {
        const raw = params.get(name)

        if (raw === null) return null

        const trimmed = raw.trim()

        return trimmed === '' || trimmed.length > maxLength ? null : trimmed
      },

      set,
    }),
    [params, set],
  )
}

/**
 * A list's page number, in the URL.
 *
 * Pushed rather than replaced, so that paging forward and then pressing Back
 * returns to the page the customer came from — which is the whole reason this
 * is not component state.
 */
export function useUrlPage(name = 'page'): [number, (page: number) => void] {
  const params = useUrlParams()
  const page = params.number(name, 1)

  const go = useCallback(
    (next: number) => {
      // Page one is the absence of the parameter: a clean URL for the first
      // page is what anybody sharing a list expects to get.
      params.set({ [name]: next <= 1 ? null : next })
    },
    [name, params],
  )

  return [page, go]
}

/**
 * An opaque forward-only cursor, in the URL.
 *
 * The cursor is the server's and is never composed here. A mangled one is
 * still sent: the API is explicit that an unreadable cursor means "the newest
 * page", which is what somebody who pasted half a URL should get rather than
 * an error screen. What this does enforce is a length bound, so a megabyte in
 * the address bar is not sent to the API at all.
 */
export function useUrlCursor(name = 'cursor'): [string | null, (cursor: string | null) => void] {
  const params = useUrlParams()
  const cursor = params.text(name, MAX_CURSOR)

  const go = useCallback(
    (next: string | null) => {
      params.set({ [name]: next })
    },
    [name, params],
  )

  return [cursor, go]
}
