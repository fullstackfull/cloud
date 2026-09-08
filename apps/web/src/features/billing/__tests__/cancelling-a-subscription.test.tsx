import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { SubscriptionsPage } from '@/features/billing/SubscriptionsPage'
import '@/i18n'

/**
 * Ending a subscription is the act that decides when somebody's data is
 * destroyed. Before this it was one click with no sentence attached, so what
 * these assert is that the customer is told both dates — the day the service
 * stops and the day what is on it goes — before they confirm.
 */

const SUBSCRIPTION = {
  id: '01JSUBSCRIPTION',
  status: 'active',
  currency: 'KWD',
  billing_period: 'monthly',
  recurring_amount: { amount: 9000, currency: 'KWD' },
  plan_id: '01JPLAN',
  current_period_end: '2026-04-01T00:00:00+00:00',
  next_invoice_at: '2026-04-01T00:00:00+00:00',
  auto_renew: true,
  is_scheduled_to_cancel: false,
  service_is_running: true,
  data_retention_days: 30,
}

const PAGE_META = { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 }

function stubFetch(onCancel?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/cancel')) {
      onCancel?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { ...SUBSCRIPTION, is_scheduled_to_cancel: true } }
    } else if (path.endsWith('/subscriptions')) {
      body = { data: [SUBSCRIPTION], meta: PAGE_META }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  // The page links to the plan-change screen, so it needs a router context.
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <SubscriptionsPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

describe('cancelling a subscription', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says when the service stops and when the data goes, before anything is confirmed', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^cancel$/i }))

    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByText(/will not renew/i)).toBeInTheDocument()

    // The second date is the one customers ring up about, and the number comes
    // from the server rather than being written into the portal.
    expect(within(dialog).getByText(/kept for 30 days/i)).toBeInTheDocument()
  })

  it('schedules by default rather than ending anything today', async () => {
    const cancelled = vi.fn()
    vi.stubGlobal('fetch', stubFetch(cancelled))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^cancel$/i }))

    const dialog = await screen.findByRole('dialog')
    // Deliberately not "Cancel": that word is the dismiss button, and in a
    // dialogue it means "do not do this".
    await user.click(within(dialog).getByRole('button', { name: /^end subscription$/i }))

    await waitFor(() => {
      expect(cancelled).toHaveBeenCalledWith({ immediately: false })
    })
  })

  it('will not end one today until the subscription reference is typed back', async () => {
    const cancelled = vi.fn()
    vi.stubGlobal('fetch', stubFetch(cancelled))
    const user = userEvent.setup()

    renderPage()

    await user.click(await screen.findByRole('button', { name: /^cancel$/i }))

    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('checkbox'))

    // The sentence changes with the choice: what is lost is different.
    expect(within(dialog).getByText(/is not refunded/i)).toBeInTheDocument()

    const confirm = within(dialog).getByRole('button', { name: /^end it now$/i })
    expect(confirm).toBeDisabled()

    await user.type(within(dialog).getByRole('textbox'), '01JSUBSCRIPTION')
    expect(confirm).toBeEnabled()

    await user.click(confirm)

    await waitFor(() => {
      expect(cancelled).toHaveBeenCalledWith({
        immediately: true,
        confirm_subscription_id: '01JSUBSCRIPTION',
      })
    })
  })
})
