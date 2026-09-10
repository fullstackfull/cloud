import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { SiteCopies } from '@/features/wordpress/SiteCopies'
import type { WordPressSite } from '@/lib/types'
import '@/i18n'

/**
 * Copies and the push back, at the component.
 *
 * Two things worth an assertion: that the buttons follow what the site's
 * panel says it can do (a site whose toolkit cannot copy shows the reason
 * and no button), and that the push shows what it overwrites — in the
 * server's words, including that the platform holds no backup — and
 * cannot be confirmed until the production domain is typed exactly.
 *
 * Rendered as the component rather than through the sites list: Wave 3 moved
 * these controls onto the site's own page, and the guarantees asserted here
 * are the component's, not any one screen's.
 */

const PRODUCTION = {
  id: '01JPROD',
  domain: 'shop.test',
  domain_source: 'external',
  domain_id: null,
  state: 'ready',
  is_usable: true,
  is_verified: true,
  needs_attention: false,
  dns_ready: true,
  installed: true,
  ssl_status: 'active',
  site_url: 'https://shop.test',
  admin_url: 'https://shop.test/wp-admin/',
  admin_username: 'sitemanager',
  wordpress_version: '6.7.1',
  locale: 'en_US',
  failure_reason: null,
  hosting_account_id: '01JACCT',
  verified_at: '2026-03-01T00:00:00+00:00',
  created_at: '2026-03-01T00:00:00+00:00',
  kind: 'production',
  parent_site_id: null,
  copies: { staging: true, clone: true, push_to_production: false, reason: null },
}

const STAGING = {
  ...PRODUCTION,
  id: '01JSTAGE',
  domain: 'staging.shop.test',
  kind: 'staging',
  parent_site_id: '01JPROD',
  copies: { staging: false, clone: false, push_to_production: true, reason: null },
}

const CANNOT = {
  ...PRODUCTION,
  id: '01JELSE',
  domain: 'elsewhere.test',
  copies: { staging: false, clone: false, push_to_production: false, reason: 'This site is on a panel whose toolkit cannot copy WordPress sites.' },
}

const IMPACT = {
  staging_domain: 'staging.shop.test',
  production_domain: 'shop.test',
  scope: 'both',
  copy_made_at: '2026-03-02T00:00:00+00:00',
  production_verified_at: '2026-03-01T00:00:00+00:00',
  platform_backup: null,
  warnings: [
    'shop.test will be overwritten with the staging copy. This cannot be undone from this platform.',
    'This platform holds no backup of a shared-hosting site. If you need one, take it in the panel before pushing.',
  ],
}

function stubFetch({ sites, onPush, onStaging }: { sites: unknown[]; onPush?: (body: unknown) => void; onStaging?: () => void }) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (path.endsWith('/wordpress/sites')) {
      body = { data: sites, meta: { total: sites.length } }
    } else if (path.endsWith('/operations')) {
      body = { data: [] }
    } else if (path.endsWith('/push/impact')) {
      body = { data: IMPACT }
    } else if (path.endsWith('/push')) {
      onPush?.(JSON.parse(typeof init?.body === 'string' ? init.body : '{}'))
      body = { data: { id: '01JOP', site_id: '01JSTAGE', target_site_id: '01JPROD', kind: 'push_to_production', state: 'succeeded', is_in_flight: false, needs_attention: false, scope: 'both', impact: IMPACT, failure_reason: null, started_at: null, finished_at: null, created_at: '2026-03-02T00:00:00+00:00' } }
    } else if (path.endsWith('/staging')) {
      onStaging?.()
      body = { data: { id: '01JOP2', site_id: '01JPROD', target_site_id: '01JSTAGE', kind: 'create_staging', state: 'succeeded', is_in_flight: false, needs_attention: false, scope: null, impact: null, failure_reason: null, started_at: null, finished_at: null, created_at: '2026-03-02T00:00:00+00:00' } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({ ok: true, status: 200, statusText: '', headers: new Headers(), text: () => Promise.resolve(JSON.stringify(body)) } as Response)
  })
}

function renderCopies(site: unknown) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={client}>
      <SiteCopies site={site as WordPressSite} />
    </QueryClientProvider>,
  )
}

describe('WordPress copies', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('offers what the panel can do, and the reason where it can do nothing', async () => {
    const asked = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ sites: [PRODUCTION, CANNOT], onStaging: asked }))
    const user = userEvent.setup()

    const production = renderCopies(PRODUCTION)
    const staging = await screen.findByRole('button', { name: /create a staging copy/i })

    await user.click(staging)
    await waitFor(() => {
      expect(asked).toHaveBeenCalled()
    })

    production.unmount()

    // The same component, for a site whose panel toolkit cannot copy: no
    // button at all, and the provider's own reason in its place.
    renderCopies(CANNOT)

    expect(
      screen.queryByRole('button', { name: /create a staging copy/i }),
    ).not.toBeInTheDocument()
    expect(await screen.findByText(/cannot copy wordpress sites/i)).toBeInTheDocument()
  })

  it('shows what a push overwrites in the server\'s words and needs the production domain typed exactly', async () => {
    const pushed = vi.fn()
    vi.stubGlobal('fetch', stubFetch({ sites: [PRODUCTION, STAGING], onPush: pushed }))
    const user = userEvent.setup()

    const copy = renderCopies(STAGING)

    await user.click(await screen.findByRole('button', { name: /push to production/i }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/holds no backup of a shared-hosting site/i)).toBeInTheDocument()

    const confirm = within(dialog).getByRole('button', { name: /^push to production$/i })
    expect(confirm).toBeDisabled()
    await user.type(within(dialog).getByRole('textbox'), 'SHOP.TEST')
    expect(confirm).toBeDisabled()
    await user.clear(within(dialog).getByRole('textbox'))
    await user.type(within(dialog).getByRole('textbox'), 'shop.test')
    await user.click(confirm)

    await waitFor(() => {
      expect(pushed).toHaveBeenCalledWith({ scope: 'both', confirmation: 'shop.test' })
    })

    // A production site has nothing to push, and never shows the button.
    copy.unmount()
    renderCopies(PRODUCTION)

    await waitFor(() => {
      expect(screen.queryByRole('button', { name: /push to production/i })).not.toBeInTheDocument()
    })
  })
})
