import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { RegisterPage } from '@/features/auth/RegisterPage'
import i18n from '@/i18n'

/**
 * F-21. Registration when the page could not find out whether it may register.
 *
 * ## The defect
 *
 * Everything the form offers comes from one request, `/registration/options`:
 * the countries, the currencies, the documents the checkbox names, and the
 * server's own answer to whether it will accept a registration at all. When
 * that request failed, the page said nothing. The country select held its
 * placeholder, the currency select was empty and disabled, the terms sentence
 * had no links, there was no alert anywhere — and Create account was live,
 * because the gate read `registration_permitted === false`, which is false for
 * `undefined`. Pressing it answered "The country field is required" under a
 * select that offered no country.
 *
 * An empty select is exactly what a first render looks like, so nothing on
 * that screen told a failed page from a slow one.
 *
 * ## What these specs hold
 *
 * The unknown answer points in opposite directions for the two things that
 * read it, which is why one boolean for both was the bug:
 *
 *  - the "registration is not open yet" warning still needs the server to have
 *    said `false`, because claiming the platform is closed is a statement the
 *    screen has no evidence for when it could not ask;
 *  - the Create account button needs the server to have said `true`, because
 *    not knowing is not permission.
 *
 * And a page that cannot submit says why, in the one state the first fix left
 * silent: a 200 whose `legal` omits `registration_permitted` rendered
 * countries, currencies and both document links with the button greyed and no
 * sentence anywhere — which differs from a healthy page only in the grey.
 *
 * Every request the page makes is recorded, so "sends nothing" is measured
 * rather than inferred from a disabled attribute.
 */

type Answer =
  | { kind: 'pending' }
  | { kind: 'failed'; status: number }
  | { kind: 'answered'; body: unknown }

interface Recorded {
  method: string
  path: string
}

const COUNTRIES = [
  { code: 'KW', currency: 'KWD', currency_is_explicit: true },
  { code: 'SA', currency: 'SAR', currency_is_explicit: true },
]

const CURRENCIES = ['KWD', 'SAR', 'USD']

const DOCUMENTS = [
  { type: 'terms', url: 'https://legal.example/terms', version: '2026-04-01' },
  { type: 'aup', url: 'https://legal.example/aup', version: '1.2' },
]

function options(legal: unknown): Answer {
  return {
    kind: 'answered',
    body: {
      data: {
        countries: COUNTRIES,
        currencies: CURRENCIES,
        fallback_currency: 'USD',
        ...(legal === undefined ? {} : { legal }),
      },
      meta: { countries_count: 2, currencies_count: 3 },
    },
  }
}

const OPEN = options({ registration_permitted: true, documents: DOCUMENTS })

/**
 * Answers `/registration/options` with each of `answers` in turn, repeating
 * the last, and records every request the page makes.
 */
function stubFetch(answers: Answer[]): Recorded[] {
  const recorded: Recorded[] = []
  let asked = 0

  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
      const url = input instanceof Request ? input.url : String(input)
      const path = url.split('?')[0] ?? url
      const method = init?.method ?? 'GET'

      recorded.push({ method, path })

      if (path.endsWith('/registration/options')) {
        const answer = answers[Math.min(asked, answers.length - 1)] ?? { kind: 'pending' }
        asked += 1

        if (answer.kind === 'pending') {
          return new Promise<Response>(() => undefined)
        }

        if (answer.kind === 'failed') {
          return Promise.resolve({
            ok: false,
            status: answer.status,
            statusText: 'Service Unavailable',
            text: () => Promise.resolve(''),
          } as Response)
        }

        return Promise.resolve({
          ok: true,
          status: 200,
          statusText: '',
          text: () => Promise.resolve(JSON.stringify(answer.body)),
        } as Response)
      }

      // The CSRF cookie and the registration itself. Answered as a success, so
      // a registration that should not have been sent is visible as sent.
      return Promise.resolve({
        ok: true,
        status: 200,
        statusText: '',
        text: () =>
          Promise.resolve(
            JSON.stringify({ data: { message: 'ok' }, meta: { email_verification_required: true } }),
          ),
      } as Response)
    }),
  )

  return recorded
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

function registrations(recorded: Recorded[]): Recorded[] {
  return recorded.filter((request) => request.method === 'POST' && request.path.endsWith('/register'))
}

function optionReads(recorded: Recorded[]): Recorded[] {
  return recorded.filter((request) => request.path.endsWith('/registration/options'))
}

function createAccount(): HTMLElement {
  return screen.getByRole('button', { name: /create account/i })
}

/** Everything a visitor can fill in without the server's lists. */
async function fillWhatCanBeFilled(): Promise<void> {
  const user = userEvent.setup()

  await user.type(screen.getByLabelText(/full name/i), 'Amal Al-Sabah')
  await user.type(screen.getByLabelText(/email address/i), 'amal@example.com')
  await user.type(screen.getByLabelText(/^password$/i), 'correct-horse-9')
  await user.type(screen.getByLabelText(/confirm password/i), 'correct-horse-9')
  await user.click(screen.getByRole('checkbox'))
}

