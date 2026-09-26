import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'

import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { cleanup, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { compile } from 'tailwindcss'
import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  DedicatedDetailPage,
  DedicatedOverviewSection,
} from '@/features/infrastructure/DedicatedDetailPage'
import { VpsDetailPage, VpsOverviewSection } from '@/features/infrastructure/VpsDetailPage'
import { VpsPage } from '@/features/infrastructure/VpsPage'
import i18n from '@/i18n'
import ar from '@/i18n/locales/ar.json'
import en from '@/i18n/locales/en.json'

/**
 * F-20: whether the disk is already gone.
 *
 * The API publishes `reinstall.data_destroyed` for a VPS exactly as it does for
 * a dedicated server — true from the moment the machine was told to replace
 * its disk, whatever happened after. The Dedicated page has said so since
 * Wave 4. No VPS screen did, and neither catalogue had a VPS sentence to say
 * it with, so a customer whose rebuild had erased the disk and was then
 * settled as `failed` by an operator read one line about it: "The rebuild did
 * not run".
 *
 * The machine's page now reads the published fact and says it, in red, next to
 * the state. These tests render the real pages against a stubbed API, in both
 * languages, rather than grepping the source for the key.
 *
 * ## What the fixtures cover, and what they cannot
 *
 * The guard is meant to follow `data_destroyed` and nothing else. The only
 * thing that makes that more than a sentence is a set of fixtures on which a
 * guard that also consulted the state name would give a different answer. So
 * every `(state, data_destroyed)` pair the API can publish today is a row
 * below, in both directions:
 *
 * - destroyed at `reinstalling`, `configuring`, `verifying`, `completed`,
 *   `failed`, `needs_review` and `indeterminate` — the six states
 *   `ReinstallState::impliesDestroyedData()` is true for, plus `failed`, which
 *   a rebuild reaches destroyed when an operator settles a reviewed one that
 *   way;
 * - intact at `requested`, `queued`, `preparing` and `failed` — every state a
 *   rebuild can be in before `VmReinstall::advanceTo()` stamps `destroyed_at`.
 *
 * A guard that disagrees with the fact on any of those eleven pairs fails a
 * row here. A state the API cannot publish today is not covered, and nothing
 * in this file can say what a condition does on one.
 *
 * ## Presented, not merely present
 *
 * A sentence can be in the DOM and not on the screen. The one row that asks
 * compiles the classes actually rendered through Tailwind — the thing that
 * gives a class string its meaning — puts the result into the document, and
 * asks whether the sentence and every ancestor are displayed, not hidden, not
 * transparent, and not clipped to a visually-hidden pixel. It does not know
 * about colour contrast, a zero font size, a transform that moves the text off
 * screen, or an ancestor's overflow clipping it, and says nothing about them.
 *
 * ## What no test here can tell
 *
 * Whether the Arabic says the right thing. The rows below check that the
 * Arabic sentence exists, is rendered, is written in Arabic script and is not
 * the English one; a fluent sentence saying the opposite would pass all of
 * them. The Arabic was checked by reading it against the Dedicated sentence it
 * is the singular of: the disks become the disk, and "on them" becomes "on it".
 */

/** The VPS sentence, singular: a VPS has one disk. Dedicated's is plural. */
const ENGLISH_SENTENCE =
  'This rebuild has already erased the disk. Whatever was on it is gone, whether or not the installation finished.'

type Catalogue = { [key: string]: string | Catalogue }

/** A key looked up in a catalogue, or undefined when the catalogue lacks it. */
function lookup(catalogue: Catalogue, key: string): string | undefined {
  let node: string | Catalogue | undefined = catalogue

  for (const part of key.split('.')) {
    if (node === undefined || typeof node === 'string') return undefined
    node = node[part]
  }

  return typeof node === 'string' ? node : undefined
}

/** The Arabic sentence, read from the catalogue the page itself reads. */
function arabicSentence(): string {
  const sentence = lookup(ar, 'vps.rebuildDataDestroyed')

  expect(sentence, 'ar.json has no vps.rebuildDataDestroyed').toBeTypeOf('string')

  return sentence ?? ''
}

/**
 * A rebuild as the API publishes it. `in_flight` and `needs_attention` are
 * derived from the state the way `ReinstallState` derives them, so a fixture
 * cannot claim a combination the API never sends.
 */
function rebuild(state: string, destroyed: boolean) {
  const terminal = ['completed', 'failed', 'needs_review', 'indeterminate'].includes(state)

  return {
    id: '01JREINSTALL',
    state,
    in_flight: !terminal,
    needs_attention: state === 'needs_review' || state === 'indeterminate',
    data_destroyed: destroyed,
    requested_at: '2026-03-01T00:00:00+00:00',
    completed_at: state === 'completed' ? '2026-03-01T00:20:00+00:00' : null,
  }
}

