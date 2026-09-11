import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ProfilePage } from '@/features/account/ProfilePage'
import '@/i18n'

/**
 * W5.3. The profile form offers a control for exactly the fields the server
 * accepts, and for nothing else.
 *
 * The classification this holds to is written out in `ProfilePage`, field by
 * field, against the routes and validators it was read from. Two halves are
 * worth asserting and they fail differently:
 *
 *  - A field the server accepts and the form does not offer is a feature
 *    nobody can reach.
 *  - A field the form offers and the server refuses is worse: the customer
 *    types something, presses Save, and is told it worked. The country and
 *    currency are the case with money behind them — an account's currency is
 *    what its catalogue is priced in, so it changes through a request an
 *    operator decides, and the thing to guard against is somebody deciding
 *    that the workflow would be tidier as a dropdown.
 *
 * The server refuses each of them by allow-list regardless of what this form
 * sends, which is asserted from the outside in the control plane's
 * `CustomerWritesResistOverpostingTest`. This file is the other end: that the
 * portal does not invite a change it cannot deliver.
 */

/** Exactly the four keys `ProfileController::update` validates. */
const EDITABLE = ['Full name', 'Language', 'Time zone', 'Phone'] as const

const USER = {
  id: '01JUSER',
  name: 'Amal Rashid',
  email: 'amal@example.test',
  email_verified: true,
  locale: 'en',
  timezone: 'Asia/Kuwait',
  phone: '+965 1234 5678',
  two_factor_enabled: false,
  last_login_at: '2026-03-01T08:00:00+00:00',
  created_at: '2025-01-01T00:00:00+00:00',
  permissions: ['customer.manage'],
  customers: [
    {
      id: '01JCUST',
      type: 'organization',
      status: 'active',
      display_name: 'Premier Care',
      legal_name: 'Premier Care Trading Co.',
      currency: 'KWD',
      country: 'KW',
      can_purchase: true,
      role: 'owner',
    },
  ],
}

function stubFetch(onPatch?: (body: unknown) => void) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = (url.split('?')[0] ?? url).replace(/\/$/, '')

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/me') && (init?.method ?? 'GET') === 'GET') {
      body = { data: USER }
    } else if (path.endsWith('/me') && init?.method === 'PATCH') {
      onPatch?.(JSON.parse(typeof init.body === 'string' ? init.body : '{}'))
      body = { data: USER }
    } else if (path.endsWith('/me/notification-preferences')) {
      body = { data: [] }
    } else {
      throw new Error(`Unstubbed request: ${init?.method ?? 'GET'} ${url}`)
    }

    return Promise.resolve({
      ok: true,
      status: 200,
      statusText: '',
      headers: new Headers(),
      text: () => Promise.resolve(JSON.stringify(body)),
    } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <ProfilePage />
    </QueryClientProvider>,
  )
}

/** The form's own controls, by their accessible names. */
function controls(): { name: string; writable: boolean }[] {
  const form = document.querySelector('form')

  if (form === null) throw new Error('The profile form did not render.')

  return [...form.querySelectorAll('input, select, textarea')].map((element) => {
    const control = element as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
    const labelled = control.labels?.[0]?.textContent ?? control.getAttribute('aria-label') ?? ''

    return {
      name: labelled.trim(),
      writable: ! control.disabled && ! (control as HTMLInputElement).readOnly,
    }
  })
}

describe('the fields the profile form offers', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('is writable for exactly the four the server accepts', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await screen.findByLabelText('Full name')

    const writable = controls().filter((control) => control.writable).map((control) => control.name)

    // Set-compared rather than ordered: the order is a layout decision, the
    // membership is a contract with the server.
    expect([...writable].sort()).toEqual([...EDITABLE].sort())
  })

  it('shows the address, and says where a change to it has to come from', async () => {
    /*
     * READ_ONLY rather than absent. An account whose email address simply was
     * not on the page reads as one the platform has no address for — and the
     * address is what every notification and every invoice goes to. Shown,
     * uneditable, with the reason.
     */
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const email = await screen.findByLabelText('Email address')

    expect(email).toHaveValue('amal@example.test')
    expect(email).toBeDisabled()
    expect(screen.getByText(/contact support to change the address/i)).toBeInTheDocument()
  })

  it('sends those four keys and no others', async () => {
    const sent = vi.fn()
    vi.stubGlobal('fetch', stubFetch(sent))
    const user = userEvent.setup()

    renderPage()

    await user.clear(await screen.findByLabelText('Full name'))
    await user.type(screen.getByLabelText('Full name'), 'Amal R')
    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(sent).toHaveBeenCalled()
    })

    /*
     * The whole payload, not a subset match: a `currency` that rode along
     * would be refused by the server and still means this screen believes it
     * owns a field it does not.
     */
    expect(Object.keys(sent.mock.calls[0]?.[0] as object).sort()).toEqual([
      'locale',
      'name',
      'phone',
      'timezone',
    ])
  })

  it('offers no control at all for the account’s own identity', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await screen.findByLabelText('Full name')

    const form = within(document.querySelector('form') as HTMLElement)

    /*
     * Named individually rather than by counting, because each is a different
     * kind of mistake: the first two are SUPPORT_ONLY and would be a customer
     * editing the name on their own invoices; the last two are
     * WORKFLOW_CONTROLLED and would be an account repricing its catalogue.
     */
    for (const label of [/display name/i, /legal name/i, /^country/i, /^currency/i, /account type/i]) {
      expect(form.queryByLabelText(label)).not.toBeInTheDocument()
    }
  })
})