describe('registration that could not ask whether it may register', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('says the form could not be loaded, instead of drawing what looks like a first render', async () => {
    stubFetch([{ kind: 'failed', status: 503 }])

    renderPage()

    const alert = await screen.findByRole('alert')

    expect(alert).toHaveTextContent(/could not load/i)
    // No evidence the platform is closed, so the screen does not claim it.
    expect(screen.queryByText(/registration is not open yet/i)).toBeNull()
  })

  it('does not offer Create account while it does not know registration is open', async () => {
    stubFetch([{ kind: 'failed', status: 503 }])

    renderPage()

    await screen.findByRole('alert')

    expect(createAccount()).toBeDisabled()
  })

  it('does not offer Create account while the answer is still on its way, and does not call that a failure', async () => {
    const recorded = stubFetch([{ kind: 'pending' }])

    renderPage()

    await waitFor(() => {
      expect(optionReads(recorded)).toHaveLength(1)
    })

    expect(createAccount()).toBeDisabled()
    // A slow answer is not a failed one: nothing flashes at a visitor who can
    // register perfectly well once it arrives.
    expect(screen.queryAllByRole('alert')).toEqual([])
    expect(screen.queryByText(/registration is not open yet/i)).toBeNull()
  })

  it('offers a way to ask again that asks again and sends nothing else', async () => {
    const recorded = stubFetch([{ kind: 'failed', status: 503 }, OPEN])
    const user = userEvent.setup()

    renderPage()

    await waitFor(() => {
      expect(optionReads(recorded)).toHaveLength(1)
    })
    await fillWhatCanBeFilled()

    /*
     * A <button> with no type inside a <form> is a submit button. An escape
     * hatch built that way would refetch the options *and* post the
     * half-filled registration — the exact dead end it exists to escape.
     */
    await user.click(await screen.findByRole('button', { name: /try again/i }))

    expect(await screen.findByRole('option', { name: 'Kuwait' })).toBeInTheDocument()
    expect(optionReads(recorded)).toHaveLength(2)
    expect(registrations(recorded)).toEqual([])

    await waitFor(() => {
      expect(screen.queryAllByRole('alert')).toEqual([])
    })
    expect(createAccount()).toBeEnabled()
  })

  it('does not submit on Enter while registration is not known to be open', async () => {
    const recorded = stubFetch([{ kind: 'failed', status: 503 }])
    const user = userEvent.setup()

    renderPage()

    await waitFor(() => {
      expect(optionReads(recorded)).toHaveLength(1)
    })
    await fillWhatCanBeFilled()

    /*
     * Implicit submission: Enter in a text field presses the form's default
     * button, which is its first submit button in tree order. That is why the
     * escape hatch above the fields must not be one, and why the default
     * button must be disabled while the answer is unknown.
     */
    await user.type(screen.getByLabelText(/full name/i), '{Enter}')

    expect(registrations(recorded)).toEqual([])
  })

  it('says so when the answer does not say whether registration is open', async () => {
    // State H+: the countries, the currencies and both documents arrive, and
    // `registration_permitted` does not. A portal and an API deploy
    // separately, so the field can be missing without anybody's code being
    // wrong on its own side.
    stubFetch([options({ documents: DOCUMENTS })])

    renderPage()

    // The rest of the answer is still used.
    expect(await screen.findByRole('option', { name: 'Kuwait' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /terms of service/i })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /acceptable use policy/i })).toBeInTheDocument()

    // And the grey button is not the only thing that differs from a healthy page.
    expect(screen.getByRole('alert')).toHaveTextContent(/could not confirm that registration is open/i)
    expect(createAccount()).toBeDisabled()
    expect(screen.queryByText(/registration is not open yet/i)).toBeNull()
  })

  it('keeps drawing the page when the answer has no legal section at all', async () => {
    // Reading `legal.registration_permitted` off an absent `legal` threw in
    // render, and a throw in render blanks the whole registration route.
    stubFetch([options(undefined)])

    renderPage()

    expect(await screen.findByRole('option', { name: 'Kuwait' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /create an account/i })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent(/could not confirm that registration is open/i)
    expect(createAccount()).toBeDisabled()
  })

  it('treats anything but a literal true as not permitted', async () => {
    // A string is not a yes. The server's contract is a boolean; a screen that
    // accepted "true" would be trusting a shape nobody promised.
    stubFetch([options({ registration_permitted: 'true', documents: DOCUMENTS })])

    renderPage()

    expect(await screen.findByRole('option', { name: 'Kuwait' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent(/could not confirm that registration is open/i)
    expect(createAccount()).toBeDisabled()
  })

  it('says it to an Arabic reader in Arabic', async () => {
    stubFetch([{ kind: 'failed', status: 503 }])
    await i18n.changeLanguage('ar')

    try {
      renderPage()

      const alert = await screen.findByRole('alert')

      expect(alert).toHaveTextContent(i18n.t('auth.registrationOptionsFailed'))
      expect(i18n.t('auth.registrationOptionsFailed')).toMatch(/[؀-ۿ]/)
      expect(screen.getByRole('button', { name: i18n.t('common.retry') })).toHaveAttribute('type', 'button')
      expect(screen.getByRole('button', { name: i18n.t('common.register') })).toBeDisabled()
    } finally {
      await i18n.changeLanguage('en')
    }
  })
})