function machine(reinstall: ReturnType<typeof rebuild> | null) {
  return {
    id: '01JVM',
    service_id: '01JSERVICE',
    hostname: 'web-kw-01',
    service_status: 'active',
    power_state: 'running',
    resources: { vcpu: 2, memory_mib: 4096, disk_gib: 40 },
    os_family: 'debian',
    os_version: '12',
    addresses: [{ address: '198.51.100.24', ip_version: 4, is_primary: true }],
    is_operable: true,
    actions: { power: true, reinstall: true, blocked_reason: null },
    reinstall,
    created_at: '2026-03-01T00:00:00+00:00',
  }
}

function server(reinstall: ReturnType<typeof rebuild> | null) {
  return {
    id: '01JDED',
    serial: 'SN-KW-0042',
    manufacturer: 'Fabrikam',
    model: 'FX-2200',
    status: 'active',
    power_state: 'on',
    is_powered_on: true,
    actions: { power: true, reinstall: true, blocked_reason: null },
    service_id: '01JSERVICE2',
    activated_at: '2026-02-01T00:00:00+00:00',
    reinstall,
  }
}

function stubApi(routes: Record<string, unknown>) {
  return vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = input instanceof Request ? input.url : String(input)
    const path = url.split('?')[0] ?? url

    let body: unknown = null

    if (!path.endsWith('/sanctum/csrf-cookie')) {
      const match = Object.keys(routes).find((suffix) => path.endsWith(suffix))

      if (match === undefined) throw new Error(`Unstubbed request: ${url}`)

      body = routes[match]
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

function renderAt(path: string, routes: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <MemoryRouter initialEntries={[path]}>
      <QueryClientProvider client={client}>
        <Routes>{routes}</Routes>
      </QueryClientProvider>
    </MemoryRouter>,
  )
}

/** The VPS machine page, with its overview mounted as the application mounts it. */
async function renderMachinePage(reinstall: ReturnType<typeof rebuild> | null) {
  vi.stubGlobal('fetch', stubApi({ '/vps/01JVM': { data: machine(reinstall) } }))

  renderAt(
    '/vps/01JVM',
    <Route path="/vps/:id" element={<VpsDetailPage />}>
      <Route index element={<VpsOverviewSection />} />
    </Route>,
  )

  await screen.findByRole('heading', { level: 1, name: 'web-kw-01' })
}

/**
 * The rebuild card on the machine page, where the sentence belongs. A titled
 * card is a named region, so it is found the way a screen reader finds it.
 */
function rebuildCard(title: string = en.vps.rebuild): HTMLElement {
  return screen.getByRole('region', { name: title })
}

const require = createRequire(import.meta.url)
const APP_STYLESHEET = path.resolve(import.meta.dirname, '../../../styles/index.css')

/**
 * The application's own stylesheet, compiled by Tailwind for every class the
 * rendered tree carries, and put into the document.
 *
 * jsdom ignores rules inside `@layer`, and every Tailwind utility is inside
 * one, so the layers are unwrapped before the rules go in. Nothing else is
 * rewritten: the declarations are Tailwind's.
 */
async function applyTailwindTo(root: HTMLElement): Promise<HTMLStyleElement> {
  const compiler = await compile(readFileSync(APP_STYLESHEET, 'utf8'), {
    base: path.dirname(APP_STYLESHEET),
    loadStylesheet: (id, base) => {
      const file = id.startsWith('.')
        ? path.resolve(base, id)
        : require.resolve(id === 'tailwindcss' ? 'tailwindcss/index.css' : id)

      return Promise.resolve({ path: file, base: path.dirname(file), content: readFileSync(file, 'utf8') })
    },
  })

  const candidates = new Set<string>()
  for (const element of [root, ...Array.from(root.querySelectorAll('[class]'))]) {
    for (const name of element.classList) candidates.add(name)
  }

  const probe = document.createElement('style')
  probe.textContent = compiler.build([...candidates])
  document.head.appendChild(probe)

  const unlayered = (rules: CSSRuleList): string[] =>
    Array.from(rules).flatMap((rule) => {
      if (!rule.cssText.startsWith('@layer')) return [rule.cssText]
      const nested = (rule as Partial<CSSGroupingRule>).cssRules
      return nested === undefined ? [] : unlayered(nested)
    })

  const flat = probe.sheet === null ? [] : unlayered(probe.sheet.cssRules)
  probe.remove()

  const style = document.createElement('style')
  style.textContent = flat.join('\n')
  document.head.appendChild(style)

  return style
}

/**
 * Whether a customer would see this element, as far as a stylesheet can hide it.
 *
 * `toBeVisible()` covers `display`, `visibility`, the `hidden` attribute and
 * every ancestor. It compares opacity against the string `'0'`, and Tailwind
 * writes `0%`, so opacity is read as a number here; and a visually-hidden
 * element (`sr-only`) is displayed, visible and opaque, so its one-pixel clip
 * is asked about by name.
 */
async function expectPresentedToTheEye(element: HTMLElement): Promise<void> {
  const style = await applyTailwindTo(document.body)

  try {
    expect(element).toBeVisible()

    for (let node: HTMLElement | null = element; node !== null; node = node.parentElement) {
      const computed = getComputedStyle(node)
      const opacity = Number.parseFloat(computed.opacity === '' ? '1' : computed.opacity)

      expect(opacity, `<${node.tagName.toLowerCase()} class="${node.className}"> is transparent`).toBeGreaterThan(0)
      expect(
        computed.position === 'absolute' && computed.width === '1px' && computed.height === '1px',
        `<${node.tagName.toLowerCase()} class="${node.className}"> is clipped to a visually-hidden pixel`,
      ).toBe(false)
    }
  } finally {
    style.remove()
  }
}

describe('a VPS whose rebuild erased the disk, on its own page', () => {
  afterEach(async () => {
    vi.unstubAllGlobals()
    cleanup()
    await i18n.changeLanguage('en')
  })

  it('says the disk is gone, next to the state that says the rebuild did not run', async () => {
    await renderMachinePage(rebuild('failed', true))

    const card = rebuildCard()

    // The finding's own case: settled as `failed`, no longer waiting for
    // anyone, and the disk already replaced.
    expect(within(card).getByText(en.vps.reinstallState.failed)).toBeInTheDocument()
    expect(within(card).getByText(ENGLISH_SENTENCE)).toBeInTheDocument()

    // The VPS sentence, not the Dedicated one borrowed: a chassis has disks,
    // a VPS has one.
    expect(card.textContent).not.toContain(en.dedicated.rebuildDataDestroyed)
  })

  it('says it in Arabic on an Arabic page — not the key, not the English', async () => {
    await i18n.changeLanguage('ar')
    await renderMachinePage(rebuild('failed', true))

    const card = rebuildCard(ar.vps.rebuild)

    expect(within(card).getByText(ar.vps.reinstallState.failed)).toBeInTheDocument()
    expect(within(card).getByText(arabicSentence())).toBeInTheDocument()

    // Measured, not reasoned: with the key wired and no catalogue carrying
    // it, i18next renders the key path itself, in red, styled as the
    // sentence — `fallbackLng` cannot help when English lacks it too.
    expect(card.textContent).not.toContain('rebuildDataDestroyed')
    expect(card.textContent).not.toContain(ENGLISH_SENTENCE)
  })

  it('says nothing about the disk when the rebuild failed before touching it', async () => {
    await renderMachinePage(rebuild('failed', false))

    // Here "The rebuild did not run" is the whole truth, and it is the only
    // thing said.
    expect(screen.getByText(en.vps.reinstallState.failed)).toBeInTheDocument()
    expect(screen.queryByText(ENGLISH_SENTENCE)).not.toBeInTheDocument()
  })

  it('presents the sentence as harm, in the danger colour', async () => {
    await renderMachinePage(rebuild('failed', true))

    expect(screen.getByText(ENGLISH_SENTENCE).className).toContain('--danger-text')
  })

  it('puts the sentence on the screen, not merely in the document', async () => {
    await renderMachinePage(rebuild('failed', true))

    await expectPresentedToTheEye(screen.getByText(ENGLISH_SENTENCE))
  })

  /*
   * The published fact, not the state name. `needs_review` and
   * `indeterminate` are written by the handler itself, with no operator in
   * the loop, after the destructive call; a guard re-pinned to `failed` would
   * say nothing about the disk on any of these rows.
   */
  it.each(['reinstalling', 'configuring', 'verifying', 'needs_review', 'indeterminate'])(
    'says the disk is gone at %s, where the state name alone does not say it',
    async (state) => {
      await renderMachinePage(rebuild(state, true))

      expect(screen.getByText(stateLabel(state))).toBeInTheDocument()
      expect(screen.getByText(ENGLISH_SENTENCE)).toBeInTheDocument()
    },
  )

  /*
   * A rebuild that finished replaced the disk too, and the page says so for
   * as long as it is the machine's latest rebuild — as the Dedicated page has
   * since Wave 4.
   *
   * Recorded, not argued: whether a permanent red sentence under a successful
   * rebuild is right for the detail page was never discussed. It is what the
   * fact says, and this row holds it so that a change is a decision rather
   * than an accident.
   */
  it('says it under a rebuild that completed, because that one replaced the disk too', async () => {
    await renderMachinePage(rebuild('completed', true))

    expect(screen.getByText(en.vps.reinstallState.completed)).toBeInTheDocument()
    expect(screen.getByText(ENGLISH_SENTENCE)).toBeInTheDocument()
  })

  /*
   * The negative rows at states that are neither `failed` nor destroyed. A
   * guard that fired on a state name here would tell a customer mid-rebuild,
   * in red, that a disk nothing has touched is already erased.
   */
  it.each(['requested', 'queued', 'preparing'])(
    'says nothing about the disk at %s, before anything was replaced',
    async (state) => {
      await renderMachinePage(rebuild(state, false))

      expect(screen.getByText(stateLabel(state))).toBeInTheDocument()
      expect(screen.queryByText(ENGLISH_SENTENCE)).not.toBeInTheDocument()
    },
  )

  it('shows no rebuild card at all for a machine that was never rebuilt', async () => {
    await renderMachinePage(null)

    expect(screen.queryByRole('region', { name: en.vps.rebuild })).not.toBeInTheDocument()
    expect(screen.queryByText(ENGLISH_SENTENCE)).not.toBeInTheDocument()
  })
})

describe('the catalogues', () => {
  it('carry the VPS sentence in both languages', () => {
    expect(lookup(en, 'vps.rebuildDataDestroyed')).toBe(ENGLISH_SENTENCE)
    expect(lookup(ar, 'vps.rebuildDataDestroyed')).toBeTypeOf('string')
  })

  it('write the Arabic one in Arabic, and not as the English pasted across', () => {
    const sentence = arabicSentence()

    expect(sentence).not.toBe(ENGLISH_SENTENCE)
    expect(sentence).toMatch(/\p{Script=Arabic}/u)
    expect(sentence).not.toMatch(/[A-Za-z]/)
  })
})

describe('the Dedicated twin, which already said it', () => {
  afterEach(async () => {
    vi.unstubAllGlobals()
    cleanup()
    await i18n.changeLanguage('en')
  })

  async function renderServerPage() {
    vi.stubGlobal('fetch', stubApi({ '/dedicated/01JDED': { data: server(rebuild('failed', true)) } }))

    renderAt(
      '/dedicated/01JDED',
      <Route path="/dedicated/:id" element={<DedicatedDetailPage />}>
        <Route index element={<DedicatedOverviewSection />} />
      </Route>,
    )

    await screen.findByRole('heading', { level: 1, name: 'SN-KW-0042' })
  }

  it('still says its own sentence, in English', async () => {
    await renderServerPage()

    expect(screen.getByText(en.dedicated.rebuildDataDestroyed)).toBeInTheDocument()
  })

  it('still says its own sentence, in Arabic', async () => {
    await i18n.changeLanguage('ar')
    await renderServerPage()

    expect(screen.getByText(ar.dedicated.rebuildDataDestroyed)).toBeInTheDocument()
  })
})

/*
 * The list, left as it was, deliberately.
 *
 * On /vps a rebuild that erased the disk and one that did not, both settled as
 * `failed`, render the same cell: "The rebuild did not run", in muted grey. That
 * is F-20's own sentence still reproducible on a VPS screen, and it is recorded
 * here rather than fixed, on the three grounds F-20's closure ruled on: the
 * list's sentence is incomplete rather than false; the Dedicated list carries
 * no rebuild information at all, so there is no twin to match; and the
 * machine's own page, which does say it, is one click away. A marker is not impossible — one conditioned on
 * `data_destroyed` alone would fire on every machine ever rebuilt, but one
 * conditioned on the fact and a state other than `completed` would not — it
 * was simply not what F-20 closed with.
 *
 * What this row holds is the third ground: the row is a way to the page that
 * answers.
 */
describe('the VPS list', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    cleanup()
  })

  it('leads from a rebuild that erased the disk to the page that says so', async () => {
    const destroyed = machine(rebuild('failed', true))

    vi.stubGlobal(
      'fetch',
      stubApi({
        '/vps': {
          data: [destroyed],
          meta: { page: 1, per_page: 25, total: 1, last_page: 1, max_per_page: 100 },
        },
      }),
    )

    renderAt('/vps', <Route path="/vps" element={<VpsPage />} />)

    const row = await screen.findByRole('row', { name: /web-kw-01/ })
    expect(within(row).getByText(en.vps.reinstallState.failed)).toBeInTheDocument()
    expect(within(row).getByRole('link', { name: 'web-kw-01' })).toHaveAttribute('href', '/vps/01JVM')
  })
})

/** The English label for a rebuild state, from the catalogue the page reads. */
function stateLabel(state: string): string {
  const label = lookup(en, `vps.reinstallState.${state}`)

  if (label === undefined) throw new Error(`en.json has no vps.reinstallState.${state}`)

  return label
}
