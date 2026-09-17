import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { RegisterPage } from '@/features/auth/RegisterPage'
import '@/i18n'

/**
 * Registration asks where the account is billed from and says what that means
 * for the currency, before anything is submitted.
 *
 * The defect: it asked neither question and booked every account in the
 * platform's own currency. A customer in Riyadh found out they were being
 * billed in Kuwaiti dinars when their first invoice arrived, and changing it
 * afterwards is a support conversation.
 *
 * The lists come from the server. Nothing in these tests — and nothing in the
 * portal — knows which currency belongs to which country.
 */

const OPTIONS = {
  data: {
    countries: [
      { code: 'KW', currency: 'KWD', currency_is_explicit: true },
      { code: 'SA', currency: 'SAR', currency_is_explicit: true },
      { code: 'JP', currency: 'USD', currency_is_explicit: false },
    ],
    currencies: ['KWD', 'USD', 'EUR', 'GBP', 'SAR', 'AED'],
    fallback_currency: 'USD',
    // Unpublished, which is the state the platform is in until somebody
    // writes the documents. The two tests at the bottom cover both answers.
    legal: { terms_url: null, aup_url: null },
  },
  meta: { countries_count: 3, currencies_count: 6 },
}

function stubFetch(
  onRegister?: (body: unknown) => void,
  legal: { terms_url: string | null; aup_url: string | null } = OPTIONS.data.legal,
) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/registration/options')) {
      body = { ...OPTIONS, data: { ...OPTIONS.data, legal } }
    } else if (path.endsWith('/register')) {
      onRegister?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { message: 'ok' }, meta: { email_verification_required: true } }
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

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <RegisterPage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

/**
 * Waits for the server's country list to arrive before touching the select.
 *
 * The list is a request, not a constant, which is the whole point: a portal
 * that shipped its own list would drift from the platform's the first time a
 * currency was enabled or withdrawn.
 */
async function chooseCountry(code: string, name: string): Promise<void> {
  const user = userEvent.setup()

  await screen.findByRole('option', { name })
  await user.selectOptions(await screen.findByLabelText(/billing country/i), code)
}

async function fillTheRest(): Promise<void> {
  const user = userEvent.setup()

  await user.type(screen.getByLabelText(/full name/i), 'Amal Al-Sabah')
  await user.type(screen.getByLabelText(/email address/i), 'amal@example.com')
  await user.type(screen.getByLabelText(/^password$/i), 'correct-horse-9')
  await user.type(screen.getByLabelText(/confirm password/i), 'correct-horse-9')
  await user.click(screen.getByRole('checkbox'))
}

describe('registration decides the currency out loud', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers the countries the server offers, named in the reader’s language', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    const country = await screen.findByLabelText(/billing country/i)

    // Named from the browser's own CLDR data, not from a table in the bundle.
    expect(await screen.findByRole('option', { name: 'Kuwait' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Saudi Arabia' })).toBeInTheDocument()
    expect(country).toBeInTheDocument()
  })

  it('shows the currency the country implies, before anything is submitted', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await chooseCountry('SA', 'Saudi Arabia')

    expect(screen.getByLabelText(/billing currency/i)).toHaveValue('SAR')
    expect(screen.getByText(/we bill customers in that country in SAR/i)).toBeInTheDocument()
  })

  it('says so when the country has no currency of its own here', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await chooseCountry('JP', 'Japan')

    expect(screen.getByLabelText(/billing currency/i)).toHaveValue('USD')
    expect(screen.getByText(/do not price in that country/i)).toBeInTheDocument()
  })

  it('submits the country and the currency the customer was shown', async () => {
    const submitted = vi.fn()
    vi.stubGlobal('fetch', stubFetch(submitted))
    const user = userEvent.setup()

    renderPage()

    await chooseCountry('KW', 'Kuwait')
    await fillTheRest()
    await user.click(screen.getByRole('button', { name: /create account/i }))

    expect(submitted).toHaveBeenCalledWith(
      expect.objectContaining({ country: 'KW', currency: 'KWD' }),
    )
  })

  it('lets the customer override the recommendation with a currency the platform bills in', async () => {
    const submitted = vi.fn()
    vi.stubGlobal('fetch', stubFetch(submitted))
    const user = userEvent.setup()

    renderPage()

    await chooseCountry('KW', 'Kuwait')
    await user.selectOptions(screen.getByLabelText(/billing currency/i), 'GBP')
    await fillTheRest()
    await user.click(screen.getByRole('button', { name: /create account/i }))

    expect(submitted).toHaveBeenCalledWith(
      expect.objectContaining({ country: 'KW', currency: 'GBP' }),
    )
  })

  it('offers only the currencies the server bills in', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    await screen.findByRole('option', { name: 'Kuwait' })

    const currency = await screen.findByLabelText(/billing currency/i)
    const offered = [...currency.querySelectorAll('option')].map((option) => option.value)

    expect(offered).toEqual(OPTIONS.data.currencies)
  })

  /*
   * The documents the checkbox names.
   *
   * The sentence beside the box says a customer accepts a terms of service and
   * an acceptable use policy, and for a long time offered no way to read
   * either. The documents are a business and legal deliverable rather than
   * something this repository writes, so the screen's job is to link them
   * when they exist and to say nothing when they do not — and publishing them
   * has to be configuration rather than a code change, which is why the URLs
   * travel with the rest of the registration options.
   */
  it('links the documents the customer is accepting, once they are published', async () => {
    vi.stubGlobal(
      'fetch',
      stubFetch(undefined, {
        terms_url: 'https://legal.example/terms',
        aup_url: 'https://legal.example/aup',
      }),
    )

    renderPage()

    const terms = await screen.findByRole('link', { name: /terms of service/i })
    const aup = await screen.findByRole('link', { name: /acceptable use policy/i })

    expect(terms).toHaveAttribute('href', 'https://legal.example/terms')
    expect(aup).toHaveAttribute('href', 'https://legal.example/aup')

    // A document on somebody else's origin, opened without a handle on this
    // tab — a half-filled registration form must not be navigable by it.
    expect(terms).toHaveAttribute('rel', expect.stringContaining('noopener'))
  })

  it('offers no link at all while the documents are unpublished', async () => {
    vi.stubGlobal('fetch', stubFetch())

    renderPage()

    // The checkbox is there and still required: acceptance is recorded either
    // way, and that half is the platform's. What must not appear is a link
    // that goes nowhere or an empty line pretending to be one.
    expect(await screen.findByRole('checkbox')).toBeRequired()
    expect(screen.queryByRole('link', { name: /terms of service/i })).toBeNull()
    expect(screen.queryByRole('link', { name: /acceptable use policy/i })).toBeNull()
  })

  it('links the one document that is published without inventing the other', async () => {
    /*
     * Two documents, published separately, and a screen that waits for both
     * would show neither. This is also the shape the server produces when it
     * drops a URL it will not hand a browser — a script scheme in the
     * configuration comes back as null, and null is a state this page already
     * renders. `TheRegistrationOptionsNameTheLegalDocumentsTest` is where that
     * refusal itself is asserted.
     */
    vi.stubGlobal(
      'fetch',
      stubFetch(undefined, { terms_url: null, aup_url: 'https://legal.example/aup' }),
    )

    renderPage()

    expect(await screen.findByRole('link', { name: /acceptable use policy/i })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /terms of service/i })).toBeNull()
  })
})
