import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { NavGroups } from '@/app/NavGroups'
import { CUSTOMER_NAV_GROUPS } from '@/app/navigation'
import '@/i18n'

/**
 * AS-11: the count is in the link, and the link says what the count means.
 *
 * The inbox had no indicator anywhere in the shell, so the only way to find
 * out that something had happened was to visit the page — which is the same as
 * not being told. The two things a badge has to get right are both about being
 * read rather than seen: the number belongs to the link's accessible name, and
 * it stops being a number somebody acts on well before three digits.
 */

const asked: string[] = []

function serveCount(unread: number) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    asked.push(url)

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      text: () => Promise.resolve(JSON.stringify({ data: { unread } })),
    } as Response)
  })
}

function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <nav>
          <NavGroups groups={CUSTOMER_NAV_GROUPS} />
        </nav>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('the unread badge', () => {
  afterEach(() => {
    asked.length = 0
    vi.unstubAllGlobals()
  })

  it('puts the count inside the link that opens the inbox', async () => {
    vi.stubGlobal('fetch', serveCount(3))

    mount()

    /*
     * The whole point. A coloured circle beside a link announces as
     * "Notifications" with no number in it; this announces as "Notifications, 3 unread" because the count is a child of the link.
     */
    expect(
      await screen.findByRole('link', { name: 'Notifications, 3 unread' }),
    ).toBeInTheDocument()
  })

  it('reads one integer rather than a page of the inbox', async () => {
    vi.stubGlobal('fetch', serveCount(1))

    mount()

    await screen.findByRole('link', { name: 'Notifications, 1 unread' })

    // The badge is drawn on every page; fetching twenty-five notifications to
    // render a digit is what this endpoint exists to avoid.
    expect(asked).toHaveLength(1)
    expect(asked[0]).toContain('/notifications/unread-count')
  })

  it('stops counting past ninety-nine', async () => {
    vi.stubGlobal('fetch', serveCount(142))

    mount()

    /*
     * Not for space. The difference between 142 and 143 unread notifications
     * is not a difference anybody acts on, and rendering it suggests it is.
     * The accessible name keeps the true number, because a screen reader user
     * asking "how many" deserves the answer.
     */
    expect(await screen.findByText('99+')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Notifications, 142 unread' })).toBeInTheDocument()
  })

  it('shows nothing at all when the inbox is clear', async () => {
    vi.stubGlobal('fetch', serveCount(0))

    mount()

    const link = await screen.findByRole('link', { name: 'Notifications' })

    // An empty badge is a thing to look at that says nothing happened.
    expect(link.textContent).toBe('Notifications')
  })
})
