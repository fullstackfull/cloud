import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ActivityPage } from '@/features/activity/ActivityPage'
import '@/i18n'
import type { ActivityItem } from '@/lib/types'

/**
 * AR-13: one account-wide history, read from the server.
 *
 * What is asserted here is what the page must not do as much as what it must.
 * It must not filter in the browser — the category has to reach the server, or
 * a filtered page is whatever survived trimming an unfiltered one. It must not
 * number its pages. It must not guess who did something. And it must not
 * report `indeterminate` as a failure or offer a retry beside it.
 */

function item(overrides: Partial<ActivityItem> = {}): ActivityItem {
  return {
    id: 'provisioning_job:01JJOB',
    occurred_at: new Date(Date.now() - 3_600_000).toISOString(),
    category: 'cloud',
    message_code: 'activity.vps.restarted',
    state: 'succeeded',
    is_terminal: true,
    needs_attention: false,
    retry_advice: 'not_retryable',
    actor: { type: 'customer_user', display_name: 'Ahmed' },
    resource: { kind: 'vps', id: '01JVM', identity: 'web-01' },
    reference: null,
    ...overrides,
  }
}

/** Every request the page makes, with its query string, in order. */
const asked: string[] = []

function serve(pages: Record<string, { data: ActivityItem[]; meta: unknown }>) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    asked.push(url)

    const match = Object.keys(pages).find((candidate) => url.includes(candidate))
    const body = match === undefined ? { data: [], meta: { per_page: 25, next_cursor: null, has_more: false } } : pages[match]

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response)
  })
}

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <ActivityPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('what happened to this account', () => {
  afterEach(() => {
    asked.length = 0
    vi.unstubAllGlobals()
  })

  it('names the person who asked, from the field rather than from a guess', async () => {
    vi.stubGlobal(
      'fetch',
      serve({
        '/activity': {
          data: [
            item(),
            item({
              id: 'provisioning_job:02JJOB',
              actor: { type: 'system', display_name: null },
              message_code: 'activity.backup.taken',
              category: 'backups',
            }),
            item({
              id: 'provisioning_job:03JJOB',
              actor: { type: 'unknown', display_name: null },
              message_code: 'activity.vps.stopped',
            }),
          ],
          meta: { per_page: 25, next_cursor: null, has_more: false },
        },
      }),
    )

    mount()

    // A team of three can read back which of them did it.
    expect(await screen.findByText('Ahmed')).toBeInTheDocument()

    // The platform's own scheduled work says so rather than leaving a blank
    // that reads as a missing name.
    expect(screen.getByText('Lynomia')).toBeInTheDocument()

    // And work whose requester was never recorded is not attributed to
    // whoever happens to be reading.
    expect(screen.getByText('Not recorded')).toBeInTheDocument()
  })

  it('sends the category to the server instead of filtering the page it has', async () => {
    vi.stubGlobal(
      'fetch',
      serve({
        '/activity': {
          data: [item()],
          meta: { per_page: 25, next_cursor: null, has_more: false },
        },
      }),
    )

    mount()
    await screen.findByText('Cloud server restarted')

    fireEvent.click(screen.getByRole('button', { name: 'Support' }))

    await vi.waitFor(() => {
      expect(asked.some((url) => url.includes('category=support'))).toBe(true)
    })

    /*
     * The filter chooses which of ten sources the server reads. Trimming the
     * rows in the browser would mean a filtered page holding however many of
     * twenty-five rows happened to match — which on a busy account is none.
     */
    expect(asked.filter((url) => url.includes('category=support'))).toHaveLength(1)
  })

  it('walks the feed by cursor and never by page number', async () => {
    vi.stubGlobal(
      'fetch',
      serve({
        'cursor=': {
          data: [item({ id: 'provisioning_job:04JJOB', message_code: 'activity.vps.created' })],
          meta: { per_page: 25, next_cursor: null, has_more: false },
        },
        '/activity': {
          data: [item()],
          meta: { per_page: 25, next_cursor: 'b3BhcXVl', has_more: true },
        },
      }),
    )

    mount()
    await screen.findByText('Cloud server restarted')

    fireEvent.click(screen.getByRole('button', { name: 'Show older' }))

    await screen.findByText('Cloud server created')

    expect(asked.some((url) => url.includes('cursor=b3BhcXVl'))).toBe(true)

    // An offset into a time-ordered union that gains rows as you read is not a
    // stable address, and page 4 of it costs the server the whole history.
    expect(asked.some((url) => /[?&]page=/.test(url))).toBe(false)
  })

  it('offers support beside a row that stopped, and nothing beside one that did not', async () => {
    vi.stubGlobal(
      'fetch',
      serve({
        '/activity': {
          data: [
            item({
              id: 'domain_operation:01JDOP',
              category: 'domains',
              message_code: 'activity.domain.registered',
              state: 'indeterminate',
              needs_attention: true,
              retry_advice: 'support_required',
              resource: { kind: 'domain', id: '01JDOM', identity: 'lynomia.test' },
            }),
            item(),
          ],
          meta: { per_page: 25, next_cursor: null, has_more: false },
        },
      }),
    )

    mount()

    expect(await screen.findByText('Domain registered')).toBeInTheDocument()

    /*
     * One row stopped and one did not, so there is exactly one way into
     * support — and it carries what it is about.
     */
    const links = screen.getAllByRole('link', { name: 'Ask support about this' })
    expect(links).toHaveLength(1)
    expect(links[0]).toHaveAttribute('href', expect.stringContaining('about=activity.domain'))
    expect(links[0]).toHaveAttribute('href', expect.stringContaining('identity=lynomia.test'))

    // And there is no retry anywhere near an outcome nobody knows.
    expect(screen.queryByRole('button', { name: /try again/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /try again/i })).not.toBeInTheDocument()
  })

  it('opens the thing a row is about, through the one routing map', async () => {
    vi.stubGlobal(
      'fetch',
      serve({
        '/activity': {
          data: [item()],
          meta: { per_page: 25, next_cursor: null, has_more: false },
        },
      }),
    )

    mount()

    const link = await screen.findByRole('link', { name: 'web-01' })
    expect(link).toHaveAttribute('href', '/vps/01JVM')
  })

  it('says the account is quiet rather than showing an error', async () => {
    vi.stubGlobal('fetch', serve({}))

    mount()

    expect(await screen.findByText('Nothing has happened on this account yet.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
