import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { OperatorCataloguePage } from '@/features/controlCenter/OperatorCataloguePage'
import '@/i18n'

/*
 * The screen behind E-11. Its job is to let a person configure what this
 * deployment sells, and to make the two states a list cannot otherwise show
 * visible: a plan nobody can buy because it has no price, and a hosting
 * package that is mapped to no plan.
 */

const PRODUCT = {
  id: 'p1', kind: 'shared_hosting', slug: 'web-hosting',
  name: { en: 'Web Hosting', ar: 'استضافة' }, description: null,
  is_active: true, is_public: true, sort_order: 0, plans_count: 1,
}

const PRICED = {
  id: 'pl1', product_id: 'p1', slug: 'starter',
  name: { en: 'Starter', ar: 'المبتدئ' }, description: null,
  resources: { disk_quota_mib: 10240 }, placement_constraints: null,
  stock_limit: null, per_customer_limit: null,
  is_active: true, is_public: true, sort_order: 0,
  prices: [{ id: 'pr1', currency: 'KWD', billing_period: 'monthly', recurring_amount_minor: 9000, setup_amount_minor: 0, is_active: true, available_from: null, available_until: null }],
  priced_in: ['KWD'],
}

const UNPRICED = { ...PRICED, id: 'pl2', slug: 'unpriced', name: { en: 'Unpriced', ar: 'بلا سعر' }, prices: [], priced_in: [] }

const PACKAGE = {
  id: 'hp1', slug: 'hosting-starter', panel_package_name: 'lyn_starter',
  plan_id: 'pl1', is_active: true, mapped: true, limits: {},
}

let posted: Array<{ path: string; body: unknown }> = []

function stubFetch(plans: unknown[] = [PRICED], packages: unknown[] = [PACKAGE]) {
  return vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url
    const method = init?.method ?? 'GET'
    let body: unknown = null

    if (path.endsWith('/sanctum/csrf-cookie')) {
      body = null
    } else if (method === 'POST') {
      // `init.body` is typed as BodyInit; the client always sends a string,
      // and asserting that is honest where `String()` would quietly produce
      // "[object Object]" if it ever stopped being one.
      const sent = typeof init?.body === 'string' ? init.body : '{}'
      posted.push({ path, body: JSON.parse(sent) })
      body = { data: PRODUCT }
    } else if (path.endsWith('/catalogue/products')) {
      body = { data: [PRODUCT], meta: { total: 1, on_sale: 1 } }
    } else if (path.endsWith('/catalogue/plans')) {
      body = { data: plans, meta: { total: plans.length, unpriced: plans.filter((p) => (p as typeof PRICED).priced_in.length === 0).length } }
    } else if (path.endsWith('/catalogue/hosting-packages')) {
      body = { data: packages, meta: { total: packages.length, orderable: packages.length } }
    } else {
      throw new Error(`Unstubbed request: ${url}`)
    }

    return Promise.resolve({ ok: true, status: 200, statusText: '', headers: new Headers(), text: () => Promise.resolve(JSON.stringify(body)) } as Response)
  })
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        <OperatorCataloguePage />
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
  posted = []
})

describe('the catalogue screen', () => {
  it('shows what is configured, in the reader’s language', async () => {
    vi.stubGlobal('fetch', stubFetch())
    renderPage()

    const products = await screen.findByLabelText('Products')
    expect(within(products).getByText('Web Hosting')).toBeInTheDocument()

    const plans = await screen.findByLabelText('Plans')
    expect(within(plans).getByText('Starter')).toBeInTheDocument()
  })

  it('says out loud when a listed plan has no price, because nobody can buy it', async () => {
    vi.stubGlobal('fetch', stubFetch([UNPRICED]))
    renderPage()

    expect(await screen.findByText('No active price. Nobody can buy this.')).toBeInTheDocument()
    expect(await screen.findByText('1 listed plans have no active price.')).toBeInTheDocument()
  })

  it('sends money as whole minor units and never as a decimal', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()
    renderPage()

    const [openPricing] = await screen.findAllByRole('button', { name: 'Set a price' })
    expect(openPricing).toBeDefined()
    await user.click(openPricing as HTMLElement)

    const form = await screen.findByLabelText('Set a price for starter')
    await user.type(within(form).getByLabelText(/Amount \(minor units\)/), '9000')
    await user.click(within(form).getByRole('button', { name: 'Set a price' }))

    await waitFor(() => { expect(posted).toHaveLength(1) })

    const [first] = posted
    const sent = (first as { body: { recurring_amount_minor: number; currency: string } }).body
    expect(sent.recurring_amount_minor).toBe(9000)
    expect(Number.isInteger(sent.recurring_amount_minor)).toBe(true)
    expect(sent.currency).toBe('KWD')
  })

  it('asks for the fields the chosen kind actually needs', async () => {
    vi.stubGlobal('fetch', stubFetch())
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Record a plan' }))
    const form = await screen.findByLabelText('Record a plan')

    // Nothing kind-specific until a product is chosen.
    expect(within(form).queryByLabelText('vCPU')).not.toBeInTheDocument()

    await user.selectOptions(within(form).getByLabelText('Product'), 'p1')

    // Shared hosting asks for quota, not for a compute triple: a hosting plan
    // is delivered by a panel package, not by a hypervisor.
    expect(within(form).getByLabelText('Disk quota (MiB)')).toBeInTheDocument()
    expect(within(form).queryByLabelText('vCPU')).not.toBeInTheDocument()
  })

  it('marks a package that is mapped to no plan', async () => {
    vi.stubGlobal('fetch', stubFetch([PRICED], [{ ...PACKAGE, plan_id: null, mapped: false }]))
    renderPage()

    const packages = await screen.findByLabelText('Hosting packages')
    expect(within(packages).getByText('No plan')).toBeInTheDocument()
  })
})
