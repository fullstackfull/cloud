import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { TwoFactorSection } from '@/features/security/TwoFactorSection'
import '@/i18n'

/**
 * Turning two-factor authentication on.
 *
 * The server requires the current password to begin enrolment. Until Wave 0
 * the button sent nothing at all and was answered 422 — "please correct the
 * highlighted fields", with no field on the screen — so the control the
 * security card advertised could not be used. These assert the password is
 * asked for, sent, and that a wrong one is reported against the field.
 */

const ME = {
  id: '01JUSER',
  name: 'Sample Customer',
  email: 'customer@lynomia.test',
  email_verified: true,
  locale: 'en',
  timezone: 'Asia/Kuwait',
  phone: null,
  two_factor_enabled: false,
  last_login_at: null,
  created_at: null,
  permissions: [],
  customers: [],
}

function stubFetch(options: { begin: (body: unknown) => { status: number; body: unknown } }) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let status = 200
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      status = 204
    } else if (path.endsWith('/me')) {
      body = { data: ME }
    } else if (path.endsWith('/me/two-factor')) {
      const answer = options.begin(JSON.parse(typeof init?.body === 'string' ? init.body : 'null'))
      status = answer.status
      body = answer.body
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({
      ok: status >= 200 && status < 300,
      status,
      statusText: '',
      text: () => Promise.resolve(body === null ? '' : JSON.stringify(body)),
    } as Response)
  })
}

function renderSection() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <TwoFactorSection />
    </QueryClientProvider>,
  )
}

describe('enabling two-factor authentication', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('asks for the current password before anything is sent, then begins enrolment with it', async () => {
    const begin = vi.fn(() => ({
      status: 200,
      body: { data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_url: 'otpauth://totp/x' } },
    }))
    vi.stubGlobal('fetch', stubFetch({ begin }))
    const user = userEvent.setup()

    renderSection()

    await user.click(await screen.findByRole('button', { name: /turn on two-factor/i }))

    // Nothing has been sent yet: the password step is in the way.
    expect(begin).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText(/current password/i), 'correct horse battery staple')
    await user.click(screen.getByRole('button', { name: /^continue$/i }))

    await waitFor(() => {
      expect(begin).toHaveBeenCalledWith({ current_password: 'correct horse battery staple' })
    })

    // The secret is shown for a manual entry, and the code step follows.
    expect(await screen.findByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument()
    expect(screen.getByLabelText(/code/i)).toBeInTheDocument()
  })

  it('reports a wrong password against the field rather than as a highlighted-fields riddle', async () => {
    const begin = vi.fn(() => ({
      status: 422,
      body: {
        error: {
          code: 'validation.failed',
          message: 'The submitted data is invalid.',
          details: { fields: { current_password: ['That password is incorrect.'] } },
        },
      },
    }))
    vi.stubGlobal('fetch', stubFetch({ begin }))
    const user = userEvent.setup()

    renderSection()

    await user.click(await screen.findByRole('button', { name: /turn on two-factor/i }))
    await user.type(screen.getByLabelText(/current password/i), 'not my password')
    await user.click(screen.getByRole('button', { name: /^continue$/i }))

    expect(await screen.findByText(/that password is incorrect/i)).toBeInTheDocument()
    // Still on the password step, not on the code step.
    expect(screen.queryByLabelText(/^code$/i)).not.toBeInTheDocument()
  })
})
